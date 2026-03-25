<?php

/**
 * Webhook endpoint for receiving Sinch Conversations API events
 *
 * This endpoint is called by Sinch to notify of message and delivery events.
 * It does NOT require OpenEMR authentication since it's called by Sinch servers.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Don't require session for webhooks - Sinch calls this endpoint directly
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\ConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;
use OpenCoreEMR\Modules\SinchConversations\ModuleAccessGuard;
use OpenCoreEMR\Sinch\Conversation\Exception\ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\Response;

// Check if module is installed and enabled - return 404 if not
$guardResponse = ModuleAccessGuard::check(Bootstrap::MODULE_NAME);
if ($guardResponse instanceof Response) {
    $guardResponse->send();
    return;
}

$logger = new SystemLogger();

try {
    $globalsAccessor = new GlobalsAccessor();
    $kernel = $globalsAccessor->getKernel();
    $configAccessor = ConfigFactory::createConfigAccessor();
    $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $configAccessor);

    $controller = $bootstrap->getWebhookController();
    $response = $controller->dispatch();
    $response->send();
} catch (ExceptionInterface $e) {
    $logger->error("Webhook error: " . $e->getMessage());

    $response = new Response(
        (string) json_encode(['error' => $e->getMessage()]),
        $e->getStatusCode(),
        ['Content-Type' => 'application/json']
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected webhook error: " . $e->getMessage());

    $response = new Response(
        (string) json_encode(['error' => 'Internal server error']),
        Response::HTTP_INTERNAL_SERVER_ERROR,
        ['Content-Type' => 'application/json']
    );
    $response->send();
}
