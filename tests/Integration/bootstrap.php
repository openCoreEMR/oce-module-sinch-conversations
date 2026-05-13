<?php

/**
 * Integration test bootstrap.
 *
 * Loads OpenEMR's real autoloader and globals so tests run against a live
 * MariaDB and exercise actual procedural library code (appointments.inc.php,
 * etc.). Must run inside the openemr container — the host has no DB
 * connectivity to the running install.
 *
 * Deliberately does NOT load tests/bootstrap.php's mocks: integration tests
 * need the real OpenEMR\... classes, not the test doubles the unit suite uses.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

// The module's own composer autoloader (PHPUnit, our test classes, etc.).
require_once __DIR__ . '/../../vendor/autoload.php';

$openemrRoot = '/var/www/localhost/htdocs/openemr';
if (!is_dir($openemrRoot)) {
    fwrite(STDERR, "Integration tests must run inside the openemr container.\n"
        . "Expected OpenEMR at {$openemrRoot} but the directory is missing.\n"
        . "Run via: task test:integration\n");
    exit(1);
}

// OpenEMR's session-bootstrapped globals. interface/globals.php starts a
// session and pulls in the kernel, autoloader, and $GLOBALS state the
// reminder pipeline reads (fileroot, OEGlobalsBag, etc.).
$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/';
$ignoreAuth = true;
require_once $openemrRoot . '/interface/globals.php';

// Pre-flight: the module must be enabled at least once so its tables exist.
// We don't auto-enable — that's a one-time manual step (or the CI job's
// `task module:install-enable`). Conflating "test setup" with "module
// install" hides real failures.
$tableCheck = sqlQuery(
    "SELECT COUNT(*) AS c FROM information_schema.tables "
    . "WHERE table_schema = DATABASE() AND table_name = 'oce_sinch_appointment_reminders'"
);
if ((int) ($tableCheck['c'] ?? 0) === 0) {
    fwrite(STDERR, "Module table oce_sinch_appointment_reminders is missing.\n"
        . "Enable the module first:\n"
        . "    task module:install-enable\n");
    exit(1);
}
