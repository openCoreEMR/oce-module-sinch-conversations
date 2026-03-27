<?php

/**
 * Message Service for sending messages
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;
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
     * @return array<string, mixed> Message data
     * @throws ValidationException
     */
    public function sendToPatient(
        int $patientId,
        string $phoneNumber,
        string $message,
        ?MessageOptions $options = null
    ): array {
        $options ??= new MessageOptions();

        if (!$options->skipConsentCheck) {
            $this->assertPatientEligible($patientId, $phoneNumber);
        }

        $contactId = $this->getOrCreateContact($patientId, $phoneNumber);

        $conversationId = $this->getOrCreateConversation($contactId, $patientId);

        // Add configured clinic phone as sender if not already set
        if ($options->sender === null) {
            $senderPhone = $this->config->getClinicPhone();
            if ($senderPhone !== '') {
                $options = new MessageOptions(
                    sender: $senderPhone,
                    channel: Channel::SMS,
                    templateKey: $options->templateKey,
                    metadata: $options->metadata,
                    channelPriority: $options->channelPriority,
                    skipConsentCheck: $options->skipConsentCheck,
                );
                $this->logger->debug('Using configured sender', ['sender' => $senderPhone]);
            }
        }

        try {
            $response = $this->apiClient->sendMessage($contactId, $message, $options->toApiOptions());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send message via Sinch API', ['exception' => $e]);
            throw new ValidationException("Failed to send message", 0, $e);
        }

        $this->storeOutboundMessage($conversationId, $response, $message, $options);

        return $response;
    }

    /**
     * Send batch messages to multiple patients
     *
     * Deduplicates by (phone_number, message_text) so that when multiple
     * patients share a phone number and receive the same message, the
     * message is sent only once. If the text differs (e.g. different
     * appointment times), each variant is sent.
     *
     * @param list<int> $patientIds
     * @return array<string, mixed> Results
     */
    public function sendBatch(array $patientIds, string $message, ?MessageOptions $options = null): array
    {
        $results = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        /** @var array<string, true> $sentMessages phone:messageHash => true */
        $sentMessages = [];

        foreach ($patientIds as $patientId) {
            $phoneNumber = $this->getPatientPhone($patientId);

            if ($phoneNumber === null) {
                $results['failed']++;
                $results['errors'][] = "Patient {$patientId}: No phone number";
                continue;
            }

            // Dedup: skip if this exact message was already sent to this number
            $dedupKey = $phoneNumber . ':' . md5($message);
            if (isset($sentMessages[$dedupKey])) {
                $results['skipped']++;
                $this->logger->debug("Skipping duplicate message to {$phoneNumber} for patient {$patientId}");
                continue;
            }

            try {
                $this->sendToPatient($patientId, $phoneNumber, $message, $options);
                $results['sent']++;
                $sentMessages[$dedupKey] = true;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "Patient {$patientId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get or create a Sinch contact for patient
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
     * @param array<string, mixed> $response
     */
    private function storeOutboundMessage(
        string $conversationId,
        array $response,
        string $message,
        MessageOptions $options
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
            $options->templateKey,
            json_encode($options->metadata ?: []),
        ]);

        $sql = "UPDATE oce_sinch_conversations
                SET last_message_at = NOW()
                WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);
    }

    /**
     * Assert patient is eligible to receive messages
     *
     * Check both OpenEMR's hipaa_allowsms field and module-level consent.
     * Query consent directly to avoid circular dependency (ConsentService depends on MessageService).
     * Normalize the phone number to E.164 before checking consent so that
     * user-entered formats match the E.164 stored by Sinch callbacks.
     *
     * @throws ValidationException
     */
    private function assertPatientEligible(int $patientId, string $phoneNumber): void
    {
        $sql = "SELECT hipaa_allowsms FROM patient_data WHERE pid = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId]);
        $hipaaAllowSms = $result['hipaa_allowsms'] ?? '';

        if ($hipaaAllowSms !== 'YES') {
            throw new ValidationException(
                "Patient {$patientId} has not allowed SMS (hipaa_allowsms is not YES)"
            );
        }

        $normalized = PhoneNormalizer::toE164($phoneNumber);
        if ($normalized === null) {
            throw new ValidationException(
                "Cannot check eligibility: unparseable phone number for patient {$patientId}"
            );
        }

        $sql = "SELECT opted_in, opted_out
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        $consent = QueryUtils::querySingleRow($sql, [$patientId, $normalized]);

        if (!$consent || !($consent['opted_in'] ?? false) || ($consent['opted_out'] ?? false)) {
            throw new ValidationException(
                "Patient {$patientId} has not consented to messages at {$phoneNumber}"
            );
        }
    }

    /**
     * Get patient phone number, normalized to E.164
     */
    private function getPatientPhone(int $patientId): ?string
    {
        $sql = "SELECT phone_cell FROM patient_data WHERE pid = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId]);
        $raw = $result['phone_cell'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $normalized = PhoneNormalizer::toE164($raw);
        if ($normalized === null) {
            $this->logger->warning('Cannot normalize patient phone number', [
                'patientId' => $patientId,
                'phone' => $raw,
            ]);
            return null;
        }

        return $normalized;
    }
}
