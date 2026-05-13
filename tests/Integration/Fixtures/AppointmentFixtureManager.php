<?php

/**
 * Manages test appointment rows in openemr_postcalendar_events.
 *
 * Mirrors the upstream BaseFixtureManager pattern (every fixture row gets a
 * known prefix in pc_title; cleanup deletes by prefix). Two reasons we
 * write our own rather than reuse upstream's helpers:
 *
 *   - Upstream ships no AppointmentFixtureManager in tests/Tests/Fixtures.
 *   - OpenEMR's AppointmentService::insert() does not write pc_recurrtype /
 *     pc_recurrspec — those columns are only populated by the procedural
 *     interface/main/calendar/add_edit_event.php form handler. To create a
 *     recurring appointment we mirror the column shape that handler writes.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration\Fixtures;

use OpenEMR\Common\Database\QueryUtils;

class AppointmentFixtureManager
{
    public const TITLE_PREFIX = 'oce-sinch-test-fixture';

    /**
     * Insert a one-shot appointment.
     *
     * @return int pc_eid
     */
    public function insertOneShot(
        int $pid,
        \DateTimeImmutable $when,
        int $durationMinutes = 30,
        string $apptStatus = '-'
    ): int {
        return $this->insertEvent(
            pid: $pid,
            when: $when,
            durationMinutes: $durationMinutes,
            apptStatus: $apptStatus,
            recurrType: 0,
            recurrSpec: null,
            endDate: '0000-00-00'
        );
    }

    /**
     * Insert a daily-recurring appointment.
     *
     * Writes the same column shape that interface/main/calendar/add_edit_event.php
     * writes on form submit for daily recurrence: pc_recurrtype = 1,
     * pc_recurrspec = serialize(['event_repeat_freq' => '1',
     * 'event_repeat_freq_type' => '4', 'event_repeat_on_num' => '1',
     * 'event_repeat_on_day' => '0', 'event_repeat_on_freq' => '0',
     * 'exdate' => '']) — frequency type 4 = daily.
     *
     * @return int pc_eid (one row covers the whole series; core's
     *     fetchAppointments expands it into per-occurrence rows)
     */
    public function insertDailyRecurring(
        int $pid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $durationMinutes = 30,
        string $apptStatus = '-'
    ): int {
        $recurrSpec = [
            'event_repeat_freq' => '1',
            'event_repeat_freq_type' => '4',
            'event_repeat_on_num' => '1',
            'event_repeat_on_day' => '0',
            'event_repeat_on_freq' => '0',
            'exdate' => '',
        ];

        return $this->insertEvent(
            pid: $pid,
            when: $start,
            durationMinutes: $durationMinutes,
            apptStatus: $apptStatus,
            recurrType: 1,
            recurrSpec: $recurrSpec,
            endDate: $end->format('Y-m-d')
        );
    }

    public function removeFixtures(): void
    {
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM openemr_postcalendar_events WHERE pc_title LIKE ?",
            [self::TITLE_PREFIX . '%']
        );
    }

    /**
     * @param array<string, string>|null $recurrSpec
     */
    private function insertEvent(
        int $pid,
        \DateTimeImmutable $when,
        int $durationMinutes,
        string $apptStatus,
        int $recurrType,
        ?array $recurrSpec,
        string $endDate
    ): int {
        $startTime = $when->format('H:i:s');
        $endTime = $when->modify("+{$durationMinutes} minutes")->format('H:i:s');
        $durationSeconds = $durationMinutes * 60;
        $title = self::TITLE_PREFIX . '-' . bin2hex(random_bytes(4));

        $sql = "INSERT INTO openemr_postcalendar_events SET
            pc_pid = ?,
            pc_catid = 9,
            pc_title = ?,
            pc_duration = ?,
            pc_hometext = '',
            pc_eventDate = ?,
            pc_endDate = ?,
            pc_apptstatus = ?,
            pc_startTime = ?,
            pc_endTime = ?,
            pc_facility = 0,
            pc_billing_location = 0,
            pc_informant = 1,
            pc_eventstatus = 1,
            pc_sharing = 1,
            pc_aid = 1,
            pc_recurrtype = ?,
            pc_recurrspec = ?";

        QueryUtils::sqlStatementThrowException($sql, [
            $pid,
            $title,
            $durationSeconds,
            $when->format('Y-m-d'),
            $endDate,
            $apptStatus,
            $startTime,
            $endTime,
            $recurrType,
            $recurrSpec === null ? '' : serialize($recurrSpec),
        ]);

        $row = QueryUtils::querySingleRow(
            "SELECT pc_eid FROM openemr_postcalendar_events WHERE pc_title = ?",
            [$title]
        );
        if (!is_array($row) || !isset($row['pc_eid'])) {
            throw new \RuntimeException("Failed to read back inserted appointment {$title}");
        }
        return (int) $row['pc_eid'];
    }
}
