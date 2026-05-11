<?php

/**
 * Unit tests for ModuleManagerListener
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenEMR\Common\Database\QueryUtils;
use PHPUnit\Framework\TestCase;

class ModuleManagerListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../ModuleManagerListener.php';
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
    }

    public function testGetModuleNamespace(): void
    {
        $this->assertSame(
            'OpenCoreEMR\\Modules\\SinchConversations\\',
            \ModuleManagerListener::getModuleNamespace()
        );
    }

    public function testGetModuleSourcePath(): void
    {
        $this->assertSame(
            '/src/Module',
            \ModuleManagerListener::getModuleSourcePath()
        );
    }

    public function testInitListenerSelfReturnsInstance(): void
    {
        $instance = \ModuleManagerListener::initListenerSelf();
        $this->assertInstanceOf(\ModuleManagerListener::class, $instance);
    }

    public function testInstallRegistersBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('install', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('INSERT INTO `background_services`', $queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]['sql']);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][0]);
    }

    public function testEnableRegistersAndActivatesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('enable', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        // upsert + 2 INFORMATION_SCHEMA probes (column missing AND table
        // missing — i.e. enable runs before install has created the table;
        // migration short-circuits as a no-op) + activate.
        // Fresh installs that did run table.sql will short-circuit after
        // the COLUMNS probe (covered by testEnableSkipsMigrationWhenOccurrenceDateColumnAlreadyExists).
        $this->assertCount(4, $queries);

        $this->assertStringContainsString('INSERT INTO `background_services`', $queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]['sql']);

        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $queries[1]['sql']);
        $this->assertSame(['oce_sinch_appointment_reminders', 'occurrence_date'], $queries[1]['binds']);

        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $queries[2]['sql']);
        $this->assertSame(['oce_sinch_appointment_reminders'], $queries[2]['binds']);

        $this->assertStringContainsString('UPDATE `background_services` SET `active` = ?', $queries[3]['sql']);
        $this->assertSame(1, $queries[3]['binds'][0]);
        $this->assertSame('oce_sinch_reminders', $queries[3]['binds'][1]);
    }

    public function testEnableSkipsMigrationWhenOccurrenceDateColumnAlreadyExists(): void
    {
        QueryUtils::setMockResult(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            [['COLUMN_NAME' => 'occurrence_date']]
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $listener->moduleManagerAction('enable', 1, 'Success');

        $queries = QueryUtils::getQueries();
        // upsert + column probe (returned a row → short-circuit) + activate
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $queries[1]['sql']);
        $this->assertStringContainsString('UPDATE `background_services`', $queries[2]['sql']);
    }

    public function testEnableRunsMigrationWhenLegacyTableExists(): void
    {
        // Column missing, but table exists — old shape, must migrate.
        QueryUtils::setMockResult(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            [['TABLE_NAME' => 'oce_sinch_appointment_reminders']]
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $listener->moduleManagerAction('enable', 1, 'Success');

        $queries = QueryUtils::getQueries();
        $alterColumnAdds = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'ADD COLUMN `occurrence_date`')
        );
        $this->assertCount(1, $alterColumnAdds);

        $backfills = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'UPDATE `oce_sinch_appointment_reminders` r')
                && str_contains($q['sql'], 'SET r.occurrence_date = e.pc_eventDate')
        );
        $this->assertCount(1, $backfills);

        $finalize = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'unique_event_occurrence')
                && str_contains($q['sql'], 'DROP INDEX `unique_event_reminder`')
        );
        $this->assertCount(1, $finalize);
    }

    public function testDisableDeactivatesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('disable', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('UPDATE `background_services` SET `active` = ?', $queries[0]['sql']);
        $this->assertSame(0, $queries[0]['binds'][0]);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][1]);
    }

    public function testUnregisterDeletesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('unregister', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('DELETE FROM `background_services`', $queries[0]['sql']);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][0]);
    }

    public function testDatabaseFailureReturnsErrorWithTraceableRef(): void
    {
        QueryUtils::setNextException(new \RuntimeException('connection lost'));

        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('install', 1, 'Success');

        $this->assertNotSame('Success', $result);
        $this->assertMatchesRegularExpression('/ref: [0-9a-f]{8}/', $result);
        $this->assertStringNotContainsString('connection lost', $result);
    }

    public function testUnknownActionReturnsCurrentStatus(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('nonexistent_action', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(0, $queries);
    }
}
