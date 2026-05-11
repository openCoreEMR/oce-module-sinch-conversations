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

use OpenEMR\Common\Database\QueryUtils;

final class ReminderTableMigration
{
    private const TABLE = 'oce_sinch_appointment_reminders';
    private const COLUMN = 'occurrence_date';
    private const OLD_UNIQUE_INDEX = 'unique_event_reminder';
    private const NEW_UNIQUE_INDEX = 'unique_event_occurrence';
    private const DATE_INDEX = 'idx_occurrence_date';
    private const LOCK_NAME = 'oce_sinch_reminder_migration';
    private const LOCK_TIMEOUT_SECONDS = 30;

    /**
     * Bring the dedup table to the target shape.
     *
     * Idempotent and partial-migration safe. No-op once the table is fully
     * migrated, or when the table doesn't exist yet (install hasn't run).
     *
     * Concurrency: this method runs from both `ModuleManagerListener::enable()`
     * and the reminder cron, which can race (admin clicks Enable while a cron
     * tick is mid-run). A MySQL session-level advisory lock (`GET_LOCK`)
     * serialises the migration so two callers don't both attempt
     * `ADD COLUMN` / `DROP INDEX` and one fail with a duplicate-DDL error.
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

        if (!self::acquireLock()) {
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
                self::LOCK_NAME,
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
            self::releaseLock();
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
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE `' . self::TABLE . '`
                    ADD COLUMN `' . self::COLUMN . '` DATE NULL AFTER `pc_eid`'
            );
        }

        // Backfill from the parent event date for any rows still missing
        // the column value. Safe to run unconditionally — if everything is
        // already populated the UPDATE matches zero rows.
        QueryUtils::sqlStatementThrowException(
            'UPDATE `' . self::TABLE . '` r
             INNER JOIN `openemr_postcalendar_events` e ON e.pc_eid = r.pc_eid
             SET r.`' . self::COLUMN . '` = e.pc_eventDate
             WHERE r.`' . self::COLUMN . '` IS NULL'
        );

        // Drop any rows the join above could not backfill — they reference
        // events that no longer exist, so dedup state is moot.
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM `' . self::TABLE . '` WHERE `' . self::COLUMN . '` IS NULL'
        );

        // MODIFY runs when the column was already nullable, OR when this
        // call just added it (ADD COLUMN above creates it as NULL so the
        // backfill UPDATE can populate existing rows before the NOT NULL
        // constraint locks in).
        if ($shape['column_nullable'] || !$shape['column_exists']) {
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE `' . self::TABLE . '`
                    MODIFY `' . self::COLUMN . '` DATE NOT NULL'
            );
        }

        if ($shape['old_unique_index_exists']) {
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::OLD_UNIQUE_INDEX . '`'
            );
        }

        if (!$shape['new_unique_index_exists']) {
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE `' . self::TABLE . '`
                    ADD UNIQUE KEY `' . self::NEW_UNIQUE_INDEX . '` (`pc_eid`, `' . self::COLUMN . '`)'
            );
        }

        if (!$shape['date_index_exists']) {
            QueryUtils::sqlStatementThrowException(
                'ALTER TABLE `' . self::TABLE . '`
                    ADD INDEX `' . self::DATE_INDEX . '` (`' . self::COLUMN . '`)'
            );
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
        $columnNullable = $columnExists
            && (string) ($columns[0]['IS_NULLABLE'] ?? '') === 'YES';

        $indexRows = QueryUtils::fetchRecords(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        );
        $indexNames = array_map(
            static fn(array $row): string => (string) ($row['INDEX_NAME'] ?? ''),
            $indexRows
        );
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
     * Acquire a MySQL session-level advisory lock.
     *
     * `GET_LOCK` returns 1 on success, 0 on timeout, NULL on error or
     * when the connection's wait was killed. Treat anything other than
     * 1 as "not acquired".
     */
    private static function acquireLock(): bool
    {
        $row = QueryUtils::fetchRecords(
            'SELECT GET_LOCK(?, ?) AS got',
            [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]
        );
        return (int) ($row[0]['got'] ?? 0) === 1;
    }

    /**
     * Release the advisory lock acquired by acquireLock().
     */
    private static function releaseLock(): void
    {
        QueryUtils::sqlStatementThrowException(
            'DO RELEASE_LOCK(?)',
            [self::LOCK_NAME]
        );
    }
}
