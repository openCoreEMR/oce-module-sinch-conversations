<?php

/**
 * Unit tests for ConsentService
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsentServiceTest extends TestCase
{
    private GlobalConfig $config;
    private TemplateService&MockObject $templateService;
    private MessageService&MockObject $messageService;
    private ConsentService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
        ]), new MockConfigFactory());
        $this->templateService = $this->createMock(TemplateService::class);
        $this->messageService = $this->createMock(MessageService::class);

        $this->service = new ConsentService(
            $this->config,
            $this->templateService,
            $this->messageService
        );
    }

    // --- optIn ---

    public function testOptInInsertsConsentAndSendsConfirmation(): void
    {
        $this->templateService->method('render')
            ->with('opt_in_confirmation', $this->anything())
            ->willReturn('Welcome!');

        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(1, '+15551234567', 'Welcome!', new MessageOptions(
                templateKey: 'opt_in_confirmation',
                skipConsentCheck: true,
            ));

        $result = $this->service->optIn(1, '+15551234567', 'web_form', '192.168.1.1');

        $this->assertTrue($result);
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent'));
        $this->assertNotEmpty($insertQueries);

        // Verify hipaa_allowsms is synced to YES
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertNotEmpty($hipaaQueries);
        $hipaaUpdate = array_values($hipaaQueries)[0];
        $this->assertEquals('YES', $hipaaUpdate['binds'][0]);
        $this->assertEquals(1, $hipaaUpdate['binds'][1]);
    }

    public function testOptInContinuesWhenConfirmationFails(): void
    {
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient')
            ->willThrowException(new \RuntimeException('API error'));

        // Should not throw — opt-in succeeds but returns false to indicate confirmation failed
        $result = $this->service->optIn(1, '+15551234567', 'web_form');

        $this->assertFalse($result);

        // Consent record is still persisted even when confirmation fails
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent'));
        $this->assertNotEmpty($insertQueries);

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter($logs, fn($log) => $log['level'] === 'error');
        $this->assertNotEmpty($errorLogs);
    }

    public function testOptInSkipsUnparseablePhone(): void
    {
        $this->messageService->expects($this->never())->method('sendToPatient');

        $result = $this->service->optIn(1, 'not-a-phone', 'web_form');

        $this->assertFalse($result);

        $queries = QueryUtils::getQueries();
        $consentQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'oce_sinch_patient_consent')
        );
        $this->assertEmpty($consentQueries);
    }

    // --- sendOptInConfirmation ---

    public function testSendOptInConfirmationNormalizesPhoneBeforeSend(): void
    {
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->expects($this->once())
            ->method('sendToPatient')
            ->with(1, '+15551234567', 'Welcome!', $this->anything());

        // Pass a chart-format phone; the service must normalize before
        // delegating to MessageService so the API receives E.164.
        $this->service->sendOptInConfirmation(1, '(555) 123-4567');
    }

    public function testSendOptInConfirmationSkipsUnparseablePhone(): void
    {
        $this->messageService->expects($this->never())->method('sendToPatient');

        $this->service->sendOptInConfirmation(1, 'not-a-phone');

        $logs = SystemLogger::getLogs();
        $warnings = array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'warning'
                && str_contains($log['message'], 'unparseable phone')
        );
        $this->assertNotEmpty($warnings);
    }

    // --- optOut ---

    public function testOptOutSmsUpsertsAndSyncsHipaaAllowSms(): void
    {
        $this->service->optOut(1, '+15551234567', 'sms_stop', Channel::SMS);

        $queries = QueryUtils::getQueries();
        // optOut now upserts so a STOP-first patient (no prior module row)
        // still gets a persistent opt-out record.
        $upsertQueries = array_values(array_filter(
            $queries,
            fn(array $q): bool => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent')
                && str_contains($q['sql'], 'ON DUPLICATE KEY UPDATE')
                && str_contains($q['sql'], 'opted_out')
        ));
        $this->assertNotEmpty($upsertQueries);
        $upsert = $upsertQueries[0];
        $this->assertSame(1, $upsert['binds'][0]);
        $this->assertSame('+15551234567', $upsert['binds'][1]);
        $this->assertSame('sms_stop', $upsert['binds'][2]);

        // Verify hipaa_allowsms is synced to NO for SMS channel
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertNotEmpty($hipaaQueries);
        $hipaaUpdate = array_values($hipaaQueries)[0];
        $this->assertEquals('NO', $hipaaUpdate['binds'][0]);
        $this->assertEquals(1, $hipaaUpdate['binds'][1]);
    }

    public function testOptOutSkipsUnparseablePhone(): void
    {
        $this->service->optOut(1, 'not-a-phone', 'sms_stop');

        $queries = QueryUtils::getQueries();
        $consentQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'oce_sinch_patient_consent')
        );
        $this->assertEmpty($consentQueries);

        $logs = SystemLogger::getLogs();
        $warnings = array_filter($logs, fn($log) => $log['level'] === 'warning');
        $this->assertNotEmpty($warnings);
    }

    public function testOptOutDefaultsToSmsChannel(): void
    {
        $this->service->optOut(1, '+15551234567', 'sms_stop');

        $queries = QueryUtils::getQueries();

        // Default channel is SMS, so hipaa_allowsms should be synced
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertNotEmpty($hipaaQueries);
        $hipaaUpdate = array_values($hipaaQueries)[0];
        $this->assertEquals('NO', $hipaaUpdate['binds'][0]);
    }

    public function testOptOutPersistsRowEvenWhenNoPriorConsentExists(): void
    {
        // Under chart-as-source-of-truth, many patients have no module row
        // until their first opt-out arrives. The upsert must record the
        // opt-out so a later NO->YES chart toggle doesn't silently undo it.
        $this->service->optOut(7, '+15554443333', 'sms_stop', Channel::SMS);

        $upsertQueries = array_values(array_filter(
            QueryUtils::getQueries(),
            fn(array $q): bool => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent')
                && str_contains($q['sql'], 'opted_out')
                && str_contains($q['sql'], 'ON DUPLICATE KEY UPDATE')
        ));
        $this->assertCount(1, $upsertQueries);
        $this->assertSame(7, $upsertQueries[0]['binds'][0]);
        $this->assertSame('+15554443333', $upsertQueries[0]['binds'][1]);
        $this->assertSame('sms_stop', $upsertQueries[0]['binds'][2]);
    }

    public function testOptOutWhatsAppDoesNotSyncHipaaAllowSms(): void
    {
        $this->service->optOut(1, '+15551234567', 'sinch_WHATSAPP', Channel::WHATSAPP);

        $queries = QueryUtils::getQueries();

        // Consent record is still upserted regardless of channel
        $upsertQueries = array_filter(
            $queries,
            fn(array $q): bool => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent')
                && str_contains($q['sql'], 'ON DUPLICATE KEY UPDATE')
                && str_contains($q['sql'], 'opted_out')
        );
        $this->assertNotEmpty($upsertQueries);

        // hipaa_allowsms must NOT be touched for non-SMS channels
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertEmpty($hipaaQueries);
    }

    public function testOptOutRcsDoesNotSyncHipaaAllowSms(): void
    {
        $this->service->optOut(1, '+15551234567', 'sinch_RCS', Channel::RCS);

        $queries = QueryUtils::getQueries();

        // hipaa_allowsms must NOT be touched for non-SMS channels
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertEmpty($hipaaQueries);
    }

    // --- optIn channel-awareness ---

    public function testOptInWhatsAppDoesNotSyncHipaaAllowSms(): void
    {
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient');

        $this->service->optIn(1, '+15551234567', 'sinch_WHATSAPP', null, Channel::WHATSAPP);

        $queries = QueryUtils::getQueries();

        // Consent record should still be inserted
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent'));
        $this->assertNotEmpty($insertQueries);

        // hipaa_allowsms must NOT be touched for non-SMS channels
        $hipaaQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE patient_data SET hipaa_allowsms'));
        $this->assertEmpty($hipaaQueries);
    }

    // --- getConsent ---

    public function testGetConsentReturnsRecord(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            [['patient_id' => 1, 'phone_number' => '+15551234567', 'opted_in' => true]]
        );

        $result = $this->service->getConsent(1, '+15551234567');

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['patient_id']);
    }

    public function testGetConsentReturnsNullWhenNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15551234567'],
            []
        );

        $this->assertNull($this->service->getConsent(1, '+15551234567'));
    }

    // --- setCarrierBlock ---

    public function testSetCarrierBlockInsertsOrUpdatesRecord(): void
    {
        $this->service->setCarrierBlock(1, '+15551234567', 'SMPP error 255');

        $queries = QueryUtils::getQueries();
        $upsertQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent')
                && str_contains($q['sql'], 'ON DUPLICATE KEY UPDATE')
                && str_contains($q['sql'], 'carrier_blocked = TRUE')
        );
        $this->assertNotEmpty($upsertQueries);

        $upsert = array_values($upsertQueries)[0];
        $this->assertEquals(1, $upsert['binds'][0]);
        $this->assertEquals('+15551234567', $upsert['binds'][1]);
        $this->assertEquals('SMPP error 255', $upsert['binds'][2]);
    }

    public function testSetCarrierBlockSkipsUnparseablePhone(): void
    {
        $this->service->setCarrierBlock(1, 'not-a-phone', 'SMPP error 255');

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'carrier_blocked')
        );
        $this->assertEmpty($updateQueries);

        $logs = SystemLogger::getLogs();
        $warnings = array_filter($logs, fn($log) => $log['level'] === 'warning');
        $this->assertNotEmpty($warnings);
    }

    // --- clearCarrierBlock ---

    public function testClearCarrierBlockUpdatesRecord(): void
    {
        $this->service->clearCarrierBlock(1, '+15551234567');

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_patient_consent')
                && str_contains($q['sql'], 'carrier_blocked = FALSE')
        );
        $this->assertNotEmpty($updateQueries);

        $update = array_values($updateQueries)[0];
        $this->assertEquals(1, $update['binds'][0]);
        $this->assertEquals('+15551234567', $update['binds'][1]);
    }

    public function testClearCarrierBlockSkipsUnparseablePhone(): void
    {
        $this->service->clearCarrierBlock(1, 'not-a-phone');

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'carrier_blocked')
        );
        $this->assertEmpty($updateQueries);
    }

    // --- getCarrierBlock ---

    public function testGetCarrierBlockReturnsDataWhenBlocked(): void
    {
        QueryUtils::setMockResult(
            "SELECT carrier_blocked_at, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ? AND carrier_blocked = TRUE",
            [1, '+15551234567'],
            [['carrier_blocked_at' => '2026-04-03 10:00:00', 'carrier_block_reason' => 'SMPP error 255']]
        );

        $result = $this->service->getCarrierBlock(1, '+15551234567');

        $this->assertNotNull($result);
        $this->assertEquals('2026-04-03 10:00:00', $result['carrier_blocked_at']);
        $this->assertEquals('SMPP error 255', $result['carrier_block_reason']);
    }

    public function testGetCarrierBlockReturnsNullWhenNotBlocked(): void
    {
        QueryUtils::setMockResult(
            "SELECT carrier_blocked_at, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ? AND carrier_blocked = TRUE",
            [1, '+15551234567'],
            []
        );

        $this->assertNull($this->service->getCarrierBlock(1, '+15551234567'));
    }

    // --- optIn clears carrier_blocked (re-subscribe deadlock fix) ---

    public function testOptInClearsCarrierBlockColumnsToBreakResubscribeDeadlock(): void
    {
        // Without this, a STOP→START flow leaves the row in
        // (opted_out=FALSE, carrier_blocked=TRUE) — and the eligibility gate
        // refuses every send forever because no other code path clears the
        // block from a re-subscribe signal.
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient');

        $this->service->optIn(1, '+15551234567', 'sms_start');

        $upsertQueries = array_values(array_filter(
            QueryUtils::getQueries(),
            fn(array $q): bool => str_contains($q['sql'], 'INSERT INTO oce_sinch_patient_consent')
                && str_contains($q['sql'], 'opted_in')
        ));
        $this->assertNotEmpty($upsertQueries);
        $sql = $upsertQueries[0]['sql'];
        $this->assertStringContainsString('carrier_blocked = FALSE', $sql);
        $this->assertStringContainsString('carrier_blocked_at = NULL', $sql);
    }

    public function testOptInLogsAuditBreadcrumbWhenClearingPriorCarrierBlock(): void
    {
        // Forensics: months later, an oncall debugging "why did this patient
        // start receiving sends after we blocked them?" must be able to see
        // that an opt-in cleared a known prior block (and what the original
        // block reason was) without having to reconstruct from row diffs.
        QueryUtils::setMockResult(
            "SELECT carrier_blocked_at, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ? AND carrier_blocked = TRUE",
            [1, '+15551234567'],
            [['carrier_blocked_at' => '2026-04-03 10:00:00', 'carrier_block_reason' => 'smpp_255']]
        );
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient');

        $this->service->optIn(1, '+15551234567', 'sms_start');

        $logs = SystemLogger::getLogs();
        $clearedLogs = array_values(array_filter(
            $logs,
            fn(array $log): bool => $log['level'] === 'info'
                && str_contains($log['message'], 'cleared prior carrier block')
        ));
        $this->assertNotEmpty($clearedLogs);
        $this->assertSame('2026-04-03 10:00:00', $clearedLogs[0]['context']['prior_carrier_blocked_at']);
        $this->assertSame('smpp_255', $clearedLogs[0]['context']['prior_carrier_block_reason']);
    }

    public function testOptInDoesNotLogCarrierClearWhenNoPriorBlock(): void
    {
        // The audit breadcrumb must not fire on every opt-in — only when
        // there was actually a block to clear. Otherwise the log noise
        // would drown the signal.
        QueryUtils::setMockResult(
            "SELECT carrier_blocked_at, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ? AND carrier_blocked = TRUE",
            [1, '+15551234567'],
            []
        );
        $this->templateService->method('render')->willReturn('Welcome!');
        $this->messageService->method('sendToPatient');

        $this->service->optIn(1, '+15551234567', 'web_form');

        $logs = SystemLogger::getLogs();
        $clearedLogs = array_filter(
            $logs,
            fn(array $log): bool => str_contains($log['message'], 'cleared prior carrier block')
        );
        $this->assertEmpty($clearedLogs);
    }

    public function testGetCarrierBlockSkipsUnparseablePhone(): void
    {
        $this->assertNull($this->service->getCarrierBlock(1, 'not-a-phone'));

        $logs = SystemLogger::getLogs();
        $warnings = array_filter($logs, fn($log) => $log['level'] === 'warning');
        $this->assertNotEmpty($warnings);
    }
}
