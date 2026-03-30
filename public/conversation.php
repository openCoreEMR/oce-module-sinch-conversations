<?php

/**
 * Conversation thread view
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
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\Response;

$logger = new SystemLogger();
$globalsAccessor = new GlobalsAccessor();

$kernel = $globalsAccessor->getKernel();
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);

$controller = $bootstrap->getConversationController();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'view');

try {
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
