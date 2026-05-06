<?php

/**
 * Unit tests for MessageService
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
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MessageServiceTest extends TestCase
{
    private GlobalConfig $config;
    private ConversationApiClient&MockObject $apiClient;
    private MessageService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]), new MockConfigFactory());
        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->service = new MessageService($this->config, $this->apiClient);
    }

    /**
     * Set up mock results so patient passes eligibility checks
     */
    private function mockPatientEligible(int $patientId, string $phoneNumber): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [$patientId],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [$patientId, $phoneNumber],
            [['opted_in' => true, 'opted_out' => false]]
        );
    }

    /**
     * Mock an existing conversation for a patient
     */
    private function mockExistingConversation(int $patientId, string $conversationId): void
    {
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE patient_id = ? AND channel = 'SMS'",
            [$patientId],
            [['conversation_id' => $conversationId]]
        );
    }

    /**
     * Mock no existing conversation for a patient
     */
    private function mockNoConversation(int $patientId): void
    {
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE patient_id = ? AND channel = 'SMS'",
            [$patientId],
            []
        );
    }

    // --- consent and hipaa gating ---

    public function testSendToPatientThrowsWhenHipaaDisallowsSms(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'NO']]
        );

        try {
            $this->service->sendToPatient(1, '+15559999999', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('hipaa_allowsms is not YES', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'hipaa_disallows_sms', ['hipaa_allowsms' => 'NO']);
    }

    public function testSendToPatientThrowsWhenHipaaFieldMissing(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            []
        );

        try {
            $this->service->sendToPatient(1, '+15559999999', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('hipaa_allowsms is not YES', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'hipaa_disallows_sms', ['hipaa_allowsms' => 'unset']);
    }

    public function testSendToPatientAllowsWhenChartYesAndNoModuleRow(): void
    {
        // Under chart-as-source-of-truth: chart YES + absence of any module
        // exception row = OK to send. Pre-flip behavior required an explicit
        // opted_in row; this test pins the new positive case.
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            []
        );
        $this->mockExistingConversation(1, 'conv-allow');

        $this->apiClient->expects($this->once())
            ->method('sendMessageByChannelIdentity')
            ->willReturn(['id' => 'msg-allow']);

        $result = $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $this->assertSame('msg-allow', $result['id']);
    }

    public function testSendToPatientThrowsWhenOptedOut(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        try {
            $this->service->sendToPatient(1, '+15559999999', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('explicitly opted out', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'module_opt_out', ['consent_state' => 'opted_out']);
    }

    public function testSendToPatientThrowsWhenCarrierBlockedEvenWithoutOptOut(): void
    {
        // setCarrierBlock() writes opted_in=FALSE, opted_out=FALSE,
        // carrier_blocked=TRUE before the paired optOut() call. If the
        // optOut() write hasn't happened (or failed), the row exists with
        // carrier_blocked=TRUE / opted_out=FALSE — sends must still be
        // blocked, and the carrier_block_reason must surface in the log.
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            [[
                'opted_in' => false,
                'opted_out' => false,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'smpp_255',
            ]]
        );

        try {
            $this->service->sendToPatient(1, '+15559999999', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('carrier-blocked', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'carrier_blocked', ['carrier_block_reason' => 'smpp_255']);
    }

    public function testSendToPatientReportsCarrierBlockedWhenBothFlagsSet(): void
    {
        // Steady state of the carrier-block flow: setCarrierBlock() then
        // optOut() leaves opted_out=TRUE AND carrier_blocked=TRUE. The
        // gate must report CarrierBlocked, not ModuleOptOut — the carrier
        // block is the more specific cause and reporting opt-out would
        // mask the carrier_block_reason context.
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            [[
                'opted_in' => false,
                'opted_out' => true,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'smpp_255',
            ]]
        );

        try {
            $this->service->sendToPatient(1, '+15559999999', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('carrier-blocked', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'carrier_blocked', ['carrier_block_reason' => 'smpp_255']);
    }

    public function testSendToPatientLogsBlockForUnparseablePhone(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );

        try {
            $this->service->sendToPatient(1, 'abcdef', 'Hello');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unparseable phone number', $e->getMessage());
        }

        $this->assertBlockLogged(1, 'unparseable_phone', ['phone_last4' => '']);
    }

    public function testSendToPatientSkipsConsentCheckWhenOptionSet(): void
    {

        $this->mockExistingConversation(1, 'conv-123');

        $this->apiClient->method('sendMessageByChannelIdentity')
            ->willReturn(['id' => 'msg-skip']);

        $result = $this->service->sendToPatient(1, '+15559999999', 'Hello', new MessageOptions(
            skipConsentCheck: true,
        ));

        $this->assertEquals('msg-skip', $result['id']);
    }

    // --- sendToPatient ---

    public function testSendToPatientWithExistingConversation(): void
    {
        $this->mockPatientEligible(1, '+15559999999');

        $this->mockExistingConversation(1, 'conv-123');

        $this->apiClient->expects($this->once())
            ->method('sendMessageByChannelIdentity')
            ->with('+15559999999', 'Hello', 'SMS', $this->anything())
            ->willReturn(['id' => 'msg-001', 'status' => 'QUEUED']);

        $result = $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $this->assertEquals('msg-001', $result['id']);

        $queries = QueryUtils::getQueries();
        $insertMsg = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_messages'));
        $this->assertNotEmpty($insertMsg);
    }

    public function testSendToPatientCreatesConversationWhenNoneExists(): void
    {
        $this->mockPatientEligible(1, '+15559999999');

        $this->mockNoConversation(1);

        $this->apiClient->method('sendMessageByChannelIdentity')
            ->willReturn(['id' => 'msg-002']);

        $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $queries = QueryUtils::getQueries();
        $insertConv = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_conversations'));
        $this->assertNotEmpty($insertConv);
    }

    public function testSendToPatientPassesChannelToApi(): void
    {
        $this->mockPatientEligible(1, '+15559999999');

        $this->mockExistingConversation(1, 'conv-123');

        $this->apiClient->expects($this->once())
            ->method('sendMessageByChannelIdentity')
            ->with('+15559999999', 'Hello', 'SMS', $this->anything())
            ->willReturn(['id' => 'msg-003']);

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientThrowsOnApiFailure(): void
    {
        $this->mockPatientEligible(1, '+15559999999');

        $this->mockExistingConversation(1, 'conv-123');

        $this->apiClient->method('sendMessageByChannelIdentity')
            ->willThrowException(new \RuntimeException('API timeout'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Failed to send message');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    // --- sendBatch ---

    public function testSendBatchCountsSuccessAndFailure(): void
    {
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['phone_cell' => '+15551111111']]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [2],
            []
        );

        $this->mockPatientEligible(1, '+15551111111');

        $this->mockExistingConversation(1, 'conv-1');
        $this->apiClient->method('sendMessageByChannelIdentity')->willReturn(['id' => 'msg-batch']);

        $results = $this->service->sendBatch([1, 2], 'Batch message');

        $this->assertEquals(1, $results['sent']);
        $this->assertEquals(1, $results['failed']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('No phone number', $results['errors'][0]);
    }

    public function testSendBatchSkipsIneligiblePatient(): void
    {
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['phone_cell' => '+15551111111']]
        );
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'NO']]
        );

        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [2],
            [['phone_cell' => '+15552222222']]
        );
        $this->mockPatientEligible(2, '+15552222222');

        $this->mockExistingConversation(2, 'conv-2');
        $this->apiClient->method('sendMessageByChannelIdentity')->willReturn(['id' => 'msg-batch-2']);

        $results = $this->service->sendBatch([1, 2], 'Batch message');

        $this->assertEquals(1, $results['sent']);
        $this->assertEquals(1, $results['failed']);
        $this->assertStringContainsString('hipaa_allowsms', $results['errors'][0]);
    }

    // --- sendBatch dedup (issue #39) ---

    public function testSendBatchDedupsByPhoneAndMessage(): void
    {
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [10],
            [['phone_cell' => '+15553333333']]
        );
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [11],
            [['phone_cell' => '+15553333333']]
        );

        $this->mockPatientEligible(10, '+15553333333');

        $this->mockExistingConversation(10, 'conv-10');

        $this->apiClient->expects($this->once())
            ->method('sendMessageByChannelIdentity')
            ->willReturn(['id' => 'msg-dedup']);

        $results = $this->service->sendBatch([10, 11], 'Office closed today');

        $this->assertEquals(1, $results['sent']);
        $this->assertEquals(0, $results['failed']);
        $this->assertEquals(1, $results['skipped']);
    }

    public function testSendBatchNormalizesUserEnteredPhones(): void
    {
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['phone_cell' => '(555) 444-5555']]
        );

        $this->mockPatientEligible(1, '+15554445555');

        $this->mockExistingConversation(1, 'conv-norm');
        $this->apiClient->method('sendMessageByChannelIdentity')->willReturn(['id' => 'msg-norm']);

        $results = $this->service->sendBatch([1], 'Test');

        $this->assertEquals(1, $results['sent']);
    }

    // --- diagnose ---

    public function testDiagnoseReturnsCanSendWhenChartYesAndNoModuleException(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '(555) 111-2222']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [42, '+15551112222'],
            []
        );

        $verdict = $this->service->diagnose(42);

        $this->assertTrue($verdict['can_send']);
        $this->assertNull($verdict['reason']);
        $this->assertSame('+15551112222', $verdict['phone']);
    }

    public function testDiagnoseReturnsHipaaDisallowsWhenChartNo(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'NO', 'phone_cell' => '+15551112222']]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('hipaa_disallows_sms', $verdict['reason']);
        $this->assertSame('NO', $verdict['context']['hipaa_allowsms']);
    }

    public function testDiagnoseReturnsHipaaDisallowsWhenChartUnset(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            []
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('hipaa_disallows_sms', $verdict['reason']);
        $this->assertSame('unset', $verdict['context']['hipaa_allowsms']);
    }

    public function testDiagnoseReturnsMissingPhoneWhenChartHasNoCell(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '   ']]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('missing_phone', $verdict['reason']);
        $this->assertNull($verdict['phone']);
    }

    public function testDiagnoseReturnsUnparseablePhoneWhenChartCellInvalid(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => 'not-a-phone']]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('unparseable_phone', $verdict['reason']);
    }

    public function testDiagnoseReturnsModuleOptOutWhenExceptionRecorded(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '+15551112222']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [42, '+15551112222'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('module_opt_out', $verdict['reason']);
        $this->assertSame('opted_out', $verdict['context']['consent_state']);
    }

    public function testDiagnoseReturnsCarrierBlockedWithReason(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '+15551112222']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [42, '+15551112222'],
            [[
                'opted_in' => false,
                'opted_out' => false,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'smpp_255',
            ]]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('carrier_blocked', $verdict['reason']);
        $this->assertSame('smpp_255', $verdict['context']['carrier_block_reason']);
    }

    public function testDiagnoseReportsCarrierBlockedWhenBothFlagsSet(): void
    {
        // Steady-state row from the carrier-block flow: both flags TRUE.
        // diagnose() must surface the carrier_block reason on the calendar
        // verdict surface, not collapse it into module_opt_out.
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '+15551112222']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [42, '+15551112222'],
            [[
                'opted_in' => false,
                'opted_out' => true,
                'carrier_blocked' => true,
                'carrier_block_reason' => 'smpp_255',
            ]]
        );

        $verdict = $this->service->diagnose(42);

        $this->assertFalse($verdict['can_send']);
        $this->assertSame('carrier_blocked', $verdict['reason']);
        $this->assertSame('smpp_255', $verdict['context']['carrier_block_reason']);
    }

    public function testDiagnoseUsesExplicitPhoneWhenProvided(): void
    {
        // When the caller passes a phone, diagnose() must check that pair
        // rather than the chart's phone_cell. This covers send paths that
        // target a non-chart number.
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?",
            [42],
            [['hipaa_allowsms' => 'YES', 'phone_cell' => '+15550000000']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [42, '+15559998888'],
            []
        );

        $verdict = $this->service->diagnose(42, '+15559998888');

        $this->assertTrue($verdict['can_send']);
        $this->assertSame('+15559998888', $verdict['phone']);
    }

    /**
     * Assert that a structured block warning was logged with the expected fields.
     *
     * @param array<string, scalar|null> $extraContext additional context fields to match
     */
    private function assertBlockLogged(int $patientId, string $reason, array $extraContext = []): void
    {
        $matches = array_filter(
            SystemLogger::getLogs(),
            static fn(array $log): bool => $log['level'] === 'warning'
                && $log['message'] === 'Message send blocked'
                && ($log['context']['patient_id'] ?? null) === $patientId
                && ($log['context']['reason'] ?? null) === $reason
        );

        $this->assertCount(
            1,
            $matches,
            sprintf(
                'Expected exactly one "Message send blocked" warning for patient_id=%d reason=%s',
                $patientId,
                $reason
            )
        );

        $log = array_values($matches)[0];
        foreach ($extraContext as $key => $expected) {
            $this->assertSame(
                $expected,
                $log['context'][$key] ?? null,
                sprintf('Block log context "%s" did not match', $key)
            );
        }
    }
}
