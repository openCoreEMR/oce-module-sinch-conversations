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
        return $this->occurrences;
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
