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

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\ConsentBlock;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\SkipReason;
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

        $this->ensureContactRecord($patientId, $phoneNumber);
        $conversationId = $this->getOrCreateConversation($patientId);
        $channel = $options->channel ?? Channel::SMS;

        try {
            $response = $this->apiClient->sendMessageByChannelIdentity(
                $phoneNumber,
                $message,
                $channel->value,
                $options->toApiOptions()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send message via Sinch API', ['exception' => $e]);
            throw new ValidationException('Failed to send message', 0, $e);
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
            $dedupKey = $phoneNumber . ':' . $message;
            if (isset($sentMessages[$dedupKey])) {
                $results['skipped']++;
                $this->logger->debug('Skipping duplicate message', [
                    'phone' => $phoneNumber,
                    'patientId' => $patientId,
                ]);
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
     * Ensure a local contact record exists for webhook/polling lookups
     *
     * The outbound send path doesn't need Sinch contacts (it sends by
     * channel identity), but inbound webhook handlers and polling look up
     * patients via oce_sinch_contacts. Write a local record so those
     * lookups succeed — no Sinch API call needed.
     */
    private function ensureContactRecord(int $patientId, string $phoneNumber): void
    {
        // Use ON DUPLICATE KEY UPDATE so that if the patient's phone number
        // changes, the contact record reflects the new number rather than
        // keeping a stale one. The unique_patient_channel constraint
        // (patient_id, channel) determines whether to insert or update.
        $sql = "INSERT INTO oce_sinch_contacts (
            patient_id, contact_id, channel, channel_identity,
            opted_in, created_at, updated_at
        ) VALUES (?, ?, 'SMS', ?, TRUE, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            channel_identity = VALUES(channel_identity),
            updated_at = NOW()";

        QueryUtils::sqlStatementThrowException(
            $sql,
            [$patientId, uniqid('local_', true), $phoneNumber]
        );
    }

    /**
     * Get or create a local conversation record keyed on patient + channel
     */
    private function getOrCreateConversation(int $patientId): string
    {
        $sql = "SELECT conversation_id FROM oce_sinch_conversations
                WHERE patient_id = ? AND channel = 'SMS'";
        $result = QueryUtils::querySingleRow($sql, [$patientId]);

        if ($result) {
            return $result['conversation_id'];
        }

        $conversationId = 'conv_' . uniqid();

        $sql = "INSERT INTO oce_sinch_conversations (
            conversation_id, patient_id, channel,
            status, created_at, updated_at
        ) VALUES (?, ?, 'SMS', 'ACTIVE', NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [$conversationId, $patientId]);

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
     * Delegates to diagnose() so the gate and the diagnostic verdict surface
     * cannot drift apart — every check (chart hipaa_allowsms, phone parsability,
     * module-side opt-out, carrier block) is evaluated in exactly one place.
     * This method only adds the side effects: a structured WARNING log and a
     * ValidationException.
     *
     * @throws ValidationException
     */
    private function assertPatientEligible(int $patientId, string $phoneNumber): void
    {
        $verdict = $this->diagnose($patientId, $phoneNumber);
        if ($verdict['can_send']) {
            return;
        }

        $reason = SkipReason::from((string) $verdict['reason']);
        $this->logBlock($patientId, $reason, $verdict['context']);
        throw new ValidationException(match ($reason) {
            SkipReason::HipaaDisallowsSms => "Patient {$patientId} has not allowed SMS (hipaa_allowsms is not YES)",
            SkipReason::MissingPhone => "Patient {$patientId} has no phone number on file",
            SkipReason::UnparseablePhone => "Cannot check eligibility: unparseable phone number"
                . " for patient {$patientId}",
            SkipReason::CarrierBlocked => "Patient {$patientId}'s phone {$phoneNumber} is carrier-blocked",
            SkipReason::ModuleOptOut => "Patient {$patientId} has explicitly opted out of messages at {$phoneNumber}",
        });
    }

    /**
     * Run the same checks as assertPatientEligible(), but return a structured
     * verdict instead of throwing. Used by diagnostic surfaces (the calendar
     * SMS-status renderer, support tools) that need to answer "would a send
     * to this patient succeed right now?" without actually attempting one.
     *
     * Looks up phone_cell from patient_data when $phoneNumber is null, so
     * UI callers can pass just the patient id.
     *
     * @return array{
     *     can_send: bool,
     *     reason: ?string,
     *     context: array<string, scalar|null>,
     *     phone: ?string
     * }
     */
    public function diagnose(int $patientId, ?string $phoneNumber = null): array
    {
        $sql = "SELECT hipaa_allowsms, phone_cell FROM patient_data WHERE pid = ?";
        $result = QueryUtils::querySingleRow($sql, [$patientId]);
        $hipaaAllowSms = is_string($result['hipaa_allowsms'] ?? null) ? $result['hipaa_allowsms'] : '';

        if ($hipaaAllowSms !== 'YES') {
            return [
                'can_send' => false,
                'reason' => SkipReason::HipaaDisallowsSms->value,
                'context' => ['hipaa_allowsms' => $hipaaAllowSms === '' ? 'unset' : $hipaaAllowSms],
                'phone' => null,
            ];
        }

        $rawPhone = $phoneNumber;
        if ($rawPhone === null) {
            $rawPhone = is_string($result['phone_cell'] ?? null) ? trim($result['phone_cell']) : '';
        }
        if ($rawPhone === '') {
            return [
                'can_send' => false,
                'reason' => SkipReason::MissingPhone->value,
                'context' => [],
                'phone' => null,
            ];
        }

        $normalized = PhoneNormalizer::toE164($rawPhone);
        if ($normalized === null) {
            return [
                'can_send' => false,
                'reason' => SkipReason::UnparseablePhone->value,
                'context' => ['phone_last4' => PhoneNormalizer::last4($rawPhone)],
                'phone' => null,
            ];
        }

        $sql = "SELECT opted_in, opted_out, carrier_blocked, carrier_block_reason
                FROM oce_sinch_patient_consent
                WHERE patient_id = ? AND phone_number = ?";
        // QueryUtils::querySingleRow returns false on no row; normalize to
        // null so ConsentBlock::evaluate's ?array contract holds.
        $row = QueryUtils::querySingleRow($sql, [$patientId, $normalized]) ?: null;
        $block = ConsentBlock::evaluate($row);
        if ($block !== null) {
            return [
                'can_send' => false,
                'reason' => $block->reason->value,
                'context' => $block->context,
                'phone' => $normalized,
            ];
        }

        return [
            'can_send' => true,
            'reason' => null,
            'context' => [],
            'phone' => $normalized,
        ];
    }

    /**
     * Emit a structured warning that a send was blocked at the eligibility gate.
     *
     * @param array<string, scalar|null> $extra additional cheap context to aid triage
     */
    private function logBlock(int $patientId, SkipReason $reason, array $extra = []): void
    {
        $this->logger->warning('Message send blocked', [
            'patient_id' => $patientId,
            'reason' => $reason->value,
        ] + $extra);
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
