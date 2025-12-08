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
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
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
        private readonly ConversationApiClient $apiClient,
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
            'success_message' => $_SESSION['settings_message'] ?? null,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        unset($_SESSION['settings_message']);

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

        $_SESSION['settings_message'] = "Settings saved successfully";

        return $this->redirect($request);
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
            $result = ['success' => true, 'message' => 'API connection test not yet implemented'];
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error("API test failed: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
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
