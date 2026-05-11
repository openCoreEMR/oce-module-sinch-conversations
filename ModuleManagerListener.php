<?php

/**
 * Module lifecycle hook handler
 *
 * Called by the module installer (ModuleInstaller::callModuleListener) during
 * enable, disable, and unregister actions. Manages the module's
 * background_services table registration for appointment reminders.
 *
 * Note: The installer does not call the "install" hook, so enable() handles
 * initial registration via upsert.
 *
 * This class intentionally has no namespace — the module installer requires
 * it via require_once and looks for a global-namespace ModuleManagerListener.
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

declare(strict_types=1);

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

// phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
class ModuleManagerListener
{
    private const SERVICE_NAME = 'oce_sinch_reminders';

    private const REQUIRE_ONCE_PATH =
        '/interface/modules/custom_modules/oce-module-sinch-conversations/background_service_entry.php';

    /**
     * Return the module namespace for the class loader
     */
    public static function getModuleNamespace(): string
    {
        return 'OpenCoreEMR\\Modules\\SinchConversations\\';
    }

    /**
     * Return the PSR-4 source path relative to the module directory
     *
     * The default assumption in upstream OpenEMR is '/src', but this
     * module maps its namespace to '/src/Module'.
     */
    public static function getModuleSourcePath(): string
    {
        return '/src/Module';
    }

    /**
     * Return a new instance for the module installer
     */
    public static function initListenerSelf(): self
    {
        return new self();
    }

    /**
     * Dispatch lifecycle actions
     *
     * Catches exceptions from lifecycle methods, logs them with a traceable
     * error ID, and returns a user-safe message. The caller
     * (ModuleInstaller::callModuleListener) displays the returned string.
     *
     * @param string $methodName
     * @param int    $modId
     * @param string $currentActionStatus
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (!method_exists($this, $methodName)) {
            return $currentActionStatus;
        }

        try {
            $this->$methodName();
        } catch (\Throwable $e) {
            $errorId = \OpenCoreEMR\Modules\SinchConversations\ErrorId::generate();
            (new SystemLogger())->error(
                'Background service lifecycle action failed',
                ['errorId' => $errorId, 'method' => $methodName, 'mod_id' => $modId, 'exception' => $e]
            );
            return "Background service $methodName failed (ref: $errorId). Check logs for details.";
        }

        return $currentActionStatus;
    }

    /**
     * On install: register the background service
     *
     * Not currently called by our ModuleInstaller, but kept as a safety
     * net in case upstream adds an install hook in the future.
     */
    private function install(): void
    {
        $this->registerBackgroundService();
    }

    /**
     * On enable: register (if needed) and activate the background service
     *
     * The install hook is not called by the module installer, so we ensure
     * the background service row exists before activating it. The upsert
     * sets active=1 on fresh INSERT, but the explicit UPDATE is needed for
     * re-enable after disable (where ON DUPLICATE KEY UPDATE does not
     * reset active).
     */
    private function enable(): void
    {
        $this->registerBackgroundService();
        $this->migrateAppointmentRemindersSchema();
        $this->setBackgroundServiceActive(true);
    }

    /**
     * On disable: deactivate the background service
     */
    private function disable(): void
    {
        $this->setBackgroundServiceActive(false);
    }

    /**
     * On unregister: remove the background service
     */
    private function unregister(): void
    {
        $this->deleteBackgroundService();
    }

    /**
     * Insert or update the background service registration
     *
     * @throws \Throwable on database failure
     */
    private function registerBackgroundService(): void
    {
        $sql = <<<'SQL'
            INSERT INTO `background_services`
                (`name`, `title`, `active`, `running`, `next_run`,
                 `execute_interval`, `function`, `require_once`, `sort_order`)
            VALUES
                (?, 'Sinch Appointment Reminders', 1, 0, NOW(),
                 15, 'oce_sinch_run_appointment_reminders', ?, 100)
            ON DUPLICATE KEY UPDATE
                `title` = VALUES(`title`),
                `function` = VALUES(`function`),
                `require_once` = VALUES(`require_once`),
                `execute_interval` = VALUES(`execute_interval`),
                `sort_order` = VALUES(`sort_order`)
            SQL;

        QueryUtils::sqlStatementThrowException($sql, [
            self::SERVICE_NAME,
            self::REQUIRE_ONCE_PATH,
        ]);
    }

    /**
     * Activate or deactivate the background service
     *
     * @throws \Throwable on database failure
     */
    private function setBackgroundServiceActive(bool $active): void
    {
        $sql = <<<'SQL'
            UPDATE `background_services` SET `active` = ? WHERE `name` = ?
            SQL;

        QueryUtils::sqlStatementThrowException($sql, [
            $active ? 1 : 0,
            self::SERVICE_NAME,
        ]);
    }

    /**
     * Remove the background service registration
     *
     * @throws \Throwable on database failure
     */
    private function deleteBackgroundService(): void
    {
        $sql = <<<'SQL'
            DELETE FROM `background_services` WHERE `name` = ?
            SQL;

        QueryUtils::sqlStatementThrowException($sql, [self::SERVICE_NAME]);
    }

    /**
     * In-place upgrade for the appointment reminders dedup table.
     *
     * Earlier versions used UNIQUE KEY (pc_eid). That blocks all but the
     * first occurrence of a recurring appointment from ever sending an
     * SMS reminder. The new key is (pc_eid, occurrence_date). Module
     * installers run table.sql on install only, so existing tenants need
     * an in-place migration on enable.
     *
     * Idempotent: detects the new shape via INFORMATION_SCHEMA and
     * returns immediately if the column already exists.
     *
     * Pre-existing dedup rows backfill `occurrence_date` from the parent
     * event's `pc_eventDate`. For one-shots that's the correct value.
     * For pre-existing recurring rows (which never sent more than one
     * reminder anyway under the old key) the backfill is a best-effort
     * that may cause one occurrence-mismatch dedup; the 90-day TTL on
     * these rows bounds the impact.
     *
     * @throws \Throwable on database failure
     */
    private function migrateAppointmentRemindersSchema(): void
    {
        $columns = QueryUtils::fetchRecords(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['oce_sinch_appointment_reminders', 'occurrence_date']
        );
        if (count($columns) > 0) {
            return;
        }

        // Verify the table itself exists — if it doesn't, install hasn't
        // happened yet and table.sql will create the new shape directly.
        $tables = QueryUtils::fetchRecords(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders']
        );
        if (count($tables) === 0) {
            return;
        }

        QueryUtils::sqlStatementThrowException(
            'ALTER TABLE `oce_sinch_appointment_reminders`
                ADD COLUMN `occurrence_date` DATE NULL AFTER `pc_eid`'
        );

        QueryUtils::sqlStatementThrowException(
            'UPDATE `oce_sinch_appointment_reminders` r
             INNER JOIN `openemr_postcalendar_events` e ON e.pc_eid = r.pc_eid
             SET r.occurrence_date = e.pc_eventDate
             WHERE r.occurrence_date IS NULL'
        );

        // Drop any rows the join above could not backfill — they reference
        // events that no longer exist, so dedup state is moot.
        QueryUtils::sqlStatementThrowException(
            'DELETE FROM `oce_sinch_appointment_reminders` WHERE `occurrence_date` IS NULL'
        );

        QueryUtils::sqlStatementThrowException(
            'ALTER TABLE `oce_sinch_appointment_reminders`
                MODIFY `occurrence_date` DATE NOT NULL,
                DROP INDEX `unique_event_reminder`,
                ADD UNIQUE KEY `unique_event_occurrence` (`pc_eid`, `occurrence_date`),
                ADD INDEX `idx_occurrence_date` (`occurrence_date`)'
        );
    }
}
