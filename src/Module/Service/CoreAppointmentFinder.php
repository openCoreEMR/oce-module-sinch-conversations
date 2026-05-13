<?php

/**
 * Default UpcomingAppointmentFinder backed by core OpenEMR
 *
 * Wraps `library/appointments.inc.php::fetchAppointments()`, which
 * expands `pc_recurrtype` / `pc_recurrspec` into per-occurrence rows and
 * scopes results to patient appointments (`e.pc_pid != ''`). Production
 * uses this; unit tests inject a stub instead so they don't have to load
 * the procedural library or wire up the kernel event dispatcher.
 *
 * Why not fetchAllEvents: it returns provider availability blocks
 * alongside patient appointments, so reminders fanned out to events with
 * no patient attached. See #143.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;

class CoreAppointmentFinder implements UpcomingAppointmentFinder
{
    /**
     * @inheritDoc
     */
    public function findUpcoming(int $windowHours, \DateTimeImmutable $now): array
    {
        $fileroot = OEGlobalsBag::getInstance()->getString('fileroot');
        if ($fileroot === '') {
            // No way to require library/appointments.inc.php — surface
            // the misconfiguration loudly so a clinic seeing zero
            // reminders can find the cause instead of silently shipping.
            (new SystemLogger())->error(
                'CoreAppointmentFinder: $GLOBALS[fileroot] is empty; cannot load core appointment helpers'
            );
            return [];
        }

        require_once $fileroot . '/library/appointments.inc.php';

        $windowEnd = $now->modify(sprintf('+%d hours', $windowHours));
        // fetchAppointments filters by date (Y-m-d) inclusive on both
        // ends, so a window that crosses midnight already pulls in the
        // trailing calendar day. We re-check the precise time bound in
        // PHP below.
        $fromDate = $now->format('Y-m-d');
        $toDate = $windowEnd->format('Y-m-d');

        $events = \fetchAppointments($fromDate, $toDate);
        if (!is_array($events)) {
            return [];
        }

        $logger = new SystemLogger();
        $occurrences = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            // Required fields. The OpenEMR mysqli driver returns numeric
            // columns as native ints when configured, but historically as
            // numeric strings — accept both for the integer columns,
            // reject anything else.
            $pcPid = self::asPositiveInt($event['pc_pid'] ?? null);
            if ($pcPid === null) {
                continue;
            }
            $pcEid = self::asPositiveInt($event['pc_eid'] ?? null);
            if ($pcEid === null) {
                continue;
            }

            $apptStatus = $event['pc_apptstatus'] ?? null;
            if (is_string($apptStatus) && $apptStatus === 'x') {
                continue;
            }

            $eventDate = $event['pc_eventDate'] ?? null;
            $startTime = $event['pc_startTime'] ?? null;
            if (
                !is_string($eventDate) || !is_string($startTime)
                || $eventDate === '' || $startTime === ''
            ) {
                continue;
            }

            try {
                $apptDt = new \DateTimeImmutable($eventDate . ' ' . $startTime);
            } catch (\Throwable) {
                $logger->warning('Skipping event with unparseable datetime', [
                    'pc_eid' => $pcEid,
                    'pc_eventDate' => $eventDate,
                    'pc_startTime' => $startTime,
                ]);
                continue;
            }

            if ($apptDt <= $now || $apptDt > $windowEnd) {
                continue;
            }

            // Optional fields — surface as null when absent or non-string;
            // downstream eligibility checks handle that explicitly.
            $phoneCell = $event['phone_cell'] ?? null;
            $hipaaAllowSms = $event['hipaa_allowsms'] ?? null;

            $occurrences[] = [
                'pc_eid' => $pcEid,
                'pc_pid' => $pcPid,
                'pc_eventDate' => $eventDate,
                'pc_startTime' => $startTime,
                'phone_cell' => is_string($phoneCell) ? $phoneCell : null,
                'hipaa_allowsms' => is_string($hipaaAllowSms) ? $hipaaAllowSms : null,
            ];
        }

        return $occurrences;
    }

    /**
     * Narrow a value into a positive int, accepting both native ints and
     * numeric strings (the historical mysqli return shape). Returns null
     * for anything else, including zero/negative values that wouldn't be
     * a valid event/patient id.
     */
    private static function asPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            return is_int($parsed) ? $parsed : null;
        }
        return null;
    }
}
