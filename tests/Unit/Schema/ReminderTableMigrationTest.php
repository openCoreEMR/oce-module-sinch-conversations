<?php

/**
 * Unit tests for ReminderTableMigration.
 *
 * Covers each branch of the probe + lock + step machinery directly,
 * decoupled from ModuleManagerListener so the migration can be evolved
 * without churning lifecycle tests.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Schema;

use OpenCoreEMR\Modules\SinchConversations\Schema\ReminderTableMigration;
use OpenEMR\Common\Database\QueryUtils;
use PHPUnit\Framework\TestCase;

class ReminderTableMigrationTest extends TestCase
{
    private const TABLE_PROBE_SQL = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    private const COLUMN_PROBE_SQL = 'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?';
    private const INDEX_PROBE_SQL = 'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    private const DATABASE_PROBE_SQL = 'SELECT DATABASE() AS db';
    private const GET_LOCK_SQL = 'SELECT GET_LOCK(?, ?) AS got';
    private const TENANT_DB = 'test_tenant';
    private const LOCK_NAME = 'oce_sinch_reminder_migration:test_tenant';

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
    }

    public function testNoOpWhenTableDoesNotExist(): void
    {
        // Default mock is empty → TABLES probe returns []. Nothing else
        // should be queried; the migration short-circuits.
        ReminderTableMigration::ensureUpgraded();

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $queries[0]['sql']);
    }

    public function testNoOpWhenSchemaAlreadyAtTarget(): void
    {
        $this->mockShape(
            tableExists: true,
            columnIsNullable: false,
            indexes: ['unique_event_occurrence', 'idx_occurrence_date'],
        );

        ReminderTableMigration::ensureUpgraded();

        $queries = QueryUtils::getQueries();
        // Three probes (TABLES, COLUMNS, STATISTICS), no lock acquisition,
        // no ALTERs. Fast-path return without taking the lock.
        $this->assertCount(3, $queries);
        $this->assertCount(
            0,
            array_filter($queries, static fn(array $q): bool => str_starts_with(ltrim($q['sql']), 'ALTER TABLE'))
        );
        $this->assertCount(
            0,
            array_filter($queries, static fn(array $q): bool => str_contains($q['sql'], 'GET_LOCK'))
        );
    }

    public function testRunsAllStepsForLegacyTable(): void
    {
        $this->mockShape(
            tableExists: true,
            columnIsNullable: null,
            indexes: ['unique_event_reminder'],
        );

        ReminderTableMigration::ensureUpgraded();

        $sqls = array_column(QueryUtils::getQueries(), 'sql');
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'ADD COLUMN `occurrence_date`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'SET r.`occurrence_date` = e.pc_eventDate')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'DELETE FROM `oce_sinch_appointment_reminders` WHERE `occurrence_date` IS NULL')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'MODIFY `occurrence_date` DATE NOT NULL')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'DROP INDEX `unique_event_reminder`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'ADD UNIQUE KEY `unique_event_occurrence`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'ADD INDEX `idx_occurrence_date`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'RELEASE_LOCK')));
    }

    public function testSkipsAddColumnWhenColumnAlreadyExists(): void
    {
        // Partial migration: column was added (still nullable) and old
        // unique index is still there. ADD COLUMN must NOT run again.
        $this->mockShape(
            tableExists: true,
            columnIsNullable: true,
            indexes: ['unique_event_reminder'],
        );

        ReminderTableMigration::ensureUpgraded();

        $sqls = array_column(QueryUtils::getQueries(), 'sql');
        $this->assertCount(0, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'ADD COLUMN `occurrence_date`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'MODIFY `occurrence_date` DATE NOT NULL')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'DROP INDEX `unique_event_reminder`')));
        $this->assertCount(1, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'ADD UNIQUE KEY `unique_event_occurrence`')));
    }

    public function testReturnsCleanWhenLockContentionAndAnotherFinishedFirst(): void
    {
        $this->mockShape(
            tableExists: true,
            columnIsNullable: null,
            indexes: ['unique_event_reminder'],
            lockAcquired: false,
        );
        // Re-probe after lock fails: holder finished while we waited.
        QueryUtils::queueMockResult(
            self::COLUMN_PROBE_SQL,
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            [['IS_NULLABLE' => 'NO']],
        );
        QueryUtils::queueMockResult(
            self::INDEX_PROBE_SQL,
            ['oce_sinch_appointment_reminders'],
            [
                ['INDEX_NAME' => 'unique_event_occurrence'],
                ['INDEX_NAME' => 'idx_occurrence_date'],
            ],
        );

        ReminderTableMigration::ensureUpgraded();

        $sqls = array_column(QueryUtils::getQueries(), 'sql');
        $this->assertCount(0, array_filter($sqls, static fn(string $s): bool => str_starts_with(ltrim($s), 'ALTER TABLE')));
        $this->assertCount(0, array_filter($sqls, static fn(string $s): bool => str_contains($s, 'RELEASE_LOCK')));
    }

    public function testThrowsWhenLockContentionAndStillLegacy(): void
    {
        $this->mockShape(
            tableExists: true,
            columnIsNullable: null,
            indexes: ['unique_event_reminder'],
            lockAcquired: false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not acquire migration advisory lock');

        ReminderTableMigration::ensureUpgraded();
    }

    public function testThrowsWhenDatabaseProbeReturnsEmpty(): void
    {
        $this->mockShape(
            tableExists: true,
            columnIsNullable: null,
            indexes: ['unique_event_reminder'],
        );
        // Override DATABASE() probe → empty.
        QueryUtils::setMockResult(self::DATABASE_PROBE_SQL, [], [['db' => '']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SELECT DATABASE() returned no value');

        ReminderTableMigration::ensureUpgraded();
    }

    public function testThrowsWhenIsNullableNotAString(): void
    {
        QueryUtils::setMockResult(
            self::TABLE_PROBE_SQL,
            ['oce_sinch_appointment_reminders'],
            [['TABLE_NAME' => 'oce_sinch_appointment_reminders']],
        );
        QueryUtils::setMockResult(
            self::COLUMN_PROBE_SQL,
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            [['IS_NULLABLE' => 0]], // intentionally wrong type
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IS_NULLABLE was not a string');

        ReminderTableMigration::ensureUpgraded();
    }

    public function testReleaseLockSwallowsErrorsToPreserveOriginalException(): void
    {
        // Migration steps fail (DDL throws). RELEASE_LOCK then also throws
        // (driver glitch). The caller must see the *original* DDL error,
        // not the release-lock secondary error.
        $this->mockShape(
            tableExists: true,
            columnIsNullable: null,
            indexes: ['unique_event_reminder'],
        );
        // First sqlStatementThrowException after the probe is the ADD COLUMN.
        QueryUtils::setNextException(new \RuntimeException('ADD COLUMN failed: disk full'));

        try {
            ReminderTableMigration::ensureUpgraded();
            $this->fail('Expected RuntimeException');
        } catch (\Throwable $e) {
            $this->assertSame('ADD COLUMN failed: disk full', $e->getMessage());
        }
    }

    /**
     * @param bool|null $columnIsNullable null = column does not exist
     * @param list<string> $indexes
     */
    private function mockShape(
        bool $tableExists,
        ?bool $columnIsNullable,
        array $indexes,
        bool $lockAcquired = true,
    ): void {
        QueryUtils::setMockResult(
            self::TABLE_PROBE_SQL,
            ['oce_sinch_appointment_reminders'],
            $tableExists ? [['TABLE_NAME' => 'oce_sinch_appointment_reminders']] : [],
        );
        QueryUtils::setMockResult(
            self::COLUMN_PROBE_SQL,
            ['oce_sinch_appointment_reminders', 'occurrence_date'],
            $columnIsNullable === null ? [] : [['IS_NULLABLE' => $columnIsNullable ? 'YES' : 'NO']],
        );
        QueryUtils::setMockResult(
            self::INDEX_PROBE_SQL,
            ['oce_sinch_appointment_reminders'],
            array_map(static fn(string $name): array => ['INDEX_NAME' => $name], $indexes),
        );
        QueryUtils::setMockResult(
            self::DATABASE_PROBE_SQL,
            [],
            [['db' => self::TENANT_DB]],
        );
        QueryUtils::setMockResult(
            self::GET_LOCK_SQL,
            [self::LOCK_NAME, 30],
            [['got' => $lockAcquired ? 1 : 0]],
        );
    }
}
