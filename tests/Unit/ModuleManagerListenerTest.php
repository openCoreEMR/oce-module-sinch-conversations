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
        // upsert + TABLES probe (returns empty — install hasn't created
        // the table yet, so the migration short-circuits as a no-op) +
        // activate. Fresh installs that ran table.sql first land in
        // testEnableShortCircuitsWhenSchemaAlreadyAtTarget.
        $this->assertCount(3, $queries);

        $this->assertStringContainsString('INSERT INTO `background_services`', $queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]['sql']);

        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $queries[1]['sql']);
        $this->assertSame(['oce_sinch_appointment_reminders'], $queries[1]['binds']);

        $this->assertStringContainsString('UPDATE `background_services` SET `active` = ?', $queries[2]['sql']);
        $this->assertSame(1, $queries[2]['binds'][0]);
        $this->assertSame('oce_sinch_reminders', $queries[2]['binds'][1]);
    }

    public function testEnableShortCircuitsWhenSchemaAlreadyAtTarget(): void
    {
        $this->mockMigrationShape(
            tableExists: true,
            columnIsNullable: false,
            indexes: ['unique_event_occurrence', 'idx_occurrence_date', 'idx_patient_id', 'idx_sent_at']
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $listener->moduleManagerAction('enable', 1, 'Success');

        $queries = QueryUtils::getQueries();
        // upsert + 3 probes (TABLES, COLUMNS, STATISTICS) + activate. No ALTERs.
        $this->assertCount(5, $queries);

        $alterCount = count(array_filter(
            $queries,
            static fn(array $q): bool => str_starts_with(ltrim($q['sql']), 'ALTER TABLE')
        ));
        $this->assertSame(0, $alterCount, 'Already-migrated schema must not run ALTERs');
    }

    public function testEnableRunsMigrationWhenLegacyTableExists(): void
    {
        // Old shape: column missing, old unique index present, no new index.
        $this->mockMigrationShape(
            tableExists: true,
            columnIsNullable: null, // column does not exist
            indexes: ['unique_event_reminder', 'idx_patient_id', 'idx_sent_at']
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $listener->moduleManagerAction('enable', 1, 'Success');

        $queries = QueryUtils::getQueries();

        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'ADD COLUMN `occurrence_date`'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'UPDATE `oce_sinch_appointment_reminders` r')
                && str_contains($q['sql'], 'SET r.`occurrence_date` = e.pc_eventDate'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'MODIFY `occurrence_date` DATE NOT NULL'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'DROP INDEX `unique_event_reminder`'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'ADD UNIQUE KEY `unique_event_occurrence`'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'ADD INDEX `idx_occurrence_date`'))
        );
    }

    public function testEnableConvergesWhenAnotherProcessFinishedMigrationFirst(): void
    {
        // Simulate: lock contention — we couldn't acquire the lock — but
        // by the time we re-probe the holder has already finished. The
        // migration should return cleanly (not throw) since the schema
        // is now at the target shape.
        QueryUtils::setMockResult(
            'SELECT DATABASE() AS db',
            [],
            [['db' => 'test_tenant_db']]
        );
        QueryUtils::setMockResult(
            'SELECT GET_LOCK(?, ?) AS got',
            ['oce_sinch_reminder_migration:test_tenant_db', 30],
            [['got' => 0]]
        );
        // First probe: legacy shape (forces the lock acquisition attempt).
        // Second probe (after lock fails): fully migrated.
        QueryUtils::setMockResult(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            [['TABLE_NAME' => 'oce_sinch_appointment_reminders']]
        );
        QueryUtils::queueMockResult(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            [] // first probe: column missing
        );
        QueryUtils::queueMockResult(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            [['IS_NULLABLE' => 'NO']] // second probe: column NOT NULL
        );
        QueryUtils::queueMockResult(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            [['INDEX_NAME' => 'unique_event_reminder']] // first probe: legacy
        );
        QueryUtils::queueMockResult(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            [
                ['INDEX_NAME' => 'unique_event_occurrence'],
                ['INDEX_NAME' => 'idx_occurrence_date'],
            ] // second probe: fully migrated
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('enable', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(
            0,
            array_filter($queries, static fn(array $q): bool => str_starts_with(ltrim($q['sql']), 'ALTER TABLE')),
            'No ALTERs should run when another process finished the migration'
        );
        $this->assertCount(
            0,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'RELEASE_LOCK')),
            'RELEASE_LOCK only runs when GET_LOCK succeeded'
        );
    }

    public function testEnableFinishesMigrationWhenPartiallyApplied(): void
    {
        // Partial-migration scenario: column was added (still nullable) and
        // the old unique key is still present, but no new indexes exist
        // (e.g., a previous enable was interrupted between ALTERs).
        $this->mockMigrationShape(
            tableExists: true,
            columnIsNullable: true,
            indexes: ['unique_event_reminder', 'idx_patient_id', 'idx_sent_at']
        );

        $listener = \ModuleManagerListener::initListenerSelf();
        $listener->moduleManagerAction('enable', 1, 'Success');

        $queries = QueryUtils::getQueries();

        // ADD COLUMN must NOT run a second time — column already exists.
        $this->assertCount(
            0,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'ADD COLUMN `occurrence_date`'))
        );

        // The remaining steps must finish the migration.
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'MODIFY `occurrence_date` DATE NOT NULL'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'DROP INDEX `unique_event_reminder`'))
        );
        $this->assertCount(
            1,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'ADD UNIQUE KEY `unique_event_occurrence`'))
        );
    }

    /**
     * @param bool|null $columnIsNullable null = column does not exist
     * @param list<string> $indexes
     */
    private function mockMigrationShape(
        bool $tableExists,
        ?bool $columnIsNullable,
        array $indexes,
        bool $lockAcquired = true
    ): void {
        // The lock name is per-tenant: '<prefix>:<DATABASE()>'.
        // Mock the DATABASE() probe and key the GET_LOCK mock to the
        // resulting concatenated name.
        QueryUtils::setMockResult(
            'SELECT DATABASE() AS db',
            [],
            [['db' => 'test_tenant_db']]
        );
        QueryUtils::setMockResult(
            'SELECT GET_LOCK(?, ?) AS got',
            ['oce_sinch_reminder_migration:test_tenant_db', 30],
            [['got' => $lockAcquired ? 1 : 0]]
        );

        QueryUtils::setMockResult(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            $tableExists ? [['TABLE_NAME' => 'oce_sinch_appointment_reminders']] : []
        );

        QueryUtils::setMockResult(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            $columnIsNullable === null ? [] : [['IS_NULLABLE' => $columnIsNullable ? 'YES' : 'NO']]
        );

        QueryUtils::setMockResult(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            array_map(static fn(string $name): array => ['INDEX_NAME' => $name], $indexes)
        );
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
