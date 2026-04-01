<?php

/**
 * Main entry point for Sinch Conversations inbox
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Sinch\Conversation\Exception\ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use Symfony\Component\HttpFoundation\Response;

$logger = new SystemLogger();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

try {
    $kernel = OEGlobalsBag::getInstance()->get('kernel');
    if (!$kernel instanceof Kernel) {
        throw new \RuntimeException('OpenEMR Kernel not available');
    }
    $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);
    $controller = $bootstrap->getInboxController();
    $response = $controller->dispatch($action);
    $response->send();
} catch (ExceptionInterface $e) {
    $logger->error("Sinch Conversations error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected error in Sinch Conversations: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
