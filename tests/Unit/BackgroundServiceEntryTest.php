<?php

/**
 * Unit tests for background_service_entry.php
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\ModulesClassLoader;
use PHPUnit\Framework\TestCase;

class BackgroundServiceEntryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../background_service_entry.php';
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();
        ModulesClassLoader::clearRegistered();

        $GLOBALS['fileroot'] = '/var/www/localhost/htdocs/openemr';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['fileroot']);
        unset($GLOBALS[GlobalConfig::CONFIG_OPTION_ENABLED]);
    }

    public function testFunctionExists(): void
    {
        $this->assertTrue(
            function_exists('oce_sinch_run_appointment_reminders'),
            'Expected function oce_sinch_run_appointment_reminders to be defined'
        );
    }

    public function testReturnsEarlyWhenFilerootMissing(): void
    {
        unset($GLOBALS['fileroot']);

        oce_sinch_run_appointment_reminders();

        $registered = ModulesClassLoader::getRegistered();
        $this->assertCount(0, $registered, 'Should not register namespaces without fileroot');
    }

    public function testRegistersNamespaces(): void
    {
        oce_sinch_run_appointment_reminders();

        $registered = ModulesClassLoader::getRegistered();
        $namespaces = array_column($registered, 'namespace');

        $this->assertContains('OpenCoreEMR\\Sinch\\Conversation\\', $namespaces);
        $this->assertContains('OpenCoreEMR\\Modules\\SinchConversations\\', $namespaces);
    }

    public function testReturnsEarlyWhenModuleDisabled(): void
    {
        oce_sinch_run_appointment_reminders();

        $logs = SystemLogger::getLogs();
        $debugMessages = array_column(
            array_filter($logs, fn ($l) => $l['level'] === 'debug'),
            'message'
        );

        // When disabled, Bootstrap is never constructed, so this log shouldn't appear
        $this->assertNotContains(
            'Sinch Conversations Bootstrap constructed',
            $debugMessages,
            'Bootstrap should not be constructed when module is disabled'
        );
    }

    public function testDelegatesToAppointmentReminderServiceWhenEnabled(): void
    {
        $GLOBALS[GlobalConfig::CONFIG_OPTION_ENABLED] = '1';

        // With SMS_NOTIFICATION_HOUR defaulting to 0, run() returns early.
        // We verify it was called by checking the debug log it emits.
        oce_sinch_run_appointment_reminders();

        $logs = SystemLogger::getLogs();
        $debugMessages = array_column(
            array_filter($logs, fn ($l) => $l['level'] === 'debug'),
            'message'
        );

        $this->assertContains(
            'SMS notification hours is 0 or negative, skipping appointment reminders',
            $debugMessages,
            'Expected AppointmentReminderService::run() to have been called'
        );
    }
}
