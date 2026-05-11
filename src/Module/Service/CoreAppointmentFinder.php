<?php

/**
 * Default UpcomingAppointmentFinder backed by core OpenEMR
 *
 * Wraps `library/appointments.inc.php::fetchAllEvents()`, which expands
 * `pc_recurrtype` / `pc_recurrspec` into per-occurrence rows. Production
 * uses this; unit tests inject a stub instead so they don't have to load
 * the procedural library or wire up the kernel event dispatcher.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenEMR\Core\OEGlobalsBag;

class CoreAppointmentFinder implements UpcomingAppointmentFinder
{
    /**
     * @inheritDoc
     */
    public function findUpcoming(int $windowHours, \DateTimeImmutable $now): array
    {
        $fileroot = (string) (OEGlobalsBag::getInstance()->get('fileroot') ?? '');
        if ($fileroot === '') {
            return [];
        }

        require_once $fileroot . '/library/appointments.inc.php';

        $windowEnd = $now->modify(sprintf('+%d hours', $windowHours));
        // fetchAllEvents filters by date (Y-m-d), so widen one extra day
        // to be safe — we re-check the precise time bound in PHP below.
        $fromDate = $now->format('Y-m-d');
        $toDate = $windowEnd->format('Y-m-d');

        /** @var array<int, array<string, mixed>>|false $events */
        $events = \fetchAllEvents($fromDate, $toDate);
        if (!is_array($events)) {
            return [];
        }

        $occurrences = [];
        foreach ($events as $event) {
            $pcPid = (int) ($event['pc_pid'] ?? 0);
            if ($pcPid <= 0) {
                continue;
            }

            $apptStatus = (string) ($event['pc_apptstatus'] ?? '');
            if ($apptStatus === 'x') {
                continue;
            }

            $eventDate = (string) ($event['pc_eventDate'] ?? '');
            $startTime = (string) ($event['pc_startTime'] ?? '');
            if ($eventDate === '' || $startTime === '') {
                continue;
            }

            try {
                $apptDt = new \DateTimeImmutable($eventDate . ' ' . $startTime);
            } catch (\Throwable) {
                continue;
            }

            if ($apptDt <= $now || $apptDt > $windowEnd) {
                continue;
            }

            $occurrences[] = [
                'pc_eid' => (int) ($event['pc_eid'] ?? 0),
                'pc_pid' => $pcPid,
                'pc_eventDate' => $eventDate,
                'pc_startTime' => $startTime,
                'phone_cell' => isset($event['phone_cell']) ? (string) $event['phone_cell'] : null,
                'hipaa_allowsms' => isset($event['hipaa_allowsms']) ? (string) $event['hipaa_allowsms'] : null,
            ];
        }

        return $occurrences;
    }
}
