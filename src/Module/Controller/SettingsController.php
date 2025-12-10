<?php

/**
 * Settings Controller - Module configuration
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\SessionAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class SettingsController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConfigService $configService,
        private readonly ConversationApiClient $apiClient,
        private readonly TemplateSyncService $templateSyncService,
        private readonly SessionAccessor $session,
        private readonly Environment $twig
    ) {
        $this->logger = new SystemLogger();
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
            'region' => $this->config->getSinchRegion(),
            'default_channel' => $this->config->getDefaultChannel(),
            'clinic_name' => $this->config->getClinicName(),
            'clinic_phone' => $this->config->getClinicPhone(),
        ];

        $content = $this->twig->render('settings/config.html.twig', [
            'settings' => $settings,
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
            // Collect settings from form
            $settings = [
                'project_id' => (string)$request->request->get('project_id', ''),
                'app_id' => (string)$request->request->get('app_id', ''),
                'api_key' => (string)$request->request->get('api_key', ''),
                'region' => (string)$request->request->get('region', 'us'),
                'default_channel' => (string)$request->request->get('default_channel', 'SMS'),
                'clinic_name' => (string)$request->request->get('clinic_name', ''),
                'clinic_phone' => (string)$request->request->get('clinic_phone', ''),
            ];

            // Only include API secret if it was provided
            $apiSecret = $request->request->get('api_secret', '');
            if (!empty($apiSecret)) {
                $settings['api_secret'] = (string)$apiSecret;
            }

            // Save settings
            $this->configService->saveSettings($settings);

            $this->session->setFlash('settings_message', "Settings saved successfully");

            return $this->redirect($request);
        } catch (ValidationException $e) {
            $this->logger->error("Validation error saving settings: " . $e->getMessage());
            $this->session->setFlash('settings_message', "Error: " . $e->getMessage());
            return $this->redirect($request);
        } catch (\Throwable $e) {
            $this->logger->error("Error saving settings: " . $e->getMessage());
            $this->session->setFlash('settings_message', "Error saving settings. Please try again.");
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

            // Test the connection
            $this->logger->info("Testing Sinch API connection");
            $this->apiClient->testConnection();
            $this->logger->info("Sinch API connection test successful");

            $result = [
                'success' => true,
                'message' => 'API connection successful! Your Sinch configuration is working correctly.',
            ];
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error("API test failed: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
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
            // Create a temporary contact and send message
            $contactResponse = $this->apiClient->createContact($phoneNumber, 'SMS');
            $contactId = $contactResponse['id'] ?? '';

            if (empty($contactId)) {
                throw new ValidationException('Failed to create contact');
            }

            // Send the test message
            $this->apiClient->sendMessage($contactId, $message, [
                'channel' => 'SMS',
            ]);

            $this->logger->info("Test SMS sent successfully to {$phoneNumber}");

            return new JsonResponse([
                'success' => true,
                'message' => 'Test SMS sent successfully to ' . $phoneNumber,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send test SMS: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to send SMS: ' . $e->getMessage(),
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
            $this->logger->info("Starting template sync");
            $results = $this->templateSyncService->syncAllTemplates();

            $message = sprintf(
                'Template sync completed: %d created, %d updated, %d failed out of %d total',
                $results['created'],
                $results['updated'],
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
            $this->logger->error("Template sync failed: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => 'Template sync failed: ' . $e->getMessage(),
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
