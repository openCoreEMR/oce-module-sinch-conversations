<?php

/**
 * Stub UpcomingAppointmentFinder for tests
 *
 * Returns a preset list of occurrences. Lets reminder-service tests
 * skip the SQL-mock rigging that the old in-line query required.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Mocks;

use OpenCoreEMR\Modules\SinchConversations\Service\UpcomingAppointmentFinder;

class StubAppointmentFinder implements UpcomingAppointmentFinder
{
    /**
     * @param list<array{
     *     pc_eid: int,
     *     pc_pid: int,
     *     pc_eventDate: string,
     *     pc_startTime: string,
     *     phone_cell: ?string,
     *     hipaa_allowsms: ?string
     * }> $occurrences
     */
    public function __construct(private array $occurrences = [])
    {
    }

    public function findUpcoming(int $windowHours, \DateTimeImmutable $now): array
    {
        // Honour the interface contract — only return occurrences whose
        // start datetime falls in `($now, $now + $windowHours]`. A real
        // implementation must filter; the stub does too so tests can't
        // accidentally pass occurrences that the production code would
        // never see.
        $end = $now->modify(sprintf('+%d hours', $windowHours));
        $filtered = [];
        foreach ($this->occurrences as $occ) {
            try {
                $apptDt = new \DateTimeImmutable(
                    $occ['pc_eventDate'] . ' ' . $occ['pc_startTime']
                );
            } catch (\Throwable) {
                continue;
            }
            if ($apptDt > $now && $apptDt <= $end) {
                $filtered[] = $occ;
            }
        }
        return $filtered;
    }

    /**
     * @param list<array{
     *     pc_eid: int,
     *     pc_pid: int,
     *     pc_eventDate: string,
     *     pc_startTime: string,
     *     phone_cell: ?string,
     *     hipaa_allowsms: ?string
     * }> $occurrences
     */
    public function setOccurrences(array $occurrences): void
    {
        $this->occurrences = $occurrences;
    }
}
