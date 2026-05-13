<?php

/**
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\Service\CoreAppointmentFinder;
use OpenEMR\Core\OEGlobalsBag;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CoreAppointmentFinder.
 *
 * The fixture at tests/fixtures/CoreAppointmentFinder/library/appointments.inc.php
 * stubs out the two relevant globals from core OpenEMR's appointments library:
 *
 *  - fetchAppointments() — controllable via $GLOBALS, returns canned event rows
 *  - fetchAllEvents()    — throws RuntimeException
 *
 * The throwing fetchAllEvents() stub is the file-wide regression guard for
 * the 1.2.0 bug where the finder called the wrong core helper and silently
 * dispatched zero reminders. If a future change re-wires that call, every
 * test in this file fails loudly instead of the cron going quiet.
 */
class CoreAppointmentFinderTest extends TestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/../../fixtures/CoreAppointmentFinder';

    protected function setUp(): void
    {
        parent::setUp();
        OEGlobalsBag::reset();
        $GLOBALS['fileroot'] = self::FIXTURE_ROOT;
        $GLOBALS['__test_fetch_appointments_calls'] = [];
        $GLOBALS['__test_fetch_appointments_return'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['fileroot'],
            $GLOBALS['__test_fetch_appointments_calls'],
            $GLOBALS['__test_fetch_appointments_return'],
        );
        OEGlobalsBag::reset();
        parent::tearDown();
    }

    public function testCallsFetchAppointmentsWithDateWindowBounds(): void
    {
        $now = new \DateTimeImmutable('2026-05-13 09:00:00');

        (new CoreAppointmentFinder())->findUpcoming(10, $now);

        $this->assertCount(1, $GLOBALS['__test_fetch_appointments_calls']);
        $this->assertSame(
            ['from_date' => '2026-05-13', 'to_date' => '2026-05-13'],
            $GLOBALS['__test_fetch_appointments_calls'][0],
        );
    }

    public function testReturnsPatientOccurrencesInsideWindow(): void
    {
        $now = new \DateTimeImmutable('2026-05-13 09:00:00');
        $GLOBALS['__test_fetch_appointments_return'] = [
            $this->event(pcEid: 65, pcPid: 5, date: '2026-05-13', time: '14:00:00', phone: '+15102551233', sms: 'YES'),
            $this->event(pcEid: 70, pcPid: 14, date: '2026-05-13', time: '11:00:00', phone: '+14123704170', sms: 'YES'),
        ];

        $result = (new CoreAppointmentFinder())->findUpcoming(10, $now);

        $this->assertCount(2, $result);
        $this->assertSame(65, $result[0]['pc_eid']);
        $this->assertSame(5, $result[0]['pc_pid']);
        $this->assertSame('14:00:00', $result[0]['pc_startTime']);
        $this->assertSame('+15102551233', $result[0]['phone_cell']);
        $this->assertSame('YES', $result[0]['hipaa_allowsms']);
        $this->assertSame(14, $result[1]['pc_pid']);
    }

    /**
     * Defence in depth against the 1.2.0 bug. If fetchAppointments ever
     * starts returning rows without a patient (a core change, a future
     * caller passing custom WHERE clauses, etc.), the finder's positive-int
     * pc_pid guard must drop them rather than emit a malformed occurrence.
     * The throwing fetchAllEvents fixture catches the wrong-function half
     * of the original bug; this test catches the wrong-shape half.
     */
    public function testRejectsRowsWithoutPatientId(): void
    {
        $now = new \DateTimeImmutable('2026-05-13 09:00:00');
        $GLOBALS['__test_fetch_appointments_return'] = [
            // Patient appointment — should appear.
            $this->event(pcEid: 65, pcPid: 5, date: '2026-05-13', time: '14:00:00'),
            // Availability-style row matching the OpenEMR mysqli return
            // shape: pc_pid is the empty string, not null. The asPositiveInt
            // guard must drop both shapes — the empty string slipping
            // through would mean fetchAppointments-shaped rows where the
            // patient JOIN didn't match are emitted as malformed
            // occurrences. Cover both to keep that exit closed.
            $this->event(pcEid: 7, pcPid: '', date: '2026-05-13', time: '10:00:00'),
            $this->event(pcEid: 8, pcPid: null, date: '2026-05-13', time: '10:30:00'),
        ];

        $result = (new CoreAppointmentFinder())->findUpcoming(10, $now);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['pc_pid']);
    }

    public function testFiltersOutOccurrencesOutsideTheTimeWindow(): void
    {
        $now = new \DateTimeImmutable('2026-05-13 09:00:00');
        $GLOBALS['__test_fetch_appointments_return'] = [
            // Already past at $now — drop.
            $this->event(pcEid: 1, pcPid: 5, date: '2026-05-13', time: '08:00:00'),
            // Inside window.
            $this->event(pcEid: 2, pcPid: 5, date: '2026-05-13', time: '14:00:00'),
            // Beyond +10h boundary (19:01 > 19:00).
            $this->event(pcEid: 3, pcPid: 5, date: '2026-05-13', time: '19:01:00'),
        ];

        $result = (new CoreAppointmentFinder())->findUpcoming(10, $now);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['pc_eid']);
    }

    public function testSkipsCancelledAppointments(): void
    {
        $now = new \DateTimeImmutable('2026-05-13 09:00:00');
        $GLOBALS['__test_fetch_appointments_return'] = [
            $this->event(pcEid: 1, pcPid: 5, date: '2026-05-13', time: '14:00:00', status: 'x'),
            $this->event(pcEid: 2, pcPid: 5, date: '2026-05-13', time: '15:00:00', status: '-'),
        ];

        $result = (new CoreAppointmentFinder())->findUpcoming(10, $now);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['pc_eid']);
    }

    public function testReturnsEmptyWhenFilerootMissing(): void
    {
        unset($GLOBALS['fileroot']);

        $result = (new CoreAppointmentFinder())->findUpcoming(10, new \DateTimeImmutable());

        $this->assertSame([], $result);
        $this->assertSame([], $GLOBALS['__test_fetch_appointments_calls']);
    }

    /**
     * @param int|string|null $pcPid Mirrors the production seam: the row
     *     comes back from fetchAppointments via OpenEMR's mysqli driver,
     *     which historically returns numeric columns as numeric strings
     *     and produces an empty string when the patient_data LEFT JOIN
     *     finds no match. Tests pass each variant to exercise the
     *     finder's asPositiveInt guard.
     * @return array<string, mixed>
     */
    private function event(
        int $pcEid,
        int|string|null $pcPid,
        string $date,
        string $time,
        ?string $phone = null,
        ?string $sms = null,
        string $status = '-',
    ): array {
        return [
            'pc_eid' => $pcEid,
            'pc_pid' => $pcPid,
            'pc_eventDate' => $date,
            'pc_startTime' => $time,
            'pc_apptstatus' => $status,
            'phone_cell' => $phone,
            'hipaa_allowsms' => $sms,
        ];
    }
}
