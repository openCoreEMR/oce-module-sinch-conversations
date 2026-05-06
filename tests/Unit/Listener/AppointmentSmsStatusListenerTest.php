<?php

/**
 * Unit tests for AppointmentSmsStatusListener
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Listener;

use OpenCoreEMR\Modules\SinchConversations\ConsentState;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusListener;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppointmentSmsStatusListenerTest extends TestCase
{
    private MessageService&MockObject $messageService;
    private AppointmentSmsStatusListener $listener;

    protected function setUp(): void
    {
        SystemLogger::clearLogs();
        $_REQUEST = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $this->messageService = $this->createMock(MessageService::class);
        $this->listener = new AppointmentSmsStatusListener(
            $this->messageService,
            new EligibilityAlertRenderer()
        );
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
        $_SESSION = [];
    }

    public function testRendersEligibleAlertWhenDiagnoseReturnsCanSend(): void
    {
        $this->messageService->method('diagnose')->with(42)->willReturn([
            'can_send' => true,
            'reason' => null,
            'context' => [],
            'phone' => '+15551112222',
        ]);

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('eligible to receive SMS', $html);
        $this->assertStringNotContainsString('Reason:', $html);
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $html);
    }

    public function testRendersNotEligibleAlertWithReasonForOptedOut(): void
    {
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => false,
            'reason' => SkipReason::ModuleOptOut->value,
            'context' => ['consent_state' => ConsentState::OptedOut->value],
            'phone' => '+15551112222',
        ]);

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('not eligible', $html);
        $this->assertStringContainsString('opted out', $html);
    }

    public function testRendersWillNotSendAlertForHipaaDisallows(): void
    {
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => false,
            'reason' => SkipReason::HipaaDisallowsSms->value,
            'context' => ['hipaa_allowsms' => 'NO'],
            'phone' => null,
        ]);

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringContainsString('Allow SMS', $html);
    }

    public function testRendersWillNotSendAlertForCarrierBlock(): void
    {
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => false,
            'reason' => SkipReason::CarrierBlocked->value,
            'context' => ['carrier_block_reason' => 'smpp_255'],
            'phone' => '+15551112222',
        ]);

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringContainsString('carrier', $html);
    }

    public function testRendersEmptyPlaceholderWhenAppointmentHasNoPatientId(): void
    {
        $this->messageService->expects($this->never())->method('diagnose');

        $html = $this->captureRender(['pc_pid' => 0]);

        // Empty placeholder is intentional — the JS layer needs a target
        // div to populate when the user picks a patient via the popup.
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    public function testFallsBackToRequestPatientIdWhenApptRowEmpty(): void
    {
        // Mirrors interface/main/calendar/add_edit_event.php new-appointment
        // path: $row is empty, the preselected pid arrived via $_REQUEST.
        $_REQUEST['patientid'] = '7';
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(7)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $html = $this->captureRender([]);

        $this->assertStringContainsString('alert-success', $html);
    }

    public function testFallsBackToSessionPidWhenRequestEmpty(): void
    {
        // Last-resort fallback for staff who navigated here straight from
        // a chart with $_SESSION['pid'] set but no $_REQUEST['patientid'].
        $_SESSION['pid'] = '11';
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(11)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $html = $this->captureRender([]);

        $this->assertStringContainsString('alert-success', $html);
    }

    public function testRequestTakesPrecedenceOverSession(): void
    {
        // $_REQUEST is the explicit "this appointment is for" signal from
        // the calling page; $_SESSION is just whoever was last viewed.
        $_REQUEST['patientid'] = '7';
        $_SESSION['pid'] = '11';
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(7)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $this->captureRender([]);
    }

    public function testInvalidRequestPatientIdFallsThroughToSession(): void
    {
        $_REQUEST['patientid'] = 'not-a-number';
        $_SESSION['pid'] = '11';
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(11)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $this->captureRender([]);
    }

    public function testNonPositiveRequestPatientIdIsIgnored(): void
    {
        $_REQUEST['patientid'] = '0';
        $this->messageService->expects($this->never())->method('diagnose');

        $html = $this->captureRender([]);

        // Empty placeholder still rendered so JS has a target.
        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $html);
    }

    public function testAcceptsStringPidFromAppointmentRow(): void
    {
        // OpenEMR's calendar row arrays often carry pids as strings.
        $this->messageService->expects($this->once())
            ->method('diagnose')
            ->with(42)
            ->willReturn([
                'can_send' => true,
                'reason' => null,
                'context' => [],
                'phone' => '+15551112222',
            ]);

        $this->captureRender(['pc_pid' => '42']);
    }

    public function testEscapesUnknownReasonValueToPreventXss(): void
    {
        // Future SkipReason values pass through with the raw token. Make
        // sure the renderer escapes that token instead of letting it land
        // in the page as raw HTML.
        $this->messageService->method('diagnose')->willReturn([
            'can_send' => false,
            'reason' => '<script>alert(1)</script>',
            'context' => [],
            'phone' => null,
        ]);

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testDiagnoseExceptionIsLoggedAndSwallowed(): void
    {
        // A transient diagnose() failure (DB blip, misconfigured module)
        // must not bubble out through the calendar render hook and break
        // the appointment form. The empty placeholder is emitted so the JS
        // layer can retry on a patient swap; the failure lands in the log
        // for ops follow-up.
        $this->messageService->method('diagnose')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $html = $this->captureRender(['pc_pid' => 42]);

        $this->assertStringContainsString(EligibilityAlertRenderer::PLACEHOLDER_ID, $html);
        $this->assertStringNotContainsString('alert-', $html);

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'error'
                && str_contains($log['message'], 'Failed to diagnose SMS eligibility')
        );
        $this->assertNotEmpty($errorLogs);
    }

    /**
     * @param array<array-key, mixed> $appt
     */
    private function captureRender(array $appt): string
    {
        ob_start();
        $this->listener->onRenderBelowPatient(new AppointmentRenderEvent($appt));
        return (string) ob_get_clean();
    }
}
