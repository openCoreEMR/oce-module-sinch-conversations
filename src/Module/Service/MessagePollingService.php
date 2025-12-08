<?php

/**
 * Message Polling Service for checking new messages
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class MessagePollingService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConversationApiClient $apiClient
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Poll for new messages in a specific conversation
     *
     * @param string $conversationId
     * @return array<int, array<string, mixed>> New messages found
     */
    public function pollConversation(string $conversationId): array
    {
        $sql = "SELECT last_polled_at FROM oce_sinch_conversations WHERE conversation_id = ?";
        $conversation = QueryUtils::querySingleRow($sql, [$conversationId]);

        if (!$conversation) {
            $this->logger->error("Conversation not found: {$conversationId}");
            return [];
        }

        $lastPolled = $conversation['last_polled_at'] ?? null;

        $filters = [];
        if ($lastPolled) {
            $filters['start_time'] = $lastPolled;
        }

        try {
            $messages = $this->apiClient->getConversationMessages($conversationId, $filters);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to poll conversation {$conversationId}: " . $e->getMessage());
            return [];
        }

        $newMessages = [];
        foreach ($messages as $message) {
            $sql = "SELECT id FROM oce_sinch_messages WHERE message_id = ?";
            $existing = QueryUtils::querySingleRow($sql, [$message['id']]);

            if (!$existing) {
                $this->storeMessage($conversationId, $message);
                $newMessages[] = $message;
            }
        }

        $sql = "UPDATE oce_sinch_conversations SET last_polled_at = NOW() WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);

        return $newMessages;
    }

    /**
     * Poll all active conversations for new messages
     *
     * @return int Number of new messages found
     */
    public function pollAllConversations(): int
    {
        $sql = "SELECT conversation_id
                FROM oce_sinch_conversations
                WHERE last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                OR last_polled_at IS NULL";
        $conversations = QueryUtils::fetchRecords($sql, []);

        $totalNewMessages = 0;
        foreach ($conversations as $conversation) {
            $newMessages = $this->pollConversation($conversation['conversation_id']);
            $totalNewMessages += count($newMessages);
        }

        return $totalNewMessages;
    }

    /**
     * Check delivery status for a specific message
     *
     * @param string $messageId
     * @return array<string, mixed> Updated message data
     */
    public function checkMessageStatus(string $messageId): array
    {
        try {
            $message = $this->apiClient->getMessage($messageId);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to check message status {$messageId}: " . $e->getMessage());
            return [];
        }

        $sql = "UPDATE oce_sinch_messages
                SET status = ?,
                    delivered_at = ?,
                    read_at = ?,
                    updated_at = NOW()
                WHERE message_id = ?";

        QueryUtils::sqlStatementThrowException($sql, [
            $message['status'] ?? 'UNKNOWN',
            $message['delivered_at'] ?? null,
            $message['read_at'] ?? null,
            $messageId,
        ]);

        return $message;
    }

    /**
     * Store a message in the database
     *
     * @param string $conversationId
     * @param array<string, mixed> $message
     */
    private function storeMessage(string $conversationId, array $message): void
    {
        $direction = ($message['direction'] ?? '') === 'TO_APP' ? 'inbound' : 'outbound';

        $sql = "INSERT INTO oce_sinch_messages (
            conversation_id, message_id, direction, channel,
            from_identifier, to_identifier, body, media_url,
            status, sent_at, delivered_at, read_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $binds = [
            $conversationId,
            $message['id'],
            $direction,
            $message['channel'] ?? 'SMS',
            $message['contact_id'] ?? null,
            $message['recipient'] ?? null,
            $message['text'] ?? $message['body'] ?? '',
            $message['media_url'] ?? null,
            $message['status'] ?? 'DELIVERED',
            $message['sent_at'] ?? null,
            $message['delivered_at'] ?? null,
            $message['read_at'] ?? null,
        ];

        QueryUtils::sqlStatementThrowException($sql, $binds);

        $sql = "UPDATE oce_sinch_conversations
                SET last_message_at = NOW()
                WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);
    }
}
