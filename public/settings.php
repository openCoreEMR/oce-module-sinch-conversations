<?php

/**
 * Settings and configuration
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

$logger = new SystemLogger();
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

$controller = $bootstrap->getSettingsController();

$action = $_GET['action'] ?? $_POST['action'] ?? 'show';

// Actions that expect JSON responses
$jsonActions = ['test', 'test-sms', 'sync-templates'];
$expectsJson = in_array($action, $jsonActions, true);

try {
    $response = $controller->dispatch($action);
    $response->send();
} catch (ExceptionInterface $e) {
    $logger->error(
        sprintf(
            "Sinch Conversations error [%s]: %s\nFile: %s:%d\nTrace: %s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        )
    );

    if ($expectsJson) {
        $response = new JsonResponse(
            [
                'success' => false,
                'message' => $e->getMessage(),
            ],
            $e->getStatusCode()
        );
    } else {
        $response = new Response(
            "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            $e->getStatusCode()
        );
    }
    $response->send();
} catch (\Throwable $e) {
    $logger->error(
        sprintf(
            "Unexpected error in Sinch Conversations [%s]: %s\nFile: %s:%d\nTrace: %s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        )
    );

    if ($expectsJson) {
        $response = new JsonResponse(
            [
                'success' => false,
                'message' => 'An unexpected error occurred',
            ],
            500
        );
    } else {
        $response = new Response(
            "Error: An unexpected error occurred",
            500
        );
    }
    $response->send();
}
