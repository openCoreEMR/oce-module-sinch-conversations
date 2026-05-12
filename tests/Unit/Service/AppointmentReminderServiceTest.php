<?php

/**
 * Unit tests for AppointmentReminderService
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\AppointmentReminderService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\StubAppointmentFinder;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppointmentReminderServiceTest extends TestCase
{
    private GlobalConfig $config;
    private TemplateService&MockObject $templateService;
    private MessageService&MockObject $messageService;
    private StubAppointmentFinder $finder;
    private AppointmentReminderService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            'SMS_NOTIFICATION_HOUR' => 24,
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]), new MockConfigFactory());

        $this->templateService = $this->createMock(TemplateService::class);
        $this->messageService = $this->createMock(MessageService::class);
        $this->finder = new StubAppointmentFinder();

        $this->service = new AppointmentReminderService(
            $this->config,
            $this->templateService,
            $this->messageService,
            $this->finder
        );
    }

    // --- notification hours = 0 ---

    public function testRunReturnsEarlyWhenNotificationHoursIsZero(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([
            'SMS_NOTIFICATION_HOUR' => 0,
        ]), new MockConfigFactory());
        $service = new AppointmentReminderService(
            $config,
            $this->templateService,
            $this->messageService,
            new StubAppointmentFinder()
        );

        $results = $service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        // No appointment queries should run (purge still runs)
        $queries = QueryUtils::getQueries();
        $appointmentQueries = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'openemr_postcalendar_events')
        );
        $this->assertCount(0, $appointmentQueries);

        $purgeQueries = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'DELETE FROM oce_sinch_appointment_reminders')
        );
        $this->assertNotEmpty($purgeQueries, 'Purge should still run even when notification hours is zero');
    }

    // --- no upcoming appointments ---

    public function testRunReturnsZerosWhenNoAppointments(): void
    {
        $this->mockUpcomingAppointments([]);

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(0, $results['skipped']);
    }

    // --- patient opted in, appointment within window -> reminder sent ---

    public function testRunSendsReminderForEligiblePatient(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(100, 42, '2026-04-01', '09:00:00', '+15559999999', 'YES'),
        ]);
        $this->mockActiveConsent(42, '+15559999999');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Your appointment is coming up.');

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(42, '+15559999999', 'Your appointment is coming up.', $this->anything())
            ->willReturn(['id' => 'msg-reminder-1']);

        $results = $this->service->run();

        $this->assertSame(1, $results['sent']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(0, $results['failed']);

        // Verify reminder was recorded with INSERT IGNORE
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'INSERT IGNORE INTO oce_sinch_appointment_reminders')
        );
        $this->assertNotEmpty($insertQueries);
    }

    // --- patient opted out -> no reminder ---

    public function testRunSkipsPatientWhoOptedOut(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(101, 43, '2026-04-01', '10:00:00', '+15558888888', 'YES'),
        ]);

        // hipaa_allowsms = YES (from main query) but opted_out = true in
        // module table — the explicit opt-out overrides the chart YES.
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [43, '+15558888888'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);

        $this->assertSkipLogged(101, 43, 'module_opt_out', ['consent_state' => 'opted_out']);
    }

    public function testRunSkipsPatientWithCarrierBlockEvenWithoutOptOut(): void
    {
        // setCarrierBlock() can leave a row with carrier_blocked=TRUE but
        // opted_out=FALSE before the paired optOut() runs (or if optOut
        // failed). The reminder cron must still skip these patients and
        // surface carrier_block_reason in the log.
        $this->mockUpcomingAppointments([
            $this->makeAppointment(120, 60, '2026-04-01', '12:00:00', '+15555550000', 'YES'),
        ]);
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [60, '+15555550000'],
            [[
                'opted_in' => false,
                'opted_out' => false,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'consent_api_sync',
            ]]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);

        $this->assertSkipLogged(120, 60, 'carrier_blocked', ['carrier_block_reason' => 'consent_api_sync']);
    }

    public function testRunReportsCarrierBlockedSkipReasonWhenBothFlagsSet(): void
    {
        // Steady state: setCarrierBlock() then optOut() leaves both flags
        // TRUE. The cron must log carrier_blocked (the more specific
        // cause) so the carrier_block_reason context survives — not
        // module_opt_out, which would mask it.
        $this->mockUpcomingAppointments([
            $this->makeAppointment(125, 65, '2026-04-01', '13:00:00', '+15555556666', 'YES'),
        ]);
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [65, '+15555556666'],
            [[
                'opted_in' => false,
                'opted_out' => true,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'smpp_255',
            ]]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);

        $this->assertSkipLogged(125, 65, 'carrier_blocked', ['carrier_block_reason' => 'smpp_255']);
    }

    public function testRunSendsReminderWhenChartYesAndNoConsentRow(): void
    {
        // Under chart-as-source-of-truth, chart YES with no module-side
        // exception row is sufficient to send. (Pre-flip behavior skipped
        // these patients as 'no_active_consent' — this test pins the new
        // positive case.)
        $this->mockUpcomingAppointments([
            $this->makeAppointment(110, 49, '2026-04-01', '10:00:00', '+15554443322', 'YES'),
        ]);
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [49, '+15554443322'],
            []
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')->willReturn('Reminder');

        $this->messageService->expects($this->once())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(1, $results['sent']);
        $this->assertSame(0, $results['skipped']);
    }

    // --- hipaa_allowsms = NO -> no reminder ---

    public function testRunSkipsPatientWithHipaaDisallowSms(): void
    {
        // hipaa_allowsms = NO comes from the main query result
        $this->mockUpcomingAppointments([
            $this->makeAppointment(102, 44, '2026-04-01', '11:00:00', '+15557777777', 'NO'),
        ]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);

        $this->assertSkipLogged(102, 44, 'hipaa_disallows_sms', ['hipaa_allowsms' => 'NO']);
    }

    public function testRunSkipsPatientWithUnsetHipaaAllowSms(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(103, 45, '2026-04-01', '12:00:00', '+15556665555', ''),
        ]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(1, $results['skipped']);
        $this->assertSkipLogged(103, 45, 'hipaa_disallows_sms', ['hipaa_allowsms' => 'unset']);
    }

    // --- reminder already sent -> excluded by LEFT JOIN (not in results) ---

    public function testRunExcludesAlreadySentReminders(): void
    {
        // The LEFT JOIN + r.id IS NULL in the main query filters out appointments
        // that already have a reminder record. So they simply don't appear in the
        // result set. Simulate this by returning an empty appointment list.
        $this->mockUpcomingAppointments([]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(0, $results['skipped']);
    }

    // --- no phone number -> skipped ---

    public function testRunSkipsPatientWithNoPhone(): void
    {
        // Empty phone_cell from the main query
        $this->mockUpcomingAppointments([
            $this->makeAppointment(104, 46, '2026-04-01', '13:00:00', '', 'YES'),
        ]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);

        $this->assertSkipLogged(104, 46, 'missing_phone');
    }

    public function testRunSkipsPatientWithUnparseablePhone(): void
    {
        // 'abc' has no digits, so PhoneNormalizer::toE164() returns null
        $this->mockUpcomingAppointments([
            $this->makeAppointment(120, 55, '2026-04-01', '13:00:00', 'abc', 'YES'),
        ]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(1, $results['skipped']);
        $this->assertSkipLogged(120, 55, 'unparseable_phone', ['phone_last4' => '']);
    }

    public function testRunUnparseablePhoneLogsLast4WhenDigitsPresent(): void
    {
        // '5551234' is 7 digits — too short to disambiguate without '+', so
        // toE164() returns null. last4() should still extract '1234'.
        $this->mockUpcomingAppointments([
            $this->makeAppointment(121, 56, '2026-04-01', '13:00:00', '5551234', 'YES'),
        ]);

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $results = $this->service->run();

        $this->assertSame(1, $results['skipped']);
        $this->assertSkipLogged(121, 56, 'unparseable_phone', ['phone_last4' => '1234']);
    }

    // --- send failure -> counted as failed ---

    public function testRunCountsSendFailure(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(105, 47, '2026-04-01', '14:00:00', '+15556666666', 'YES'),
        ]);
        $this->mockActiveConsent(47, '+15556666666');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Reminder message.');

        $this->messageService->method('sendToPatient')
            ->willThrowException(new ValidationException('API error'));

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['failed']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('send failed', $results['errors'][0]);
    }

    // --- template render failure -> counted as failed ---

    public function testRunCountsTemplateRenderFailure(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(106, 48, '2026-04-01', '15:00:00', '+15555555555', 'YES'),
        ]);
        $this->mockActiveConsent(48, '+15555555555');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willThrowException(new ValidationException('Template not found'));

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['failed']);
        $this->assertStringContainsString('template render failed', $results['errors'][0]);
    }

    // --- multiple appointments: mixed results ---

    public function testRunHandlesMultipleAppointments(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(200, 50, '2026-04-01', '09:00:00', '+15550001111', 'YES'),
            $this->makeAppointment(201, 51, '2026-04-01', '10:00:00', '+15550002222', 'YES'),
            $this->makeAppointment(202, 52, '2026-04-01', '11:00:00', '+15550003333', 'YES'),
        ]);

        // First: eligible, send succeeds
        $this->mockActiveConsent(50, '+15550001111');

        // Second: explicit opt-out -> skipped
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [51, '+15550002222'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        // Third: eligible, send succeeds
        $this->mockActiveConsent(52, '+15550003333');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Reminder message.');

        $this->messageService->method('sendToPatient')
            ->willReturn(['id' => 'msg-ok']);

        $results = $this->service->run();

        $this->assertSame(2, $results['sent']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['failed']);
    }

    // --- portal-enabled config passes portal_url variable ---

    public function testRunPassesPortalUrlWhenPortalEnabled(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([
            'SMS_NOTIFICATION_HOUR' => 24,
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Portal Clinic',
            'portal_onsite_two_enable' => true,
            'portal_onsite_two_address' => 'https://portal.example.com',
        ]), new MockConfigFactory());
        $service = new AppointmentReminderService(
            $config,
            $this->templateService,
            $this->messageService,
            $this->finder
        );

        $this->mockUpcomingAppointments([
            $this->makeAppointment(300, 60, '2026-04-01', '09:00:00', '+15554443333', 'YES'),
        ]);
        $this->mockActiveConsent(60, '+15554443333');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_portal');

        // Capture the variables passed to render
        $this->templateService->expects($this->once())
            ->method('render')
            ->with(
                'appointment_reminder_portal',
                $this->callback(static function (array $vars): bool {
                    return isset($vars['portal_url'])
                        && $vars['portal_url'] === 'https://portal.example.com'
                        && $vars['clinic_name'] === 'Portal Clinic';
                })
            )
            ->willReturn('Portal reminder message.');

        $this->messageService->method('sendToPatient')
            ->willReturn(['id' => 'msg-portal']);

        $results = $service->run();

        $this->assertSame(1, $results['sent']);
    }

    // --- appt_time is human-friendly formatted ---

    public function testRunFormatsApptTimeAsHumanReadable(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(400, 70, '2026-04-01', '14:30:00', '+15551112222', 'YES'),
        ]);
        $this->mockActiveConsent(70, '+15551112222');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->templateService->expects($this->once())
            ->method('render')
            ->with(
                'appointment_reminder_no_portal',
                $this->callback(static function (array $vars): bool {
                    // 2026-04-01 is a Wednesday
                    return $vars['appt_time'] === 'Wednesday, Apr 1, 2026 at 2:30 PM';
                })
            )
            ->willReturn('Formatted reminder.');

        $this->messageService->method('sendToPatient')
            ->willReturn(['id' => 'msg-fmt']);

        $results = $this->service->run();

        $this->assertSame(1, $results['sent']);
    }

    // --- raw phone_cell format is normalized before consent lookup ---

    public function testRunNormalizesPhoneCellBeforeConsentLookup(): void
    {
        // phone_cell is a raw 10-digit number as stored in patient_data,
        // but consent is recorded in E.164 format (+1 prefix)
        $this->mockUpcomingAppointments([
            $this->makeAppointment(500, 80, '2026-04-01', '09:00:00', '5102551233', 'YES'),
        ]);
        $this->mockActiveConsent(80, '+15102551233');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Reminder for raw phone.');

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(80, '+15102551233', 'Reminder for raw phone.', $this->anything())
            ->willReturn(['id' => 'msg-raw-phone']);

        $results = $this->service->run();

        $this->assertSame(1, $results['sent']);
        $this->assertSame(0, $results['skipped']);
    }

    // --- recurring appointments ---

    public function testRunSendsOneReminderPerOccurrenceForRecurringAppointment(): void
    {
        // Use dates relative to "now" so the occurrences mirror what a real
        // CoreAppointmentFinder would return for a daily-recurring appointment
        // within the active reminder window. The stub doesn't enforce the
        // window contract — this is for readability and contract-fidelity.
        $now = new \DateTimeImmutable();
        $day1 = $now->modify('+1 day')->format('Y-m-d');
        $day2 = $now->modify('+2 days')->format('Y-m-d');
        $day3 = $now->modify('+3 days')->format('Y-m-d');

        $this->mockUpcomingAppointments([
            $this->makeAppointment(900, 70, $day1, '09:00:00', '+15557770001', 'YES'),
            $this->makeAppointment(900, 70, $day2, '09:00:00', '+15557770001', 'YES'),
            $this->makeAppointment(900, 70, $day3, '09:00:00', '+15557770001', 'YES'),
        ]);
        $this->mockActiveConsent(70, '+15557770001');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Reminder.');
        $this->messageService->expects($this->exactly(3))
            ->method('sendToPatient')
            ->willReturn(['id' => 'msg-recur']);

        $results = $this->service->run();

        $this->assertSame(3, $results['sent']);
        $this->assertSame(0, $results['skipped']);

        $inserts = array_values(array_filter(
            QueryUtils::getQueries(),
            static fn(array $q): bool => str_contains(
                $q['sql'],
                'INSERT IGNORE INTO oce_sinch_appointment_reminders'
            )
        ));
        $this->assertCount(3, $inserts, 'Expected one INSERT per occurrence');

        $occurrenceDates = array_map(
            static fn(array $q): string => (string) $q['binds'][1],
            $inserts
        );
        sort($occurrenceDates);
        $this->assertSame([$day1, $day2, $day3], $occurrenceDates);
    }

    public function testRunSkipsRecurringOccurrenceAlreadySent(): void
    {
        // Pin $now so the dedup-load bind values match between test setup
        // and service execution. Without this, run()'s internal `new
        // DateTimeImmutable()` could land on the next calendar day if
        // the test crosses midnight, leaving the mocked SELECT unmatched
        // and causing the dedup skip to silently no-op.
        $now = new \DateTimeImmutable('2026-05-15 12:00:00');
        $day1 = $now->modify('+1 day')->format('Y-m-d');
        $day2 = $now->modify('+2 days')->format('Y-m-d');
        $day3 = $now->modify('+3 days')->format('Y-m-d');

        $this->mockUpcomingAppointments([
            $this->makeAppointment(901, 71, $day1, '10:00:00', '+15557770002', 'YES'),
            $this->makeAppointment(901, 71, $day2, '10:00:00', '+15557770002', 'YES'),
            $this->makeAppointment(901, 71, $day3, '10:00:00', '+15557770002', 'YES'),
        ]);
        $this->mockActiveConsent(71, '+15557770002');

        // Pre-seed the dedup table: middle day has already been sent.
        QueryUtils::setMockResult(
            "SELECT pc_eid, occurrence_date
                FROM oce_sinch_appointment_reminders
                WHERE occurrence_date BETWEEN ? AND ?",
            [$now->format('Y-m-d'), $now->modify('+24 hours')->format('Y-m-d')],
            [['pc_eid' => 901, 'occurrence_date' => $day2]]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')
            ->willReturn('Reminder.');
        $this->messageService->expects($this->exactly(2))
            ->method('sendToPatient')
            ->willReturn(['id' => 'msg-dedup']);

        $results = $this->service->run($now);

        $this->assertSame(2, $results['sent']);
        $this->assertSame(0, $results['skipped'], 'Already-sent occurrences are quiet skips, not eligibility skips');

        $inserts = array_values(array_filter(
            QueryUtils::getQueries(),
            static fn(array $q): bool => str_contains(
                $q['sql'],
                'INSERT IGNORE INTO oce_sinch_appointment_reminders'
            )
        ));
        $occurrenceDates = array_map(
            static fn(array $q): string => (string) $q['binds'][1],
            $inserts
        );
        sort($occurrenceDates);
        $this->assertSame([$day1, $day3], $occurrenceDates);
    }

    public function testRecordedReminderBindsIncludeOccurrenceDate(): void
    {
        $occurrenceDate = (new \DateTimeImmutable())->modify('+1 day')->format('Y-m-d');
        $this->mockUpcomingAppointments([
            $this->makeAppointment(902, 72, $occurrenceDate, '09:00:00', '+15557770003', 'YES'),
        ]);
        $this->mockActiveConsent(72, '+15557770003');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')->willReturn('Reminder.');
        $this->messageService->method('sendToPatient')->willReturn(['id' => 'msg-bind']);

        $this->service->run();

        $inserts = array_values(array_filter(
            QueryUtils::getQueries(),
            static fn(array $q): bool => str_contains(
                $q['sql'],
                'INSERT IGNORE INTO oce_sinch_appointment_reminders'
            )
        ));
        $this->assertCount(1, $inserts);
        $this->assertSame(902, $inserts[0]['binds'][0]);
        $this->assertSame($occurrenceDate, $inserts[0]['binds'][1]);
        $this->assertSame(72, $inserts[0]['binds'][2]);
        $this->assertSame('appointment_reminder_no_portal', $inserts[0]['binds'][3]);
    }

    public function testRunDedupesDuplicateOccurrencesWithinSingleRun(): void
    {
        // Finder returns the same (pc_eid, pc_eventDate) twice in one run.
        // Without in-loop dedup, both would send because the bulk-loaded
        // sentKeys map is empty and the DB INSERT IGNORE only dedups the
        // log row after both sends complete.
        $now = new \DateTimeImmutable('2026-05-15 12:00:00');
        $day = $now->modify('+1 day')->format('Y-m-d');
        $this->mockUpcomingAppointments([
            $this->makeAppointment(950, 80, $day, '09:00:00', '+15557770100', 'YES'),
            $this->makeAppointment(950, 80, $day, '09:00:00', '+15557770100', 'YES'),
        ]);
        $this->mockActiveConsent(80, '+15557770100');

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');
        $this->templateService->method('render')->willReturn('Reminder.');
        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->willReturn(['id' => 'msg-dup']);

        $results = $this->service->run($now);

        $this->assertSame(1, $results['sent']);
        $this->assertSame(0, $results['skipped'], 'In-loop dedup is a quiet skip, not an eligibility skip');
    }

    // --- migration failure ---

    public function testRunReturnsFailedResultWhenMigrationThrows(): void
    {
        // Force ensureUpgraded() to throw: legacy table exists (forces
        // lock acquisition), no GET_LOCK mock → lock attempt returns
        // [] → got=0 → false → re-probe still legacy → RuntimeException.
        QueryUtils::setMockResult(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oce_sinch_appointment_reminders'],
            [['TABLE_NAME' => 'oce_sinch_appointment_reminders']]
        );

        // Service should not crash the cron runner — caller has no catch.
        $results = $this->service->run();

        $this->assertSame(1, $results['failed']);
        $this->assertSame(0, $results['sent']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('schema migration failed', $results['errors'][0]);

        // Migration is the failure boundary — purge / appointment-finder
        // / sender must not have run.
        $queries = QueryUtils::getQueries();
        $this->assertCount(
            0,
            array_filter(
                $queries,
                static fn(array $q): bool => str_contains($q['sql'], 'DELETE FROM oce_sinch_appointment_reminders')
            ),
            'Purge must not run when the migration fails'
        );
    }

    // --- cleanup ---

    public function testRunPurgesExpiredReminders(): void
    {
        $this->mockUpcomingAppointments([]);

        $this->service->run();

        $queries = QueryUtils::getQueries();
        $deleteQueries = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'DELETE FROM oce_sinch_appointment_reminders')
                && str_contains($q['sql'], 'INTERVAL 90 DAY')
        );
        $this->assertCount(1, $deleteQueries, 'Expected purge of expired reminders after run');
    }

    // --- Helpers ---

    /**
     * @param list<array{
     *     pc_eid: int,
     *     pc_pid: int,
     *     pc_eventDate: string,
     *     pc_startTime: string,
     *     phone_cell: ?string,
     *     hipaa_allowsms: ?string
     * }> $appointments
     */
    private function mockUpcomingAppointments(array $appointments): void
    {
        // Mutate the existing finder in place so any AppointmentReminderService
        // already constructed with a reference to it (e.g. tests that build a
        // local service with a custom config) sees the new occurrences.
        $this->finder->setOccurrences($appointments);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAppointment(
        int $pcEid,
        int $patientId,
        string $date,
        string $time,
        string $phoneCell = '+15559999999',
        string $hipaaAllowSms = 'YES'
    ): array {
        return [
            'pc_eid' => $pcEid,
            'pc_pid' => $patientId,
            'pc_eventDate' => $date,
            'pc_startTime' => $time,
            'phone_cell' => $phoneCell,
            'hipaa_allowsms' => $hipaaAllowSms,
        ];
    }

    private function mockActiveConsent(int $patientId, string $phoneNumber): void
    {
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [$patientId, $phoneNumber],
            [['opted_in' => true, 'opted_out' => false]]
        );
    }

    /**
     * Assert that a structured skip warning was logged with the expected fields.
     *
     * @param array<string, scalar|null> $extraContext additional context fields to match
     */
    private function assertSkipLogged(int $pcEid, int $patientId, string $reason, array $extraContext = []): void
    {
        $matches = array_filter(
            SystemLogger::getLogs(),
            static fn(array $log): bool => $log['level'] === 'warning'
                && $log['message'] === 'Appointment reminder skipped'
                && ($log['context']['pc_eid'] ?? null) === $pcEid
                && ($log['context']['patient_id'] ?? null) === $patientId
                && ($log['context']['reason'] ?? null) === $reason
        );

        $this->assertCount(
            1,
            $matches,
            sprintf(
                'Expected exactly one "Appointment reminder skipped" warning for pc_eid=%d patient_id=%d reason=%s',
                $pcEid,
                $patientId,
                $reason
            )
        );

        $log = array_values($matches)[0];
        foreach ($extraContext as $key => $expected) {
            $this->assertSame(
                $expected,
                $log['context'][$key] ?? null,
                sprintf('Skip log context "%s" did not match', $key)
            );
        }
    }
}
