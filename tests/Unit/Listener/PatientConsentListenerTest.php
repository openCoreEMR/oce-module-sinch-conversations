<?php

/**
 * Unit tests for PatientConsentListener
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Listener;

use OpenCoreEMR\Modules\SinchConversations\Listener\PatientConsentListener;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PatientConsentListenerTest extends TestCase
{
    private ConsentService&MockObject $consentService;
    private PatientConsentListener $listener;

    protected function setUp(): void
    {
        SystemLogger::clearLogs();
        $this->consentService = $this->createMock(ConsentService::class);
        $this->listener = new PatientConsentListener($this->consentService);
    }

    /**
     * Default mock: no module-side consent record exists. The listener calls
     * getConsent() before sending the welcome SMS to detect a stale opt-out.
     */
    private function mockNoExistingConsent(): void
    {
        $this->consentService->method('getConsent')->willReturn(null);
    }

    // --- onPatientCreated ---

    public function testCreatedWithAllowSmsYesSendsWelcomeSms(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())
            ->method('sendOptInConfirmation')
            ->with(42, '+15551234567');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithAllowSmsNoIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'NO',
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithBlankAllowSmsIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithYesButNoPhoneLogsAndSkips(): void
    {
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '',
        ]));

        $logs = SystemLogger::getLogs();
        $infoLogs = array_filter($logs, fn($log) => $log['level'] === 'info');
        $this->assertNotEmpty($infoLogs);
    }

    public function testCreatedWithYesAndStringPidIsAccepted(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())
            ->method('sendOptInConfirmation')
            ->with(42, '+15551234567');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => '42',
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedSkipsWelcomeWhenStaleOptOutPresent(): void
    {
        // A patient created with chart YES who already has a module-side
        // opt-out (e.g., they previously texted STOP and were re-created
        // through some unusual path) should not get a welcome SMS — staff
        // would have to clear the explicit opt-out deliberately first.
        $this->consentService->method('getConsent')->willReturn([
            'opted_in' => false,
            'opted_out' => true,
        ]);
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));

        $logs = SystemLogger::getLogs();
        $infoLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'Skipped welcome SMS')
        );
        $this->assertNotEmpty($infoLogs);
    }

    // --- onPatientUpdated ---

    public function testUpdatedNoToYesSendsWelcomeSms(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())
            ->method('sendOptInConfirmation')
            ->with(42, '+15551234567');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedBlankToYesSendsWelcomeSms(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())
            ->method('sendOptInConfirmation')
            ->with(42, '+15551234567');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedYesToNoIsNoop(): void
    {
        // Under chart-as-source-of-truth, chart NO already gates future
        // sends — the listener does not need to mirror this into the module
        // table. No welcome SMS, no opt-out write.
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');
        $this->consentService->expects($this->never())->method('getConsent');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedYesToYesIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedPayloadMissingFieldIsNoop(): void
    {
        // Partial REST PATCH that doesn't include hipaa_allowsms must not
        // be interpreted as a transition.
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'fname' => 'New First'],
        ));
    }

    public function testUpdatedNoToYesWithMissingPhoneFallsBackToOldPhone(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())
            ->method('sendOptInConfirmation')
            ->with(42, '+15551234567');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES'],
        ));
    }

    public function testUpdatedTransitionWithNoPhoneAtAllLogsAndSkips(): void
    {
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => ''],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => ''],
        ));

        $logs = SystemLogger::getLogs();
        $infoLogs = array_filter($logs, fn($log) => $log['level'] === 'info');
        $this->assertNotEmpty($infoLogs);
    }

    public function testUpdatedYesValueIsCaseInsensitive(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->expects($this->once())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'no', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'yes', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedNoToYesSkipsWelcomeWhenStaleOptOutPresent(): void
    {
        $this->consentService->method('getConsent')->willReturn([
            'opted_in' => false,
            'opted_out' => true,
        ]);
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));

        $logs = SystemLogger::getLogs();
        $infoLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'Skipped welcome SMS')
        );
        $this->assertNotEmpty($infoLogs);
    }

    public function testUpdatedNoToYesSkipsWelcomeWhenCarrierBlockPresent(): void
    {
        // sendOptInConfirmation() bypasses assertPatientEligible() via
        // skipConsentCheck=true, so the welcome path must independently
        // honor the carrier_blocked flag — otherwise a chart toggle on a
        // carrier-blocked number would push a send the rest of the module
        // already refuses.
        $this->consentService->method('getConsent')->willReturn([
            'opted_in' => false,
            'opted_out' => false,
            'carrier_blocked' => true,
            'carrier_block_reason' => 'smpp_255',
        ]);
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));

        $logs = SystemLogger::getLogs();
        $infoLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'carrier block')
        );
        $this->assertNotEmpty($infoLogs);
    }

    public function testUpdatedNoToYesLogsCarrierBlockWhenBothFlagsSet(): void
    {
        // Steady state: setCarrierBlock() then optOut() leaves both flags
        // TRUE. The listener must log the more specific carrier-block
        // reason (with carrier_block_reason context), not the generic
        // module-opt-out skip.
        $this->consentService->method('getConsent')->willReturn([
            'opted_in' => false,
            'opted_out' => true,
            'carrier_blocked' => true,
            'carrier_block_reason' => 'smpp_255',
        ]);
        $this->consentService->expects($this->never())->method('sendOptInConfirmation');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));

        $logs = SystemLogger::getLogs();
        $carrierLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'carrier block')
        );
        $optOutLogs = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'opt-out')
        );
        $this->assertNotEmpty($carrierLogs);
        $this->assertEmpty($optOutLogs);
    }

    public function testWelcomeSmsFailureIsLoggedNotPropagated(): void
    {
        $this->mockNoExistingConsent();
        $this->consentService->method('sendOptInConfirmation')
            ->willThrowException(new \RuntimeException('API timeout'));

        // Should not throw — listener swallows so a downstream messaging
        // failure does not roll back the chart save.
        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter($logs, fn(array $log): bool => $log['level'] === 'error');
        $this->assertNotEmpty($errorLogs);
    }
}
