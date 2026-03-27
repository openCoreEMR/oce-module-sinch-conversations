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

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\AppointmentReminderService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
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
        ]));

        $this->templateService = $this->createMock(TemplateService::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->service = new AppointmentReminderService(
            $this->config,
            $this->templateService,
            $this->messageService
        );
    }

    // --- notification hours = 0 ---

    public function testRunReturnsEarlyWhenNotificationHoursIsZero(): void
    {
        $config = new GlobalConfig(new MockGlobalsAccessor([
            'SMS_NOTIFICATION_HOUR' => 0,
        ]));
        $service = new AppointmentReminderService(
            $config,
            $this->templateService,
            $this->messageService
        );

        $results = $service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(0, $results['failed']);
        // No DB queries should be made
        $this->assertSame([], QueryUtils::getQueries());
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
            $this->makeAppointment(100, 42, '2026-04-01', '09:00:00'),
        ]);
        $this->mockNoExistingReminder(100);
        $this->mockPatientPhone(42, '+15559999999');
        $this->mockPatientEligible(42, '+15559999999');

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

        // Verify reminder was recorded
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter(
            $queries,
            static fn(array $q): bool => str_contains($q['sql'], 'INSERT INTO oce_sinch_appointment_reminders')
        );
        $this->assertNotEmpty($insertQueries);
    }

    // --- patient opted out -> no reminder ---

    public function testRunSkipsPatientWhoOptedOut(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(101, 43, '2026-04-01', '10:00:00'),
        ]);
        $this->mockNoExistingReminder(101);
        $this->mockPatientPhone(43, '+15558888888');

        // hipaa_allowsms = YES but opted_out = true
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [43],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
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
    }

    // --- hipaa_allowsms = NO -> no reminder ---

    public function testRunSkipsPatientWithHipaaDisallowSms(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(102, 44, '2026-04-01', '11:00:00'),
        ]);
        $this->mockNoExistingReminder(102);
        $this->mockPatientPhone(44, '+15557777777');

        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [44],
            [['hipaa_allowsms' => 'NO']]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);
    }

    // --- reminder already sent -> no duplicate ---

    public function testRunSkipsAlreadySentReminder(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(103, 45, '2026-04-01', '12:00:00'),
        ]);

        // Existing reminder record
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_appointment_reminders WHERE pc_eid = ?",
            [103],
            [['id' => 1]]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);
    }

    // --- no phone number -> skipped ---

    public function testRunSkipsPatientWithNoPhone(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(104, 46, '2026-04-01', '13:00:00'),
        ]);
        $this->mockNoExistingReminder(104);

        // No phone number
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [46],
            [['phone_cell' => '']]
        );

        $this->templateService->method('getAppointmentReminderTemplateKey')
            ->willReturn('appointment_reminder_no_portal');

        $this->messageService->expects($this->never())->method('sendToPatient');

        $results = $this->service->run();

        $this->assertSame(0, $results['sent']);
        $this->assertSame(1, $results['skipped']);
    }

    // --- send failure -> counted as failed ---

    public function testRunCountsSendFailure(): void
    {
        $this->mockUpcomingAppointments([
            $this->makeAppointment(105, 47, '2026-04-01', '14:00:00'),
        ]);
        $this->mockNoExistingReminder(105);
        $this->mockPatientPhone(47, '+15556666666');
        $this->mockPatientEligible(47, '+15556666666');

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
            $this->makeAppointment(106, 48, '2026-04-01', '15:00:00'),
        ]);
        $this->mockNoExistingReminder(106);
        $this->mockPatientPhone(48, '+15555555555');
        $this->mockPatientEligible(48, '+15555555555');

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
            $this->makeAppointment(200, 50, '2026-04-01', '09:00:00'),
            $this->makeAppointment(201, 51, '2026-04-01', '10:00:00'),
            $this->makeAppointment(202, 52, '2026-04-01', '11:00:00'),
        ]);

        // First: eligible, send succeeds
        $this->mockNoExistingReminder(200);
        $this->mockPatientPhone(50, '+15550001111');
        $this->mockPatientEligible(50, '+15550001111');

        // Second: already reminded
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_appointment_reminders WHERE pc_eid = ?",
            [201],
            [['id' => 5]]
        );

        // Third: eligible, send succeeds
        $this->mockNoExistingReminder(202);
        $this->mockPatientPhone(52, '+15550003333');
        $this->mockPatientEligible(52, '+15550003333');

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
        ]));
        $service = new AppointmentReminderService(
            $config,
            $this->templateService,
            $this->messageService
        );

        $this->mockUpcomingAppointments([
            $this->makeAppointment(300, 60, '2026-04-01', '09:00:00'),
        ]);
        $this->mockNoExistingReminder(300);
        $this->mockPatientPhone(60, '+15554443333');
        $this->mockPatientEligible(60, '+15554443333');

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

    // --- Helpers ---

    /**
     * @param array<int, array<string, mixed>> $appointments
     */
    private function mockUpcomingAppointments(array $appointments): void
    {
        QueryUtils::setMockResult(
            "SELECT e.pc_eid, e.pc_pid, e.pc_eventDate, e.pc_startTime,
                       p.fname, p.lname
                FROM openemr_postcalendar_events e
                JOIN patient_data p ON e.pc_pid = p.pid
                WHERE CONCAT(e.pc_eventDate, ' ', e.pc_startTime) > NOW()
                  AND CONCAT(e.pc_eventDate, ' ', e.pc_startTime) <= DATE_ADD(NOW(), INTERVAL ? HOUR)
                  AND e.pc_apptstatus != 'x'
                  AND e.pc_pid > 0
                ORDER BY e.pc_eventDate, e.pc_startTime",
            [24],
            $appointments
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAppointment(int $pcEid, int $patientId, string $date, string $time): array
    {
        return [
            'pc_eid' => $pcEid,
            'pc_pid' => $patientId,
            'pc_eventDate' => $date,
            'pc_startTime' => $time,
            'fname' => 'Test',
            'lname' => 'Patient',
        ];
    }

    private function mockNoExistingReminder(int $pcEid): void
    {
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_appointment_reminders WHERE pc_eid = ?",
            [$pcEid],
            []
        );
    }

    private function mockPatientPhone(int $patientId, string $phone): void
    {
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [$patientId],
            [['phone_cell' => $phone]]
        );
    }

    private function mockPatientEligible(int $patientId, string $phoneNumber): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [$patientId],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [$patientId, $phoneNumber],
            [['opted_in' => true, 'opted_out' => false]]
        );
    }
}
