<?php

/**
 * Webhook Controller - handles incoming webhooks from Sinch Conversations API
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\ErrorId;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\PhoneNormalizer;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\UnauthorizedException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $globalConfig,
        private readonly KeywordHandlerService $keywordHandler,
        private readonly MessageService $messageService,
        private readonly ConsentService $consentService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Dispatch incoming webhook request
     */
    public function dispatch(?Request $request = null): Response
    {
        $request ??= Request::createFromGlobals();

        if (!$request->isMethod('POST')) {
            $this->logger->warning('Webhook received non-POST request', [
                'method' => $request->getMethod(),
            ]);
            return new JsonResponse(
                ['error' => 'Method not allowed'],
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        $this->logger->info('Webhook received', ['clientIp' => $clientIp]);

        try {
            $this->authenticate($request, $clientIp);
        } catch (AccessDeniedException) {
            // Return 404 to hide endpoint existence from unauthorized callers
            return new Response('', Response::HTTP_NOT_FOUND);
        } catch (UnauthorizedException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_UNAUTHORIZED
            );
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Webhook authentication error', [
                'errorId' => $errorId,
                'clientIp' => $clientIp,
                'exception' => $e,
            ]);
            return new JsonResponse(
                ['error' => 'Internal error', 'errorId' => $errorId],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $payload = $this->parsePayload($request);

        if ($payload === []) {
            $this->logger->error("Webhook received empty or invalid payload");
            return new JsonResponse(
                ['error' => 'Invalid payload'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Sinch uses discriminated union by field presence — the event type
        // is determined by which top-level key exists in the payload.
        // See https://developers.sinch.com/docs/conversation/callbacks.md
        $eventType = $this->detectEventType($payload);

        if ($eventType === null) {
            $this->logger->error('Webhook payload has no recognized event key', [
                'payload_keys' => array_keys($payload),
            ]);
            return new JsonResponse(
                ['error' => 'Unrecognized payload structure'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->logger->info('Processing webhook event', ['eventType' => $eventType]);

        return match ($eventType) {
            'message' => $this->handleMessageInbound($payload),
            'message_delivery_report' => $this->handleMessageDelivery($payload),
            'opt_out_notification' => $this->handleOptOut($payload),
            'opt_in_notification' => $this->handleOptIn($payload),
            default => $this->handleUnknownEvent($eventType),
        };
    }

    /**
     * Authenticate the webhook request
     *
     * Checks IP allowlist first (if configured), then verifies the Sinch HMAC-SHA256
     * webhook signature. If the webhook secret is not configured, returns 404 to hide
     * the endpoint.
     *
     * @throws AccessDeniedException If IP is not in allowlist, secret not configured, or headers missing
     * @throws UnauthorizedException If HMAC signature verification fails
     */
    private function authenticate(Request $request, string $clientIp): void
    {
        if (!$this->globalConfig->isIpInAllowlist($clientIp)) {
            $this->logger->warning('Webhook request from unauthorized IP', ['clientIp' => $clientIp]);
            throw new AccessDeniedException("IP address not in allowlist");
        }

        if (!$this->globalConfig->isWebhookAuthConfigured()) {
            $this->logger->warning('Webhook request but webhook secret not configured');
            throw new AccessDeniedException("Webhook authentication not configured");
        }

        $signature = $request->headers->get('x-sinch-webhook-signature') ?? '';
        $timestamp = $request->headers->get('x-sinch-webhook-signature-timestamp') ?? '';
        $nonce = $request->headers->get('x-sinch-webhook-signature-nonce') ?? '';
        $algorithm = $request->headers->get('x-sinch-webhook-signature-algorithm') ?? '';

        if ($signature === '' || $timestamp === '' || $nonce === '' || $algorithm === '') {
            $this->logger->warning('Webhook request missing HMAC signature headers', ['clientIp' => $clientIp]);
            throw new AccessDeniedException("Missing webhook signature headers");
        }

        if (strlen($nonce) > 255) {
            $this->logger->warning('Webhook request with oversized nonce', ['clientIp' => $clientIp]);
            throw new AccessDeniedException("Invalid webhook signature headers");
        }

        if (strcasecmp($algorithm, 'HmacSHA256') !== 0) {
            $this->logger->warning(
                'Webhook request with unsupported signature algorithm',
                ['clientIp' => $clientIp, 'algorithm' => $algorithm]
            );
            throw new AccessDeniedException("Invalid webhook signature headers");
        }

        if (!preg_match('/^\d+$/', $timestamp)) {
            $this->logger->warning(
                'Webhook request with invalid timestamp format in signature headers',
                ['clientIp' => $clientIp, 'timestamp' => $timestamp]
            );
            throw new AccessDeniedException("Invalid webhook signature headers");
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > self::TIMESTAMP_TOLERANCE_SECONDS) {
            $this->logger->warning(
                'Webhook request with stale timestamp',
                ['clientIp' => $clientIp, 'age' => $age]
            );
            throw new UnauthorizedException("Invalid webhook signature");
        }

        /** @var string $rawBody */
        $rawBody = $request->getContent();

        if (!$this->globalConfig->verifyWebhookSignature($rawBody, $signature, $timestamp, $nonce)) {
            $this->logger->warning('Webhook request with invalid HMAC signature', ['clientIp' => $clientIp]);
            throw new UnauthorizedException("Invalid webhook signature");
        }

        // Atomically record the nonce; duplicate means replay
        if (!$this->recordNonce($nonce, (int) $timestamp)) {
            $this->logger->warning('Webhook request with replayed nonce', ['clientIp' => $clientIp]);
            throw new UnauthorizedException("Invalid webhook signature");
        }
    }

    /**
     * Atomically record a nonce and prune expired entries
     *
     * Uses INSERT (not INSERT IGNORE) so a duplicate key error signals
     * replay. This eliminates the race condition of check-then-insert.
     * Uses FROM_UNIXTIME for DB-side timestamp conversion to avoid
     * PHP/MySQL timezone mismatches.
     *
     * @return bool False when the nonce already exists (replay detected)
     */
    private function recordNonce(string $nonce, int $timestamp): bool
    {
        $expiresAtUnix = $timestamp + self::TIMESTAMP_TOLERANCE_SECONDS;

        try {
            QueryUtils::sqlStatementThrowException(
                "INSERT INTO `oce_sinch_webhook_nonces` (`nonce`, `expires_at`) VALUES (?, FROM_UNIXTIME(?))",
                [$nonce, $expiresAtUnix]
            );
        } catch (\Throwable $e) {
            $isDuplicate = str_contains($e->getMessage(), 'Duplicate entry')
                || (string) $e->getCode() === '23000';
            if ($isDuplicate) {
                return false;
            }
            throw $e;
        }

        // Prune expired nonces opportunistically. Failures must not cause
        // the request to fail after the nonce is recorded, otherwise retries
        // would be incorrectly rejected as replays.
        try {
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `oce_sinch_webhook_nonces` WHERE `expires_at` < NOW()"
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to prune expired webhook nonces', [
                'exception' => $e,
            ]);
        }

        return true;
    }

    /**
     * Detect the event type from the webhook payload
     *
     * Sinch identifies the callback type by which event-specific key is
     * present at the top level of the payload. Returns the key name, or
     * null if no recognized key is found.
     *
     * @param array<string, mixed> $payload
     */
    private function detectEventType(array $payload): ?string
    {
        // Order matters only for readability; each payload has exactly one of these
        $eventKeys = [
            'message',
            'message_delivery_report',
            'event',
            'opt_out_notification',
            'opt_in_notification',
            'contact_create_notification',
            'contact_delete_notification',
            'contact_merge_notification',
            'contact_update_notification',
            'conversation_start_notification',
            'conversation_stop_notification',
            'conversation_delete_notification',
            'capability_notification',
            'unsupported_callback',
            'channel_event_notification',
            'contact_identities_duplication_notification',
            'smart_conversation',
            'message_redaction',
            'record_notification',
        ];

        foreach ($eventKeys as $key) {
            if (array_key_exists($key, $payload)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Parse webhook payload from request
     *
     * Sinch Conversations API sends webhooks as application/json. The event
     * type is determined by field presence (discriminated union), not by an
     * explicit type field. Common top-level keys include: app_id, project_id,
     * accepted_time, event_time, message_metadata, correlation_id.
     *
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        /** @var string $content */
        $content = $request->getContent();
        /** @var array<string, mixed>|scalar|null $data */
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Webhook JSON parse error', ['error' => json_last_error_msg()]);
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Handle MESSAGE_INBOUND event
     *
     * Stores the inbound message and runs keyword detection for STOP/START/HELP.
     *
     * @param array<string, mixed> $payload
     */
    private function handleMessageInbound(array $payload): Response
    {
        /** @var array<string, mixed> $message */
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
        $messageId = $this->extractString($message, 'id', 'unknown');
        $conversationId = $this->extractString($message, 'conversation_id', '');
        $contactId = $this->extractString($message, 'contact_id', '');
        $channel = $this->extractString($message, 'channel_identity.channel', 'SMS');
        $channelIdentity = $this->extractString($message, 'channel_identity.identity', '');

        /** @var array<string, mixed> $contactMessage */
        $contactMessage = is_array($message['contact_message'] ?? null) ? $message['contact_message'] : [];
        /** @var array<string, mixed> $textMessage */
        $textMessage = is_array($contactMessage['text_message'] ?? null) ? $contactMessage['text_message'] : [];
        $messageBody = $this->extractString($textMessage, 'text', '');

        $this->logger->info('Processing inbound message', [
            'messageId' => $messageId,
            'channelIdentity' => $channelIdentity,
        ]);

        try {
            $this->storeInboundMessage(
                $conversationId,
                $messageId,
                $channel,
                $channelIdentity,
                $messageBody,
                $contactId
            );

            // Check for keyword responses (STOP, START, HELP)
            $autoResponseError = null;
            if ($messageBody !== '' && $channelIdentity !== '') {
                $keywordResponse = $this->keywordHandler->handleInboundMessage($channelIdentity, $messageBody);
                if ($keywordResponse !== null) {
                    $autoResponseError = $this->sendKeywordResponse($channelIdentity, $keywordResponse);
                }
            }

            $this->logger->info('Successfully processed inbound message', ['messageId' => $messageId]);

            $responseBody = ['status' => 'success', 'messageId' => $messageId];
            if ($autoResponseError !== null) {
                $responseBody['autoResponseError'] = $autoResponseError;
            }

            return new JsonResponse($responseBody, Response::HTTP_OK);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Failed to process inbound message', [
                'messageId' => $messageId,
                'errorId' => $errorId,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => "Failed to process message (ref: $errorId)", 'messageId' => $messageId],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Handle MESSAGE_DELIVERY event
     *
     * Updates the delivery status of an outbound message.
     *
     * @param array<string, mixed> $payload
     */
    private function handleMessageDelivery(array $payload): Response
    {
        /** @var array<string, mixed> $deliveryReport */
        $deliveryReport = is_array($payload['message_delivery_report'] ?? null)
            ? $payload['message_delivery_report']
            : [];
        $messageId = $this->extractString($deliveryReport, 'message_id', 'unknown');
        $status = $this->extractString($deliveryReport, 'status', 'UNKNOWN');

        $this->logger->info('Processing delivery report', [
            'messageId' => $messageId,
            'status' => $status,
        ]);

        try {
            $this->updateMessageDeliveryStatus($messageId, $status);

            $this->logger->info('Successfully processed delivery report', ['messageId' => $messageId]);

            return new JsonResponse(
                ['status' => 'success', 'messageId' => $messageId],
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Failed to process delivery report', [
                'messageId' => $messageId,
                'errorId' => $errorId,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => "Failed to process delivery report (ref: $errorId)", 'messageId' => $messageId],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Handle OPT_OUT callback from Sinch consent management
     *
     * Fires on channels with native opt-out support (e.g. Viber BM).
     * SMS opt-outs arrive as MESSAGE_INBOUND and are handled by KeywordHandlerService.
     *
     * Opt-out applies to ALL patients sharing the phone number, because the
     * carrier blocks the number, not a specific person.
     *
     * @param array<string, mixed> $payload
     */
    private function handleOptOut(array $payload): Response
    {
        $notification = $payload['opt_out_notification'] ?? [];
        $identity = $this->extractString($notification, 'identity', '');
        $channel = $this->extractString($notification, 'channel', '');
        $status = $this->extractString($notification, 'status', '');
        $contactId = $this->extractString($notification, 'contact_id', '');

        $this->logger->info('Processing OPT_OUT', [
            'identity' => $identity,
            'channel' => $channel,
            'status' => $status,
        ]);

        if ($status !== 'OPT_OUT_SUCCEEDED') {
            $this->logger->warning('OPT_OUT did not succeed, skipping', ['status' => $status]);
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        $patientIds = $this->lookupPatientsByContactOrIdentity($contactId, $identity);
        if ($patientIds === []) {
            $this->logger->warning('No patient found for OPT_OUT', [
                'identity' => $identity,
                'contactId' => $contactId,
            ]);
            return new JsonResponse(['status' => 'no_patient'], Response::HTTP_OK);
        }

        $normalizedIdentity = PhoneNormalizer::toE164($identity);
        if ($normalizedIdentity === null) {
            $this->logger->warning("Cannot normalize identity for OPT_OUT: {$identity}");
            return new JsonResponse(['status' => 'invalid_identity'], Response::HTTP_OK);
        }

        $channelEnum = Channel::tryFrom($channel);
        if ($channelEnum === null) {
            $this->logger->warning('Unrecognized channel in OPT_OUT, skipping HIPAA sync', [
                'channel' => $channel,
            ]);
        }

        $failures = 0;
        foreach ($patientIds as $patientId) {
            try {
                $method = "sinch_{$channel}";
                $this->consentService->optOut($patientId, $normalizedIdentity, $method, channel: $channelEnum);
            } catch (\Throwable $e) {
                $failures++;
                $errorId = ErrorId::generate();
                $this->logger->error('Failed to process OPT_OUT for patient', [
                    'patientId' => $patientId,
                    'identity' => $identity,
                    'errorId' => $errorId,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('Recorded OPT_OUT', [
            'patientCount' => count($patientIds),
            'failures' => $failures,
            'channel' => $channel,
        ]);

        // The carrier already blocked the number regardless, so we always
        // return 200 -- but reflect partial failure so callers can alert.
        $status = $failures === 0 ? 'success' : 'partial_failure';
        return new JsonResponse(['status' => $status], Response::HTTP_OK);
    }

    /**
     * Handle OPT_IN callback from Sinch consent management
     *
     * Fires on channels with native opt-in support (e.g. Viber BM).
     * SMS opt-ins arrive as MESSAGE_INBOUND and are handled by KeywordHandlerService.
     *
     * Opt-in applies only to the first matched patient. Unlike opt-out (which
     * must cover all patients at a number for carrier compliance), opt-in is
     * an affirmative choice that cannot be assumed for other people.
     *
     * @param array<string, mixed> $payload
     */
    private function handleOptIn(array $payload): Response
    {
        $notification = $payload['opt_in_notification'] ?? [];
        $identity = $this->extractString($notification, 'identity', '');
        $channel = $this->extractString($notification, 'channel', '');
        $status = $this->extractString($notification, 'status', '');
        $contactId = $this->extractString($notification, 'contact_id', '');

        $this->logger->info('Processing OPT_IN', [
            'identity' => $identity,
            'channel' => $channel,
            'status' => $status,
        ]);

        if ($status !== 'OPT_IN_SUCCEEDED') {
            $this->logger->warning('OPT_IN did not succeed, skipping', ['status' => $status]);
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        $patientIds = $this->lookupPatientsByContactOrIdentity($contactId, $identity);
        if ($patientIds === []) {
            $this->logger->warning('No patient found for OPT_IN', [
                'identity' => $identity,
                'contactId' => $contactId,
            ]);
            return new JsonResponse(['status' => 'no_patient'], Response::HTTP_OK);
        }

        $normalizedIdentity = PhoneNormalizer::toE164($identity);
        if ($normalizedIdentity === null) {
            $this->logger->warning("Cannot normalize identity for OPT_IN: {$identity}");
            return new JsonResponse(['status' => 'invalid_identity'], Response::HTTP_OK);
        }

        $channelEnum = Channel::tryFrom($channel);
        if ($channelEnum === null) {
            $this->logger->warning('Unrecognized channel in OPT_IN, skipping HIPAA sync', [
                'channel' => $channel,
            ]);
        }

        $patientId = $patientIds[0];
        try {
            $this->consentService->optIn($patientId, $normalizedIdentity, "sinch_{$channel}", channel: $channelEnum);
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Failed to process OPT_IN', [
                'identity' => $identity,
                'errorId' => $errorId,
                'exception' => $e,
            ]);

            return new JsonResponse(
                ['error' => "Failed to process opt-in (ref: $errorId)"],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $this->logger->info('Recorded OPT_IN', [
            'patientId' => $patientId,
            'channel' => $channel,
        ]);

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    /**
     * Look up all patient IDs by Sinch contact ID or channel identity (phone number)
     *
     * Prefer contact_id (patient-specific) over identity (phone-level) fallback.
     * When falling back to identity, return ALL patients sharing the number.
     *
     * For OPT_OUT, multi-patient phone-level opt-out is handled by the SMS
     * STOP keyword flow in KeywordHandlerService. This webhook fires for
     * channels with native opt-out (Viber BM etc.) where contact_id is the
     * reliable patient identifier.
     *
     * @return list<int>
     */
    private function lookupPatientsByContactOrIdentity(string $contactId, string $identity): array
    {
        if ($contactId !== '') {
            $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1";
            $result = QueryUtils::querySingleRow($sql, [$contactId]);
            if ($result) {
                return [(int) $result['patient_id']];
            }
        }

        if ($identity !== '') {
            $normalized = PhoneNormalizer::toE164($identity) ?? $identity;
            $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? ORDER BY patient_id ASC";
            $results = QueryUtils::fetchRecords($sql, [$normalized]);
            if ($results !== []) {
                return array_values(array_map(static fn(array $row): int => (int) $row['patient_id'], $results));
            }
        }

        return [];
    }

    /**
     * Handle unknown event types gracefully
     */
    private function handleUnknownEvent(string $eventType): Response
    {
        $this->logger->warning('Received unknown webhook event type', ['eventType' => $eventType]);

        return new JsonResponse(
            ['status' => 'ignored', 'message' => "Unknown event type: {$eventType}"],
            Response::HTTP_OK
        );
    }

    /**
     * Store an inbound message in the database
     */
    private function storeInboundMessage(
        string $conversationId,
        string $messageId,
        string $channel,
        string $fromIdentifier,
        string $body,
        string $contactId
    ): void {
        // Check if this message already exists (idempotency)
        $sql = "SELECT id FROM oce_sinch_messages WHERE message_id = ?";
        $existing = QueryUtils::querySingleRow($sql, [$messageId]);
        if ($existing) {
            $this->logger->debug('Message already stored, skipping', ['messageId' => $messageId]);
            return;
        }

        // Ensure conversation record exists
        $this->ensureConversationExists($conversationId, $contactId, $channel);

        $sql = "INSERT INTO oce_sinch_messages (
            conversation_id, message_id, direction, channel,
            from_identifier, body, status, sent_at, created_at
        ) VALUES (?, ?, 'inbound', ?, ?, ?, 'DELIVERED', NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [
            $conversationId,
            $messageId,
            $channel,
            $fromIdentifier,
            $body,
        ]);

        $sql = "UPDATE oce_sinch_conversations
                SET last_message_at = NOW()
                WHERE conversation_id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$conversationId]);
    }

    /**
     * Ensure a conversation record exists for this conversation ID
     */
    private function ensureConversationExists(
        string $conversationId,
        string $contactId,
        string $channel
    ): void {
        if ($conversationId === '') {
            return;
        }

        $sql = "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?";
        $existing = QueryUtils::querySingleRow($sql, [$conversationId]);
        if ($existing) {
            return;
        }

        // Look up patient from contact
        $patientId = null;
        if ($contactId !== '') {
            $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ?";
            $contact = QueryUtils::querySingleRow($sql, [$contactId]);
            if ($contact) {
                $patientId = $contact['patient_id'];
            }
        }

        $sql = "INSERT INTO oce_sinch_conversations (
            conversation_id, contact_id, patient_id, channel,
            status, last_message_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'ACTIVE', NOW(), NOW(), NOW())";

        QueryUtils::sqlStatementThrowException($sql, [
            $conversationId,
            $contactId,
            $patientId,
            $channel,
        ]);
    }

    /**
     * Update delivery status for a message
     */
    private function updateMessageDeliveryStatus(string $messageId, string $status): void
    {
        $deliveredAt = in_array($status, ['DELIVERED', 'READ'], true) ? 'NOW()' : null;
        $readAt = $status === 'READ' ? 'NOW()' : null;

        if ($deliveredAt !== null && $readAt !== null) {
            $sql = "UPDATE oce_sinch_messages
                    SET status = ?, delivered_at = NOW(), read_at = NOW()
                    WHERE message_id = ?";
            QueryUtils::sqlStatementThrowException($sql, [$status, $messageId]);
        } elseif ($deliveredAt !== null) {
            $sql = "UPDATE oce_sinch_messages
                    SET status = ?, delivered_at = NOW()
                    WHERE message_id = ?";
            QueryUtils::sqlStatementThrowException($sql, [$status, $messageId]);
        } else {
            $sql = "UPDATE oce_sinch_messages
                    SET status = ?
                    WHERE message_id = ?";
            QueryUtils::sqlStatementThrowException($sql, [$status, $messageId]);
        }
    }

    /**
     * Send an auto-response for a detected keyword
     *
     * @return string|null Generic error message on failure, null on success or no matching contact
     */
    private function sendKeywordResponse(
        string $phoneNumber,
        string $responseMessage
    ): ?string {
        $normalized = PhoneNormalizer::toE164($phoneNumber) ?? $phoneNumber;
        $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1";
        $contact = QueryUtils::querySingleRow($sql, [$normalized]);

        if (!$contact) {
            $this->logger->debug('No contact found for keyword response', ['phone' => $normalized]);
            return null;
        }

        try {
            $this->messageService->sendToPatient(
                (int) $contact['patient_id'],
                $normalized,
                $responseMessage,
                new MessageOptions(templateKey: 'keyword_response', skipConsentCheck: true)
            );
        } catch (\Throwable $e) {
            $errorId = ErrorId::generate();
            $this->logger->error('Failed to send keyword response', [
                'phone' => $normalized,
                'errorId' => $errorId,
                'exception' => $e,
            ]);
            return "Failed to send auto-response (ref: $errorId)";
        }

        $this->logger->info('Sent keyword auto-response', ['phone' => $normalized]);
        return null;
    }

    /**
     * Extract a string value from a nested array using dot notation
     *
     * @param array<string, mixed> $data
     */
    private function extractString(array $data, string $key, string $default): string
    {
        $keys = explode('.', $key);
        $current = $data;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return $default;
            }
            $current = $current[$k];
        }

        return is_scalar($current) ? (string) $current : $default;
    }
}
