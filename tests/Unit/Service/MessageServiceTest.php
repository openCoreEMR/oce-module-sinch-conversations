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

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
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
        ]));
        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->service = new MessageService($this->config, $this->apiClient);
    }

    // --- sendToPatient ---

    public function testSendToPatientWithExistingContact(): void
    {
        // Existing contact
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            [['contact_id' => 'contact-abc']]
        );
        // Existing conversation
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['contact-abc', 1],
            [['conversation_id' => 'conv-123']]
        );

        $this->apiClient->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['id' => 'msg-001', 'status' => 'QUEUED']);

        $result = $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $this->assertEquals('msg-001', $result['id']);

        // Verify outbound message was stored
        $queries = QueryUtils::getQueries();
        $insertMsg = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_messages'));
        $this->assertNotEmpty($insertMsg);
    }

    public function testSendToPatientCreatesNewContact(): void
    {
        // No existing contact
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            []
        );
        // API creates contact
        $this->apiClient->method('createContact')
            ->with('+15559999999', 'SMS')
            ->willReturn(['id' => 'new-contact-id']);

        // New conversation (no existing)
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['new-contact-id', 1],
            []
        );

        $this->apiClient->method('sendMessage')
            ->willReturn(['id' => 'msg-002']);

        $result = $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $this->assertEquals('msg-002', $result['id']);

        // Verify contact was inserted
        $queries = QueryUtils::getQueries();
        $insertContact = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_contacts'));
        $this->assertNotEmpty($insertContact);
    }

    public function testSendToPatientThrowsWhenCreateContactFails(): void
    {
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            []
        );
        $this->apiClient->method('createContact')->willReturn(['id' => '']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Failed to create Sinch contact');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientAddsSenderFromConfig(): void
    {
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            [['contact_id' => 'contact-abc']]
        );
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['contact-abc', 1],
            [['conversation_id' => 'conv-123']]
        );

        $this->apiClient->expects($this->once())
            ->method('sendMessage')
            ->with(
                'contact-abc',
                'Hello',
                $this->callback(fn($opts) => ($opts['sender'] ?? '') === '+15551234567' && ($opts['channel'] ?? '') === 'SMS')
            )
            ->willReturn(['id' => 'msg-003']);

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    public function testSendToPatientPreservesExplicitSender(): void
    {
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            [['contact_id' => 'contact-abc']]
        );
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['contact-abc', 1],
            [['conversation_id' => 'conv-123']]
        );

        $this->apiClient->expects($this->once())
            ->method('sendMessage')
            ->with(
                'contact-abc',
                'Hello',
                $this->callback(fn($opts) => ($opts['sender'] ?? '') === '+15550000000')
            )
            ->willReturn(['id' => 'msg-004']);

        $this->service->sendToPatient(1, '+15559999999', 'Hello', ['sender' => '+15550000000']);
    }

    public function testSendToPatientThrowsOnApiFailure(): void
    {
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            [['contact_id' => 'contact-abc']]
        );
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['contact-abc', 1],
            [['conversation_id' => 'conv-123']]
        );

        $this->apiClient->method('sendMessage')
            ->willThrowException(new \RuntimeException('API timeout'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Failed to send message');

        $this->service->sendToPatient(1, '+15559999999', 'Hello');
    }

    // --- sendBatch ---

    public function testSendBatchCountsSuccessAndFailure(): void
    {
        // Patient 1 has phone
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [1],
            [['phone_cell' => '+15551111111']]
        );
        // Patient 2 has no phone
        QueryUtils::setMockResult(
            "SELECT phone_cell FROM patient_data WHERE pid = ?",
            [2],
            []
        );

        // Patient 1 send flow
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15551111111'],
            [['contact_id' => 'c1']]
        );
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['c1', 1],
            [['conversation_id' => 'conv-1']]
        );
        $this->apiClient->method('sendMessage')->willReturn(['id' => 'msg-batch']);

        $results = $this->service->sendBatch([1, 2], 'Batch message');

        $this->assertEquals(1, $results['sent']);
        $this->assertEquals(1, $results['failed']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('No phone number', $results['errors'][0]);
    }

    // --- getOrCreateConversation (tested indirectly) ---

    public function testCreatesNewConversationWhenNoneExists(): void
    {
        QueryUtils::setMockResult(
            "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?",
            [1, '+15559999999'],
            [['contact_id' => 'contact-abc']]
        );
        // No existing conversation
        QueryUtils::setMockResult(
            "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?",
            ['contact-abc', 1],
            []
        );

        $this->apiClient->method('sendMessage')->willReturn(['id' => 'msg-005']);

        $this->service->sendToPatient(1, '+15559999999', 'Hello');

        $queries = QueryUtils::getQueries();
        $insertConv = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_conversations'));
        $this->assertNotEmpty($insertConv);
    }
}
