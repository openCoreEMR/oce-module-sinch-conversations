<?php

/**
 * In-place schema migration for `oce_sinch_appointment_reminders`.
 *
 * Earlier versions used UNIQUE KEY (pc_eid). That blocks all but the first
 * occurrence of a recurring appointment from ever sending an SMS reminder.
 * The new key is (pc_eid, occurrence_date). Module installers run table.sql
 * on install only, so existing tenants need an in-place upgrade.
 *
 * The migration is invoked from two places:
 *
 * 1. `ModuleManagerListener::enable()` — covers fresh installs and explicit
 *    re-enables after an upgrade.
 * 2. `AppointmentReminderService::run()` — covers the case where a tenant
 *    upgrades the module code without disabling/re-enabling the module.
 *    Without this safety net, `loadSentOccurrences()` would hard-fail
 *    against the legacy column-less table.
 *
 * The end-state probe verifies the column exists, is NOT NULL, the new
 * unique key is present, and the old unique key is gone. Each remediation
 * step then runs only if its specific precondition is unmet, so a partially
 * applied migration (e.g., enable was interrupted between ALTERs) finishes
 * cleanly on the next call instead of short-circuiting on "column exists"
 * and leaving the table in a half-migrated state.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Schema;

use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

final class ReminderTableMigration
{
    private const TABLE = 'oce_sinch_appointment_reminders';
    private const COLUMN = 'occurrence_date';
    private const OLD_UNIQUE_INDEX = 'unique_event_reminder';
    private const NEW_UNIQUE_INDEX = 'unique_event_occurrence';
    private const DATE_INDEX = 'idx_occurrence_date';
    private const LOCK_NAME_PREFIX = 'oce_sinch_reminder_migration';
    private const LOCK_TIMEOUT_SECONDS = 30;

    /**
     * Bring the dedup table to the target shape.
     *
     * Idempotent and partial-migration safe. No-op once the table is fully
     * migrated, or when the table doesn't exist yet (install hasn't run).
     *
     * Concurrency: this method runs from both `ModuleManagerListener::enable()`
     * and the reminder cron, which can race (admin clicks Enable while a cron
     * tick is mid-run). A MySQL advisory lock (`GET_LOCK`) serialises the
     * migration so two callers don't both attempt `ADD COLUMN` / `DROP INDEX`
     * and one fail with a duplicate-DDL error.
     *
     * `GET_LOCK` is server-wide, not database-scoped. On a shared MySQL
     * instance hosting multiple tenant databases, a bare lock name would
     * collide across tenants. The lock name therefore includes the current
     * `DATABASE()` so each tenant's migration serialises against itself
     * only.
     *
     * The fast-path probe at the top avoids taking the lock entirely once
     * the table is fully migrated.
     *
     * @throws \Throwable on database failure
     */
    public static function ensureUpgraded(): void
    {
        $shape = self::probeShape();
        if ($shape === null) {
            // Table absent — install hasn't run; table.sql will create
            // the new shape directly.
            return;
        }
        if ($shape['fully_migrated']) {
            return;
        }

        $lockName = self::buildLockName();

        if (!self::acquireLock($lockName)) {
            // Another process is already migrating. Wait was up to
            // LOCK_TIMEOUT_SECONDS; if they finished in that window the
            // table is now migrated and we can return cleanly. Otherwise
            // surface the timeout — the caller (cron / enable) will log
            // and we'll retry next tick.
            $shape = self::probeShape();
            if ($shape !== null && $shape['fully_migrated']) {
                return;
            }
            throw new \RuntimeException(sprintf(
                'Could not acquire migration advisory lock "%s" within %ds',
                $lockName,
                self::LOCK_TIMEOUT_SECONDS
            ));
        }

        try {
            // Re-probe under the lock — the previous holder may have
            // completed the migration while we waited.
            $shape = self::probeShape();
            if ($shape === null || $shape['fully_migrated']) {
                return;
            }

            self::runMigrationSteps($shape);
        } finally {
            self::releaseLock($lockName);
        }
    }

    /**
     * @param array{
     *     column_exists: bool,
     *     column_nullable: bool,
     *     old_unique_index_exists: bool,
     *     new_unique_index_exists: bool,
     *     date_index_exists: bool,
     *     fully_migrated: bool
     * } $shape
     */
    private static function runMigrationSteps(array $shape): void
    {
        if (!$shape['column_exists']) {
            QueryUtils::sqlStatementThrowException(<<<'SQL'
                ALTER TABLE `oce_sinch_appointment_reminders`
                    ADD COLUMN `occurrence_date` DATE NULL AFTER `pc_eid`
                SQL);
        }

        // Backfill from the parent event date for any rows still missing
        // the column value. Safe to run unconditionally — if everything is
        // already populated the UPDATE matches zero rows.
        QueryUtils::sqlStatementThrowException(<<<'SQL'
            UPDATE `oce_sinch_appointment_reminders` r
            INNER JOIN `openemr_postcalendar_events` e ON e.pc_eid = r.pc_eid
            SET r.`occurrence_date` = e.pc_eventDate
            WHERE r.`occurrence_date` IS NULL
            SQL);

        // Drop any rows the join above could not backfill — they reference
        // events that no longer exist, so dedup state is moot.
        QueryUtils::sqlStatementThrowException(<<<'SQL'
            DELETE FROM `oce_sinch_appointment_reminders` WHERE `occurrence_date` IS NULL
            SQL);

        // MODIFY runs when the column was already nullable, OR when this
        // call just added it (ADD COLUMN above creates it as NULL so the
        // backfill UPDATE can populate existing rows before the NOT NULL
        // constraint locks in).
        if ($shape['column_nullable'] || !$shape['column_exists']) {
            QueryUtils::sqlStatementThrowException(<<<'SQL'
                ALTER TABLE `oce_sinch_appointment_reminders`
                    MODIFY `occurrence_date` DATE NOT NULL
                SQL);
        }

        if ($shape['old_unique_index_exists']) {
            QueryUtils::sqlStatementThrowException(<<<'SQL'
                ALTER TABLE `oce_sinch_appointment_reminders`
                    DROP INDEX `unique_event_reminder`
                SQL);
        }

        if (!$shape['new_unique_index_exists']) {
            QueryUtils::sqlStatementThrowException(<<<'SQL'
                ALTER TABLE `oce_sinch_appointment_reminders`
                    ADD UNIQUE KEY `unique_event_occurrence` (`pc_eid`, `occurrence_date`)
                SQL);
        }

        if (!$shape['date_index_exists']) {
            QueryUtils::sqlStatementThrowException(<<<'SQL'
                ALTER TABLE `oce_sinch_appointment_reminders`
                    ADD INDEX `idx_occurrence_date` (`occurrence_date`)
                SQL);
        }
    }

    /**
     * Inspect the current table shape.
     *
     * @return array{
     *     column_exists: bool,
     *     column_nullable: bool,
     *     old_unique_index_exists: bool,
     *     new_unique_index_exists: bool,
     *     date_index_exists: bool,
     *     fully_migrated: bool
     * }|null  null when the table doesn't exist
     */
    private static function probeShape(): ?array
    {
        $tables = QueryUtils::fetchRecords(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );
        if (count($tables) === 0) {
            return null;
        }

        $columns = QueryUtils::fetchRecords(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [self::TABLE, self::COLUMN]
        );
        $columnExists = count($columns) > 0;
        // INFORMATION_SCHEMA contracts IS_NULLABLE as 'YES' or 'NO'.
        // Narrow rather than cast so a non-string value surfaces as a
        // hard failure instead of silently flipping to "non-nullable".
        $columnNullable = false;
        if ($columnExists) {
            $isNullable = $columns[0]['IS_NULLABLE'] ?? null;
            if (!is_string($isNullable)) {
                throw new \RuntimeException(sprintf(
                    'INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE was not a string for %s.%s',
                    self::TABLE,
                    self::COLUMN
                ));
            }
            $columnNullable = strtoupper($isNullable) === 'YES';
        }

        $indexRows = QueryUtils::fetchRecords(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );
        $indexNames = [];
        foreach ($indexRows as $row) {
            $name = $row['INDEX_NAME'] ?? null;
            if (!is_string($name)) {
                throw new \RuntimeException(sprintf(
                    'INFORMATION_SCHEMA.STATISTICS.INDEX_NAME was not a string for %s',
                    self::TABLE
                ));
            }
            $indexNames[] = $name;
        }
        $oldUnique = in_array(self::OLD_UNIQUE_INDEX, $indexNames, true);
        $newUnique = in_array(self::NEW_UNIQUE_INDEX, $indexNames, true);
        $dateIndex = in_array(self::DATE_INDEX, $indexNames, true);

        $fullyMigrated = $columnExists
            && !$columnNullable
            && !$oldUnique
            && $newUnique
            && $dateIndex;

        return [
            'column_exists' => $columnExists,
            'column_nullable' => $columnNullable,
            'old_unique_index_exists' => $oldUnique,
            'new_unique_index_exists' => $newUnique,
            'date_index_exists' => $dateIndex,
            'fully_migrated' => $fullyMigrated,
        ];
    }

    /**
     * Build the per-tenant advisory lock name.
     *
     * `GET_LOCK` is server-wide (not database-scoped). On a shared MySQL
     * instance hosting multiple tenants, a bare lock name would let one
     * tenant's migration block another's. Suffix with `DATABASE()` so
     * each tenant serialises against itself only.
     */
    private static function buildLockName(): string
    {
        $row = QueryUtils::fetchRecords('SELECT DATABASE() AS db');
        $db = $row[0]['db'] ?? null;
        if (!is_string($db) || $db === '') {
            // No current database — without a tenant suffix the lock would
            // collide across tenants on a shared MySQL instance, which is
            // exactly what the per-tenant scheme exists to prevent. Refuse.
            throw new \RuntimeException(
                'SELECT DATABASE() returned no value; cannot build per-tenant migration lock name'
            );
        }
        return self::LOCK_NAME_PREFIX . ':' . $db;
    }

    /**
     * Acquire a MySQL advisory lock.
     *
     * `GET_LOCK` returns 1 on success, 0 on timeout, NULL on error or
     * when the connection's wait was killed. Treat anything other than
     * 1 as "not acquired".
     */
    private static function acquireLock(string $lockName): bool
    {
        $row = QueryUtils::fetchRecords(
            'SELECT GET_LOCK(?, ?) AS got',
            [$lockName, self::LOCK_TIMEOUT_SECONDS]
        );
        $got = $row[0]['got'] ?? null;
        // GET_LOCK returns 1, 0, or NULL. Mock layers may surface a string;
        // coerce only after narrowing to a scalar so non-scalar surprises
        // (driver bugs, mocks gone wrong) bubble up as a hard failure.
        return is_int($got) ? $got === 1 : (is_string($got) && $got === '1');
    }

    /**
     * Release the advisory lock acquired by acquireLock().
     *
     * Errors here are logged but never re-thrown: this runs from
     * `finally`, and re-throwing would mask whatever exception caused
     * `runMigrationSteps()` to fail. The lock will free when the
     * session terminates regardless.
     */
    private static function releaseLock(string $lockName): void
    {
        try {
            QueryUtils::sqlStatementThrowException('DO RELEASE_LOCK(?)', [$lockName]);
        } catch (\Throwable $e) {
            (new SystemLogger())->warning(
                'Failed to release reminder migration advisory lock; will free at session end',
                [
                    'lock_name' => $lockName,
                    'exception' => ExceptionContext::fromThrowable($e),
                ]
            );
        }
    }
}
