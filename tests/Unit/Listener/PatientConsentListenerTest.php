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

use OpenCoreEMR\Modules\SinchConversations\Channel;
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

    // --- onPatientCreated ---

    public function testCreatedWithAllowSmsYesCallsOptIn(): void
    {
        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(
                42,
                '+15551234567',
                PatientConsentListener::CONSENT_METHOD,
                null,
                Channel::SMS,
            );

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithAllowSmsNoIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('optIn');
        $this->consentService->expects($this->never())->method('optOut');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'hipaa_allowsms' => 'NO',
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithBlankAllowSmsIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('optIn');

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => 42,
            'phone_cell' => '+15551234567',
        ]));
    }

    public function testCreatedWithYesButNoPhoneLogsAndSkips(): void
    {
        $this->consentService->expects($this->never())->method('optIn');

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
        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(42, '+15551234567', PatientConsentListener::CONSENT_METHOD, null, Channel::SMS);

        $this->listener->onPatientCreated(new PatientCreatedEvent([
            'pid' => '42',
            'hipaa_allowsms' => 'YES',
            'phone_cell' => '+15551234567',
        ]));
    }

    // --- onPatientUpdated ---

    public function testUpdatedNoToYesCallsOptIn(): void
    {
        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(42, '+15551234567', PatientConsentListener::CONSENT_METHOD, null, Channel::SMS);
        $this->consentService->expects($this->never())->method('optOut');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedBlankToYesCallsOptIn(): void
    {
        $this->consentService->expects($this->once())
            ->method('optIn')
            ->with(42, '+15551234567', PatientConsentListener::CONSENT_METHOD, null, Channel::SMS);

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedYesToNoCallsOptOut(): void
    {
        $this->consentService->expects($this->once())
            ->method('optOut')
            ->with(42, '+15551234567', PatientConsentListener::CONSENT_METHOD, Channel::SMS);
        $this->consentService->expects($this->never())->method('optIn');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'NO', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedYesToYesIsNoop(): void
    {
        $this->consentService->expects($this->never())->method('optIn');
        $this->consentService->expects($this->never())->method('optOut');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
        ));
    }

    public function testUpdatedPayloadMissingFieldIsNoop(): void
    {
        // Partial REST PATCH that doesn't include hipaa_allowsms must not
        // be interpreted as a transition.
        $this->consentService->expects($this->never())->method('optIn');
        $this->consentService->expects($this->never())->method('optOut');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'fname' => 'New First'],
        ));
    }

    public function testUpdatedYesToNoWithMissingPhoneFallsBackToOldPhone(): void
    {
        $this->consentService->expects($this->once())
            ->method('optOut')
            ->with(42, '+15551234567', PatientConsentListener::CONSENT_METHOD, Channel::SMS);

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'YES', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'NO'],
        ));
    }

    public function testUpdatedTransitionWithNoPhoneAtAllLogsAndSkips(): void
    {
        $this->consentService->expects($this->never())->method('optIn');
        $this->consentService->expects($this->never())->method('optOut');

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
        $this->consentService->expects($this->once())->method('optIn');

        $this->listener->onPatientUpdated(new PatientUpdatedEvent(
            ['pid' => 42, 'hipaa_allowsms' => 'no', 'phone_cell' => '+15551234567'],
            ['pid' => 42, 'hipaa_allowsms' => 'yes', 'phone_cell' => '+15551234567'],
        ));
    }
}
