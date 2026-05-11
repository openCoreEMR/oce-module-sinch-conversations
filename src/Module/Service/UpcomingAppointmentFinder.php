<?php

/**
 * Upcoming Appointment Finder
 *
 * Locate calendar occurrences (one-shot or recurring) whose start time
 * falls within a window after `now`. Implementations expand recurring
 * events into one record per occurrence.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

interface UpcomingAppointmentFinder
{
    /**
     * Return appointment occurrences whose start datetime is in
     * `($now, $now + $windowHours]`.
     *
     * Recurring events MUST be expanded to one returned record per
     * occurrence; the same `pc_eid` may appear multiple times with
     * different `pc_eventDate` values.
     *
     * @return list<array{
     *     pc_eid: int,
     *     pc_pid: int,
     *     pc_eventDate: string,
     *     pc_startTime: string,
     *     phone_cell: ?string,
     *     hipaa_allowsms: ?string
     * }>
     */
    public function findUpcoming(int $windowHours, \DateTimeImmutable $now): array;
}
