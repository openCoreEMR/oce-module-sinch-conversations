<?php

/**
 * SMS-eligibility verdict endpoint for the calendar appointment form
 *
 * Returns the rendered alert markup for a single patient (Content-Type:
 * text/html). The calendar JS layer fetches this on page load and after
 * every patient swap so the badge below the patient field stays in sync
 * with what the user sees.
 *
 * Returns 404 when the module is not installed/enabled (so the endpoint's
 * existence is not leaked to unauthenticated callers) and 403 when the
 * caller lacks the same ACL the appointment form itself requires.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\ModuleConfig\ConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\ModuleAccessGuard;
use OpenCoreEMR\Modules\SinchConversations\SinchModuleConfig;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use Symfony\Component\HttpFoundation\Response;

// Check if module is installed and enabled - return 404 if not
$guardResponse = ModuleAccessGuard::check(Bootstrap::MODULE_NAME);
if ($guardResponse instanceof Response) {
    $guardResponse->send();
    return;
}

// Same ACL the appointment form itself requires
// (interface/main/calendar/add_edit_event.php:63)
if (!AclMain::aclCheckCore('patients', 'appt', '', ['write', 'wsome'])) {
    (new Response('', Response::HTTP_FORBIDDEN))->send();
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

    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'html';
    $controller = $bootstrap->getEligibilityController();
    $response = $controller->dispatch($action);
    $response->send();
} catch (\Throwable $e) {
    $logger->error('Eligibility endpoint unexpected error', ['exception' => $e]);

    $response = new Response(
        '',
        Response::HTTP_INTERNAL_SERVER_ERROR,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
    $response->send();
}
