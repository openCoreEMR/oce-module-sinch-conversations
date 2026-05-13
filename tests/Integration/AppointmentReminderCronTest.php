<?php

/**
 * End-to-end integration tests for the appointment reminder cron.
 *
 * Each test inserts patient + appointment rows via the prefix-tagged
 * fixture managers (direct SQL into patient_data /
 * openemr_postcalendar_events), then invokes the real reminder service
 * graph (with MessageService swapped for a recording fake) and asserts on
 * the captured sends. The fixture rows are deliberately shaped to match
 * what the OpenEMR UI / form handlers write, so core's procedural
 * appointments library expands and returns them the same way it would in
 * production. The unit suite covers the pure-PHP logic; this suite covers
 * the seam between the module and that procedural library — the seam
 * where #137 and #143 lived.
 *
 * Until #143 (fetchAllEvents vs fetchAppointments) and #145 (pc_pid vs
 * pid) are fixed, every scenario except the cancelled-appointment one
 * fails: every event is dropped at the finder, so the reminder service
 * never calls sendToPatient. That red CI status IS the harness's value —
 * the reminder cron is broken in production today and the suite makes
 * that visible until the bugs are fixed.
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

    public function testOneOffAppointmentInWindowSendsOneReminder(): void
    {
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
     * Defends against #137 (recurring-expansion bug) and exposes #143
     * (CoreAppointmentFinder calling fetchAllEvents instead of
     * fetchAppointments). With a 48h window and a daily-recurring
     * appointment whose first occurrence is 12h after $now, exactly two
     * future occurrences fall inside the window.
     */
    public function testRecurringAppointmentExpandsToOneSendPerOccurrence(): void
    {
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
     * This passes vacuously while #145 drops every appointment. Once #145
     * is fixed it becomes the real cancelled-status assertion, and the
     * @depends ensures it only runs when the basic-send path also works
     * (so a green here actually means "we excluded this one", not "we
     * dropped everything").
     */
    public function testCancelledAppointmentExcluded(): void
    {
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
