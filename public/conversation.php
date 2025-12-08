<?php

/**
 * Conversation thread view
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
use Symfony\Component\HttpFoundation\Response;

$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

$controller = $bootstrap->getConversationController();

$action = $_GET['action'] ?? $_POST['action'] ?? 'view';

try {
    $response = $controller->dispatch($action);
    $response->send();
} catch (ExceptionInterface $e) {
    error_log("Sinch Conversations error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    error_log("Unexpected error in Sinch Conversations: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
