<?php

/**
 * Unit tests for MessagePollingService
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MessagePollingServiceTest extends TestCase
{
    private GlobalConfig $config;
    private ConversationApiClient&MockObject $apiClient;
    private KeywordHandlerService&MockObject $keywordHandler;
    private MessageService&MockObject $messageService;
    private MessagePollingService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->config = new GlobalConfig(new MockGlobalsAccessor([]));
        $this->apiClient = $this->createMock(ConversationApiClient::class);
        $this->keywordHandler = $this->createMock(KeywordHandlerService::class);
        $this->messageService = $this->createMock(MessageService::class);
        $this->service = new MessagePollingService(
            $this->config,
            $this->apiClient,
            $this->keywordHandler,
            $this->messageService
        );
    }

    // --- pollConversation ---

    public function testPollConversationReturnsEmptyForMissingConversation(): void
    {
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-missing'],
            []
        );

        $result = $this->service->pollConversation('conv-missing');

        $this->assertEquals(['messages' => [], 'keyword_failures' => []], $result);
    }

    public function testPollConversationStoresNewMessages(): void
    {
        // Conversation exists
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['last_polled_at' => '2026-01-01 00:00:00', 'patient_id' => 1]]
        );

        // API returns messages
        $this->apiClient->method('getConversationMessages')
            ->with('conv-1', ['start_time' => '2026-01-01 00:00:00'])
            ->willReturn([
                ['id' => 'msg-new-1', 'direction' => 'TO_APP', 'channel' => 'SMS', 'text' => 'Hello'],
                ['id' => 'msg-new-2', 'direction' => 'FROM_APP', 'channel' => 'SMS', 'text' => 'Reply'],
            ]);

        // Neither message exists yet
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-new-1'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-new-2'],
            []
        );

        $result = $this->service->pollConversation('conv-1');

        $this->assertCount(2, $result['messages']);
        $this->assertEmpty($result['keyword_failures']);

        // Verify messages were inserted
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_messages'));
        $this->assertCount(2, $insertQueries);

        // Verify last_polled_at was updated
        $updateQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_conversations SET last_polled_at'));
        $this->assertNotEmpty($updateQueries);
    }

    public function testPollConversationSkipsExistingMessages(): void
    {
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['last_polled_at' => null, 'patient_id' => 1]]
        );

        $this->apiClient->method('getConversationMessages')
            ->willReturn([
                ['id' => 'msg-exists', 'direction' => 'TO_APP', 'channel' => 'SMS', 'text' => 'Old'],
            ]);

        // Message already exists
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-exists'],
            [['id' => 99]]
        );

        $result = $this->service->pollConversation('conv-1');

        $this->assertEmpty($result['messages']);
    }

    public function testPollConversationHandlesApiError(): void
    {
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['last_polled_at' => null, 'patient_id' => 1]]
        );

        $this->apiClient->method('getConversationMessages')
            ->willThrowException(new \RuntimeException('API error'));

        $result = $this->service->pollConversation('conv-1');

        $this->assertEquals(['messages' => [], 'keyword_failures' => []], $result);

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter($logs, fn($log) => $log['level'] === 'error');
        $this->assertNotEmpty($errorLogs);
    }

    public function testPollConversationSendsStartTimeFilter(): void
    {
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['last_polled_at' => '2026-03-01 12:00:00', 'patient_id' => 1]]
        );

        $this->apiClient->expects($this->once())
            ->method('getConversationMessages')
            ->with('conv-1', ['start_time' => '2026-03-01 12:00:00'])
            ->willReturn([]);

        $this->service->pollConversation('conv-1');
    }

    public function testPollConversationOmitsFilterWhenNeverPolled(): void
    {
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-1'],
            [['last_polled_at' => null, 'patient_id' => 1]]
        );

        $this->apiClient->expects($this->once())
            ->method('getConversationMessages')
            ->with('conv-1', [])
            ->willReturn([]);

        $this->service->pollConversation('conv-1');
    }

    // --- pollAllConversations ---

    public function testPollAllConversationsReturnsTotal(): void
    {
        QueryUtils::setMockResult(
            "SELECT conversation_id
                FROM oce_sinch_conversations
                WHERE last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                OR last_polled_at IS NULL",
            [],
            [
                ['conversation_id' => 'conv-a'],
                ['conversation_id' => 'conv-b'],
            ]
        );

        // conv-a: has conversation, returns 1 new message
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-a'],
            [['last_polled_at' => null, 'patient_id' => 1]]
        );
        // conv-b: has conversation, returns 0 new messages
        QueryUtils::setMockResult(
            "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-b'],
            [['last_polled_at' => null, 'patient_id' => 1]]
        );

        $callCount = 0;
        $this->apiClient->method('getConversationMessages')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [['id' => 'msg-a1', 'direction' => 'TO_APP', 'channel' => 'SMS', 'text' => 'Hi']];
                }
                return [];
            });

        // msg-a1 doesn't exist
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-a1'],
            []
        );

        $result = $this->service->pollAllConversations();

        $this->assertEquals(1, $result['total_messages']);
        $this->assertEmpty($result['keyword_failures']);
    }

    public function testPollAllConversationsReturnsZeroWhenNoConversations(): void
    {
        QueryUtils::setMockResult(
            "SELECT conversation_id
                FROM oce_sinch_conversations
                WHERE last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                OR last_polled_at IS NULL",
            [],
            []
        );

        $result = $this->service->pollAllConversations();
        $this->assertEquals(0, $result['total_messages']);
        $this->assertEmpty($result['keyword_failures']);
    }

    // --- checkMessageStatus ---

    public function testCheckMessageStatusUpdatesDatabase(): void
    {
        $this->apiClient->method('getMessage')
            ->with('msg-check')
            ->willReturn([
                'status' => 'DELIVERED',
                'delivered_at' => '2026-03-25T10:00:00Z',
                'read_at' => null,
            ]);

        $result = $this->service->checkMessageStatus('msg-check');

        $this->assertEquals('DELIVERED', $result['status']);

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_messages'));
        $this->assertNotEmpty($updateQueries);
    }

    public function testCheckMessageStatusReturnsEmptyOnApiError(): void
    {
        $this->apiClient->method('getMessage')
            ->willThrowException(new \RuntimeException('Not found'));

        $result = $this->service->checkMessageStatus('msg-missing');

        $this->assertEquals([], $result);
    }
}
