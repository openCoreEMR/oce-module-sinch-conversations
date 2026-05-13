<?php

/**
 * Settings Controller - Module configuration
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\ErrorId;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\SessionAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Config\Region;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Csrf\CsrfUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class SettingsController
{
    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConfigService $configService,
        private readonly ConversationApiClient $apiClient,
        private readonly TemplateSyncService $templateSyncService,
        private readonly SessionAccessor $session,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Dispatch action to appropriate method
     *
     * @param string $action
     * @return Response
     */
    public function dispatch(string $action): Response
    {
        $request = Request::createFromGlobals();

        return match ($action) {
            'save' => $this->handleSave($request),
            'test' => $this->handleTest($request),
            'test-sms' => $this->handleTestSms($request),
            'sync-templates' => $this->handleSyncTemplates($request),
            'show', 'default' => $this->showSettings(),
            default => $this->showSettings(),
        };
    }

    /**
     * Show settings page
     *
     * @return Response
     */
    private function showSettings(): Response
    {
        $settings = [
            'project_id' => $this->config->getSinchProjectId(),
            'app_id' => $this->config->getSinchAppId(),
            'api_key' => $this->config->getSinchApiKey(),
            'region' => $this->config->getSinchRegion()->value,
            'default_channel' => $this->config->getDefaultChannel(),
            'clinic_name' => $this->config->getClinicName(),
            'clinic_phone' => $this->config->getClinicPhone(),
        ];

        $content = $this->twig->render('settings/config.html.twig', [
            'settings' => $settings,
            'is_external_config' => $this->config->isExternalConfigMode(),
            'success_message' => $this->session->getFlash('settings_message'),
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        $response = new Response($content);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    /**
     * Handle save settings
     *
     * @param Request $request
     * @return Response
     */
    private function handleSave(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        try {
            // Skip saving when all config is managed externally
            if ($this->config->isExternalConfigMode()) {
                $this->session->setFlash(
                    'settings_message',
                    "Configuration is managed externally and cannot be changed here."
                );
                return $this->redirect($request);
            }

            $settings = [
                'default_channel' => (string)$request->request->get('default_channel', 'SMS'),
                'clinic_name' => (string)$request->request->get('clinic_name', ''),
                'clinic_phone' => (string)$request->request->get('clinic_phone', ''),
                'project_id' => (string)$request->request->get('project_id', ''),
                'app_id' => (string)$request->request->get('app_id', ''),
                'api_key' => (string)$request->request->get('api_key', ''),
                'region' => (string)$request->request->get('region', 'us'),
            ];

            $apiSecret = $request->request->get('api_secret', '');
            if ($apiSecret !== null && $apiSecret !== '') {
                $settings['api_secret'] = (string)$apiSecret;
            }

            // Verify the credentials with Sinch before persisting them, so a
            // misconfiguration is reported here rather than at message-send
            // time. The settings form re-posts the existing API field values
            // even when the user is only editing clinic info, so detect a
            // real API edit by comparing the posted tuple to what's stored
            // (and treat any typed api_secret as an edit, since the UI
            // leaves that field blank when keeping the existing value).
            $secretForValidation = $settings['api_secret'] ?? $this->config->getSinchApiSecret();
            $apiFieldChanged = isset($settings['api_secret'])
                || $settings['project_id'] !== $this->config->getSinchProjectId()
                || $settings['app_id'] !== $this->config->getSinchAppId()
                || $settings['api_key'] !== $this->config->getSinchApiKey()
                || $settings['region'] !== $this->config->getSinchRegion()->value;
            $hasApiTuple = $settings['project_id'] !== ''
                && $settings['app_id'] !== ''
                && $settings['api_key'] !== '';

            // Reject saves that would persist API fields with no usable
            // secret (no value typed in the form and none on file). Without
            // this gate, validation would be skipped and the partial config
            // saved silently, defeating the point of validation.
            if ($hasApiTuple && $secretForValidation === '') {
                $this->session->setFlash(
                    'settings_message',
                    "API Secret is required to configure Sinch credentials. Settings were not saved."
                );
                return $this->redirect($request);
            }

            // $hasApiTuple → $secretForValidation !== '' here (the guard
            // above returned when an API tuple lacked a secret). Region
            // hasn't been validated yet — that happens in
            // ConfigService::saveSettings() further down — so check
            // tryFrom and skip the API call if the form posted an
            // unrecognised value (saveSettings will then reject it with a
            // user-facing error).
            $region = Region::tryFrom($settings['region']);
            try {
                if ($apiFieldChanged && $hasApiTuple && $region !== null) {
                    $this->apiClient->validateCredentials(
                        $settings['project_id'],
                        $settings['app_id'],
                        $settings['api_key'],
                        $secretForValidation,
                        $region,
                    );
                }
            } catch (ValidationException | ApiException $e) {
                $errorId = ErrorId::generate();
                $this->logger->warning('Settings credential validation failed', [
                    'errorId' => $errorId,
                    'exception' => ExceptionContext::fromThrowable($e),
                ]);
                $this->session->setFlash(
                    'settings_message',
                    "Credentials could not be verified with Sinch (ref: $errorId). " .
                        "Settings were not saved. Check logs for details."
                );
                return $this->redirect($request);
            }

            // Save settings
            $this->configService->saveSettings($settings);

            $this->session->setFlash('settings_message', "Settings saved successfully");

            return $this->redirect($request);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Error saving settings', [
                'errorId' => $errorId,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            $this->session->setFlash('settings_message', "Error saving settings (ref: $errorId). Please try again.");
            return $this->redirect($request);
        }
    }

    /**
     * Test API connection
     *
     * @param Request $request
     * @return Response
     */
    private function handleTest(Request $request): Response
    {
        if (!CsrfUtils::verifyCsrfToken($request->query->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        try {
            // Validate configuration is complete
            if (empty($this->config->getSinchProjectId())) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Project ID is not configured',
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($this->config->getSinchAppId())) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'App ID is not configured',
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($this->config->getSinchApiKey())) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'API Key is not configured',
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($this->config->getSinchApiSecret())) {
                $this->logger->warning('API Secret is not configured');
                return new JsonResponse([
                    'success' => false,
                    'message' => 'API Secret is not configured. ' .
                        'OAuth2 authentication requires both API Key and API Secret.',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Test the connection
            $this->logger->info('Testing Sinch API connection');
            $this->apiClient->testConnection();
            $this->logger->info('Sinch API connection test successful');

            // Get app configuration details
            $appConfig = $this->apiClient->getApp();

            $result = [
                'success' => true,
                'message' => 'API connection successful! Your Sinch configuration is working correctly.',
                'app_config' => [
                    'display_name' => $appConfig['display_name'] ?? 'N/A',
                    'conversation_metadata_report_view' => $appConfig['conversation_metadata_report_view'] ?? 'NONE',
                    'channel_credentials' => $appConfig['channel_credentials'] ?? [],
                    'dispatch_retention_policy' => $appConfig['dispatch_retention_policy'] ?? null,
                ],
            ];
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('API connection test failed', [
                'errorId' => $errorId,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => "Connection failed (ref: $errorId). Check logs for details.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send test SMS
     *
     * @param Request $request
     * @return Response
     */
    private function handleTestSms(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid request method',
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        $phoneNumber = (string)$request->request->get('phone_number', '');
        $message = (string)$request->request->get('message', '');

        // Validate inputs
        if (empty($phoneNumber)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Phone number is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($message)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Message is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate configuration
        if (
            empty($this->config->getSinchProjectId()) ||
            empty($this->config->getSinchAppId()) ||
            empty($this->config->getSinchApiKey())
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Sinch API is not fully configured. Please save your settings first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Get configured clinic phone to use as sender
            $senderPhone = $this->config->getClinicPhone();

            // Send message using channel identity (works with DISPATCH mode)
            // In DISPATCH mode, Sinch automatically creates/finds contacts
            $options = [];
            if (!empty($senderPhone)) {
                $options['sender'] = $senderPhone;
                $this->logger->info('Using configured sender', ['sender' => $senderPhone]);
            } else {
                $this->logger->warning('No clinic phone configured, using default sender from Sinch app');
            }

            $this->apiClient->sendMessageByChannelIdentity(
                $phoneNumber,
                $message,
                'SMS',
                $options
            );

            $this->logger->info('Test SMS sent successfully', ['phone' => $phoneNumber]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Test SMS sent successfully to ' . $phoneNumber .
                    (!empty($senderPhone) ? " from {$senderPhone}" : ''),
            ]);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Failed to send test SMS', [
                'phone' => $phoneNumber,
                'errorId' => $errorId,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => "Failed to send SMS (ref: $errorId). Check logs for details.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sync templates to Sinch
     *
     * @param Request $request
     * @return Response
     */
    private function handleSyncTemplates(Request $request): Response
    {
        if (!CsrfUtils::verifyCsrfToken($request->query->get('csrf_token', ''))) {
            throw new AccessDeniedException("CSRF token verification failed");
        }

        // Validate configuration is complete
        if (
            empty($this->config->getSinchProjectId()) ||
            empty($this->config->getSinchAppId()) ||
            empty($this->config->getSinchApiKey()) ||
            empty($this->config->getSinchApiSecret())
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Sinch API credentials are incomplete. ' .
                    'Please configure Project ID, App ID, API Key, and API Secret.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->logger->info('Starting template sync');
            $results = $this->templateSyncService->syncAllTemplates();

            $message = sprintf(
                'Template sync completed: %d created, %d updated, %d skipped, %d failed out of %d total',
                $results['created'],
                $results['updated'],
                $results['skipped'] ?? 0,
                $results['failed'],
                $results['total']
            );

            if ($results['failed'] > 0) {
                $this->logger->warning($message, $results);
                return new JsonResponse([
                    'success' => false,
                    'message' => $message,
                    'details' => $results,
                ], Response::HTTP_PARTIAL_CONTENT);
            }

            $this->logger->info($message);
            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'details' => $results,
            ]);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Template sync failed', [
                'errorId' => $errorId,
                'exception' => ExceptionContext::fromThrowable($e),
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => "Template sync failed (ref: $errorId). Check logs for details.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Redirect back to settings
     *
     * @param Request $request
     * @return RedirectResponse
     */
    private function redirect(Request $request): RedirectResponse
    {
        $queryParams = $request->query->all();
        unset($queryParams['action']);

        $queryString = http_build_query($queryParams);
        $scriptName = $request->server->get(
            'SCRIPT_NAME',
            '/interface/modules/custom_modules/oce-module-sinch-conversations/public/settings.php'
        );
        $uri = $queryString ? $scriptName . '?' . $queryString : $scriptName;

        return new RedirectResponse($uri);
    }
}
