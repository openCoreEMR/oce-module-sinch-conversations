<?php

/**
 * Message Service for sending messages
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
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class MessageService
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly ConversationApiClient $apiClient
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Send message to a patient
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @param string $message
     * @param array<string, mixed> $options
     * @return array<string, mixed> Message data
     * @throws ValidationException
     */
    public function sendToPatient(
        int $patientId,
        string $phoneNumber,
        string $message,
        array $options = []
    ): array {
        $contactId = $this->getOrCreateContact($patientId, $phoneNumber);

        $conversationId = $this->getOrCreateConversation($contactId, $patientId);

        try {
            $response = $this->apiClient->sendMessage($contactId, $message, $options);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send message: " . $e->getMessage());
            throw new ValidationException("Failed to send message: " . $e->getMessage());
        }

        $this->storeOutboundMessage($conversationId, $response, $message, $options);

        return $response;
    }

    /**
     * Send batch messages to multiple patients
     *
     * @param array<int, int> $patientIds
     * @param string $message
     * @param array<string, mixed> $options
     * @return array<string, mixed> Results
     */
    public function sendBatch(array $patientIds, string $message, array $options = []): array
    {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($patientIds as $patientId) {
            $phoneNumber = $this->getPatientPhone($patientId);

            if (!$phoneNumber) {
                $results['failed']++;
                $results['errors'][] = "Patient {$patientId}: No phone number";
                continue;
            }

            try {
                $this->sendToPatient($patientId, $phoneNumber, $message, $options);
                $results['sent']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Patient {$patientId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get or create a Sinch contact for patient
     *
     * @param int $patientId
     * @param string $phoneNumber
     * @return string Contact ID
     */
    private function getOrCreateContact(int $patientId, string $phoneNumber): string
    {
        $sql = "SELECT contact_id FROM oce_sinch_contacts
                WHERE patient_id = ? AND channel_identity = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId, $phoneNumber]);

        if ($result) {
            return $result['contact_id'];
        }

        $response = $this->apiClient->createContact($phoneNumber, 'SMS');
        $contactId = $response['id'] ?? '';

        if (!$contactId) {
            throw new ValidationException("Failed to create Sinch contact");
        }

        $sql = "INSERT INTO oce_sinch_contacts (
            patient_id, contact_id, channel, channel_identity,
            opted_in, created_at, updated_at
        ) VALUES (?, ?, 'SMS', ?, TRUE, NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [$patientId, $contactId, $phoneNumber]);

        return $contactId;
    }

    /**
     * Get or create a conversation
     *
     * @param string $contactId
     * @param int $patientId
     * @return string Conversation ID
     */
    private function getOrCreateConversation(string $contactId, int $patientId): string
    {
        $sql = "SELECT conversation_id FROM oce_sinch_conversations
                WHERE contact_id = ? AND patient_id = ?";
        $result = QueryUtils::querySingleRow($sql, [$contactId, $patientId]);

        if ($result) {
            return $result['conversation_id'];
        }

        $conversationId = 'conv_' . uniqid();

        $sql = "INSERT INTO oce_sinch_conversations (
            conversation_id, contact_id, patient_id, channel,
            status, created_at, updated_at
        ) VALUES (?, ?, ?, 'SMS', 'ACTIVE', NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [$conversationId, $contactId, $patientId]);

        return $conversationId;
    }

    /**
     * Store outbound message in database
     *
     * @param string $conversationId
     * @param array<string, mixed> $response
     * @param string $message
     * @param array<string, mixed> $options
     */
    private function storeOutboundMessage(
        string $conversationId,
        array $response,
        string $message,
        array $options
    ): void {
        $sql = "INSERT INTO oce_sinch_messages (
            conversation_id, message_id, direction, channel,
            body, status, template_key, metadata,
            sent_at, created_at
        ) VALUES (?, ?, 'outbound', 'SMS', ?, 'SENT', ?, ?, NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [
            $conversationId,
            $response['id'] ?? uniqid('msg_'),
            $message,
            $options['template_key'] ?? null,
            json_encode($options['metadata'] ?? []),
        ]);

        $sql = "UPDATE oce_sinch_conversations
                SET last_message_at = NOW()
                WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);
    }

    /**
     * Get patient phone number
     *
     * @param int $patientId
     * @return string|null
     */
    private function getPatientPhone(int $patientId): ?string
    {
        $sql = "SELECT phone_cell FROM patient_data WHERE pid = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId]);

        return $result['phone_cell'] ?? null;
    }
}
