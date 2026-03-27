<?php

/**
 * Message Polling Service for checking new messages
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
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
        private readonly ConversationApiClient $apiClient,
        private readonly KeywordHandlerService $keywordHandler,
        private readonly MessageService $messageService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Poll for new messages in a specific conversation
     *
     * @param string $conversationId
     * @return array{messages: list<array<string, mixed>>, keyword_failures: list<array{phone: string, error: string}>}
     */
    public function pollConversation(string $conversationId): array
    {
        $sql = "SELECT last_polled_at, patient_id
                FROM oce_sinch_conversations WHERE conversation_id = ?";
        $conversation = QueryUtils::querySingleRow($sql, [$conversationId]);

        if (!$conversation) {
            $this->logger->error('Conversation not found', ['conversationId' => $conversationId]);
            return ['messages' => [], 'keyword_failures' => []];
        }

        $lastPolled = $conversation['last_polled_at'] ?? null;
        $patientId = $conversation['patient_id'] ?? null;

        $filters = [];
        if ($lastPolled) {
            $filters['start_time'] = $lastPolled;
        }

        $messages = $this->apiClient->getConversationMessages($conversationId, $filters);

        $newMessages = [];
        $keywordFailures = [];
        foreach ($messages as $message) {
            $sql = "SELECT id FROM oce_sinch_messages WHERE message_id = ?";
            $existing = QueryUtils::querySingleRow($sql, [$message['id']]);

            if ($existing) {
                continue;
            }

            $this->storeMessage($conversationId, $message);
            $newMessages[] = $message;

            $direction = ($message['direction'] ?? '') === 'TO_APP' ? 'inbound' : 'outbound';
            $messageBody = $this->extractMessageBody($message);
            $fromIdentifier = $message['contact_id'] ?? '';

            if ($direction === 'inbound' && $messageBody !== '' && $patientId !== null) {
                $failure = $this->handleKeywordIfPresent((int) $patientId, $fromIdentifier, $messageBody);
                if ($failure !== null) {
                    $keywordFailures[] = $failure;
                }
            }
        }

        $sql = "UPDATE oce_sinch_conversations SET last_polled_at = NOW() WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);

        return ['messages' => $newMessages, 'keyword_failures' => $keywordFailures];
    }

    /**
     * Poll all active conversations for new messages
     *
     * @return array{total_messages: int, keyword_failures: list<array{phone: string, error: string}>}
     */
    public function pollAllConversations(): array
    {
        $sql = "SELECT conversation_id
                FROM oce_sinch_conversations
                WHERE last_message_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                OR last_polled_at IS NULL";
        $conversations = QueryUtils::fetchRecords($sql, []);

        $totalNewMessages = 0;
        $allKeywordFailures = [];
        foreach ($conversations as $conversation) {
            $result = $this->pollConversation($conversation['conversation_id']);
            $totalNewMessages += count($result['messages']);
            $allKeywordFailures = array_merge($allKeywordFailures, $result['keyword_failures']);
        }

        return ['total_messages' => $totalNewMessages, 'keyword_failures' => $allKeywordFailures];
    }

    /**
     * Check delivery status for a specific message
     *
     * @param string $messageId
     * @return array<string, mixed> Updated message data
     */
    public function checkMessageStatus(string $messageId): array
    {
        $message = $this->apiClient->getMessage($messageId);

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
     * Run keyword detection on an inbound message and send any auto-response
     *
     * @return array{phone: string, error: string}|null Failure detail, or null on success
     */
    private function handleKeywordIfPresent(int $patientId, string $contactId, string $messageBody): ?array
    {
        $phoneNumber = $this->getPhoneFromContact($contactId);
        if ($phoneNumber === null) {
            return null;
        }

        $response = $this->keywordHandler->handleInboundMessage($phoneNumber, $messageBody);
        if ($response === null) {
            return null;
        }

        try {
            $this->messageService->sendToPatient(
                $patientId,
                $phoneNumber,
                $response,
                new MessageOptions(templateKey: 'keyword_response', skipConsentCheck: true)
            );
        } catch (\Throwable $e) {
            $errorId = bin2hex(random_bytes(4));
            $this->logger->error('Failed to send keyword response', [
                'phone' => $phoneNumber,
                'errorId' => $errorId,
                'exception' => $e,
            ]);
            return ['phone' => $phoneNumber, 'error' => "Failed (ref: $errorId)"];
        }

        $this->logger->info('Sent keyword auto-response via polling', ['phone' => $phoneNumber]);
        return null;
    }

    /**
     * Look up phone number from a Sinch contact ID
     */
    private function getPhoneFromContact(string $contactId): ?string
    {
        if ($contactId === '') {
            return null;
        }

        $sql = "SELECT channel_identity FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1";
        $result = QueryUtils::querySingleRow($sql, [$contactId]);

        return $result['channel_identity'] ?? null;
    }

    /**
     * Extract message body text from a Sinch message object
     *
     * Sinch messages nest text under contact_message.text_message.text (inbound)
     * or app_message.text_message.text (outbound).
     *
     * @param array<string, mixed> $message
     */
    private function extractMessageBody(array $message): string
    {
        // Inbound: contact_message.text_message.text
        $contactMessage = $message['contact_message'] ?? [];
        if (is_array($contactMessage)) {
            $textMessage = $contactMessage['text_message'] ?? [];
            if (is_array($textMessage) && isset($textMessage['text'])) {
                return (string) $textMessage['text'];
            }
        }

        // Outbound: app_message.text_message.text
        $appMessage = $message['app_message'] ?? [];
        if (is_array($appMessage)) {
            $textMessage = $appMessage['text_message'] ?? [];
            if (is_array($textMessage) && isset($textMessage['text'])) {
                return (string) $textMessage['text'];
            }
        }

        // Fallback for pre-normalized messages
        return (string) ($message['text'] ?? $message['body'] ?? '');
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
            $this->extractMessageBody($message),
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
