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
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'NO']]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('hipaa_allowsms is not YES');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientThrowsWhenHipaaFieldMissing(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [1],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('hipaa_allowsms is not YES');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientThrowsWhenNoConsent(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has not consented');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientThrowsWhenOptedOut(): void
    {
        QueryUtils::setMockResult(
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
            [1],
            [['hipaa_allowsms' => 'YES']]
        );
        QueryUtils::setMockResult(
            "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?",
            [1, '+15559999999'],
            [['opted_in' => true, 'opted_out' => true]]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has not consented');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
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
            "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?",
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
}
