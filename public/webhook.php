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

declare(strict_types=1);

// Don't require session for webhooks - Sinch calls this endpoint directly
$ignoreAuth = true;

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\ModuleConfig\ConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\ModuleAccessGuard;
use OpenCoreEMR\Modules\SinchConversations\SinchModuleConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use Symfony\Component\HttpFoundation\Response;

// Check if module is installed and enabled - return 404 if not
$guardResponse = ModuleAccessGuard::check(Bootstrap::MODULE_NAME);
if ($guardResponse instanceof Response) {
    $guardResponse->send();
    return;
}

$logger = new SystemLogger();

try {
    $globalsBag = OEGlobalsBag::getInstance();
    $kernel = $globalsBag->get('kernel');
    if (!$kernel instanceof \OpenEMR\Core\Kernel) {
        throw new \RuntimeException('OpenEMR Kernel not available');
    }
    $configFactory = new ConfigFactory(SinchModuleConfig::createConfigDescriptor(), $globalsBag);
    $configAccessor = $configFactory->createConfigAccessor();
    $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $configAccessor, $configFactory);

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
