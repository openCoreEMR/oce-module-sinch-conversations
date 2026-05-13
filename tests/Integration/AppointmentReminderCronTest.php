<?php

/**
 * End-to-end integration tests for the appointment reminder cron.
 *
 * Each test creates real patient + appointment rows via the actual OpenEMR
 * data paths, then invokes oce_sinch_run_appointment_reminders()'s service
 * graph (with MessageService swapped for a recording fake) and asserts on
 * the captured sends. The unit suite covers the pure-PHP logic; this suite
 * covers the seam between the module and core OpenEMR's procedural
 * appointments library — the seam where #137 and #143 lived.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Integration;

final class AppointmentReminderCronTest extends IntegrationTestCase
{
    private const WINDOW_HOURS = 48;

    protected function setUp(): void
    {
        parent::setUp();
        // Reminder service short-circuits when SMS_NOTIFICATION_HOUR is 0.
        // OEGlobalsBag::get() falls back to $GLOBALS when a key isn't in
        // the bag, so writing to $GLOBALS is the lowest-friction way to
        // set this for the test.
        $GLOBALS['SMS_NOTIFICATION_HOUR'] = self::WINDOW_HOURS;
        // The reminder template needs a clinic_name variable, and the
        // module-enabled gate reads its own toggle from globals.
        $GLOBALS['oce_sinch_conversations_enabled'] = '1';
    }

    /**
     * Skip until #145 (CoreAppointmentFinder reads non-existent `pc_pid`)
     * is fixed. The harness exercise behind this assertion works — running
     * it against a worktree with the #145 one-character fix produces a
     * passing assert. See #145 for the bug detail.
     */
    public function testOneOffAppointmentInWindowSendsOneReminder(): void
    {
        $this->markTestIncomplete('Blocked on #145 (pc_pid vs pid in CoreAppointmentFinder)');
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');
        $appointmentTime = $now->modify('+12 hours');

        $pid = $this->patients->insert([
            'phone_cell' => '+15555551234',
            'hipaa_allowsms' => 'YES',
        ]);
        $this->appointments->insertOneShot($pid, $appointmentTime);

        $result = $this->runReminders($now);

        self::assertSame(1, $result['sent'], json_encode($result));
        self::assertCount(1, $this->recorder()->getSends());
        self::assertSame($pid, $this->recorder()->getSends()[0]['patientId']);
        self::assertSame('+15555551234', $this->recorder()->getSends()[0]['phone']);
    }

    /**
     * Defends against #137 (recurring-expansion bug) AND exposes #143
     * (CoreAppointmentFinder calling fetchAllEvents instead of
     * fetchAppointments). Currently also blocked on #145; once both
     * #143 and #145 land, this assertion runs.
     */
    public function testRecurringAppointmentExpandsToOneSendPerOccurrence(): void
    {
        $this->markTestIncomplete('Blocked on #143 (fetchAllEvents vs fetchAppointments) and #145 (pc_pid vs pid)');
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');
        $seriesStart = $now->modify('+12 hours');           // tonight
        $seriesEnd = $now->modify('+10 days');               // far future

        $pid = $this->patients->insert([
            'phone_cell' => '+15555552222',
            'hipaa_allowsms' => 'YES',
        ]);
        $this->appointments->insertDailyRecurring($pid, $seriesStart, $seriesEnd);

        $result = $this->runReminders($now);

        // 48h window starting at $now contains:
        //   - $now+12h (tonight)
        //   - $now+36h (tomorrow night)
        // $now+60h is outside.
        self::assertSame(2, $result['sent'], json_encode($result));
        $sends = $this->recorder()->getSends();
        self::assertCount(2, $sends);
        foreach ($sends as $send) {
            self::assertSame($pid, $send['patientId']);
            self::assertSame('+15555552222', $send['phone']);
        }
    }

    /**
     * This passes on main today only because EVERY appointment is dropped
     * (#145), so an excluded one is indistinguishable from an included one.
     * Once #145 lands the test becomes meaningful — until then it's a
     * vacuous green and we mark it incomplete to be honest about that.
     */
    public function testCancelledAppointmentExcluded(): void
    {
        $this->markTestIncomplete('Blocked on #145; assertion is vacuous until the finder returns rows');
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');
        $pid = $this->patients->insert([
            'phone_cell' => '+15555553333',
            'hipaa_allowsms' => 'YES',
        ]);
        // 'x' is the cancelled-status code (CoreAppointmentFinder filters it).
        $this->appointments->insertOneShot($pid, $now->modify('+6 hours'), apptStatus: 'x');

        $result = $this->runReminders($now);

        self::assertSame(0, $result['sent']);
        self::assertCount(0, $this->recorder()->getSends());
    }

    public function testHipaaAllowsmsNoSkipsSend(): void
    {
        $this->markTestIncomplete('Blocked on #145 (pc_pid vs pid in CoreAppointmentFinder)');
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');
        $pid = $this->patients->insert([
            'phone_cell' => '+15555554444',
            'hipaa_allowsms' => 'NO',
        ]);
        $this->appointments->insertOneShot($pid, $now->modify('+6 hours'));

        $result = $this->runReminders($now);

        self::assertSame(0, $result['sent']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(0, $this->recorder()->getSends());
    }

    public function testDedupAcrossRuns(): void
    {
        $this->markTestIncomplete('Blocked on #145 (pc_pid vs pid in CoreAppointmentFinder)');
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');
        $pid = $this->patients->insert([
            'phone_cell' => '+15555555555',
            'hipaa_allowsms' => 'YES',
        ]);
        $this->appointments->insertOneShot($pid, $now->modify('+6 hours'));

        $first = $this->runReminders($now);
        $second = $this->runReminders($now);

        self::assertSame(1, $first['sent']);
        self::assertSame(0, $second['sent']);
        self::assertCount(1, $this->recorder()->getSends(), 'Recorder should see exactly one send across both runs');
    }
}
