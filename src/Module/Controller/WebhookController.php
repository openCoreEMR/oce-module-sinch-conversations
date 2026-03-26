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

namespace OpenCoreEMR\Modules\SinchConversations\Controller;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Sinch\Conversation\Exception\AccessDeniedException;
use OpenCoreEMR\Sinch\Conversation\Exception\UnauthorizedException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
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
            $this->logger->warning("Webhook received non-POST request: " . $request->getMethod());
            return new JsonResponse(
                ['error' => 'Method not allowed'],
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        $this->logger->info("Webhook received from: " . $clientIp);

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
        }

        $payload = $this->parsePayload($request);

        if ($payload === []) {
            $this->logger->error("Webhook received empty or invalid payload");
            return new JsonResponse(
                ['error' => 'Invalid payload'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $eventTypeRaw = $payload['trigger'] ?? null;
        if (!is_string($eventTypeRaw) || $eventTypeRaw === '') {
            $this->logger->error("Webhook missing trigger type");
            return new JsonResponse(
                ['error' => 'Missing trigger type'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->logger->info("Processing webhook event: {$eventTypeRaw}");

        return match ($eventTypeRaw) {
            'MESSAGE_INBOUND' => $this->handleMessageInbound($payload),
            'MESSAGE_DELIVERY' => $this->handleMessageDelivery($payload),
            'OPT_OUT' => $this->handleOptOut($payload),
            'OPT_IN' => $this->handleOptIn($payload),
            default => $this->handleUnknownEvent($eventTypeRaw),
        };
    }

    /**
     * Authenticate the webhook request
     *
     * Checks IP allowlist first (if configured), then HTTP Basic Auth (always required).
     * If Basic Auth is not configured, returns 404 to hide the endpoint.
     *
     * @throws AccessDeniedException If IP is not in allowlist or Basic Auth not configured
     * @throws UnauthorizedException If Basic Auth credentials are invalid
     */
    private function authenticate(Request $request, string $clientIp): void
    {
        if (!$this->globalConfig->isIpInAllowlist($clientIp)) {
            $this->logger->warning("Webhook request from unauthorized IP: {$clientIp}");
            throw new AccessDeniedException("IP address not in allowlist");
        }

        if (!$this->globalConfig->isWebhookAuthConfigured()) {
            $this->logger->warning("Webhook request but Basic Auth not configured");
            throw new AccessDeniedException("Webhook authentication not configured");
        }

        $username = $request->getUser() ?? '';
        $password = $request->getPassword() ?? '';

        if (!$this->globalConfig->verifyWebhookAuth($username, $password)) {
            $this->logger->warning("Webhook request with invalid credentials from: {$clientIp}");
            throw new UnauthorizedException("Invalid webhook credentials");
        }
    }

    /**
     * Parse webhook payload from request
     *
     * Sinch Conversations API sends webhooks as application/json with structure:
     * {
     *   "app_id": "...",
     *   "trigger": "MESSAGE_INBOUND",
     *   "message": { "id": "...", "direction": "TO_APP", "contact_message": {...}, ... },
     *   "message_metadata": "..."
     * }
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
            $this->logger->error("Webhook JSON parse error: " . json_last_error_msg());
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

        $this->logger->info("Processing inbound message: {$messageId} from {$channelIdentity}");

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
            $errorId = bin2hex(random_bytes(4));
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

        $this->logger->info("Processing delivery report for message: {$messageId}, status: {$status}");

        try {
            $this->updateMessageDeliveryStatus($messageId, $status);

            $this->logger->info("Successfully processed delivery report for: {$messageId}");

            return new JsonResponse(
                ['status' => 'success', 'messageId' => $messageId],
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $this->logger->error("Failed to process delivery report {$messageId}: " . $e->getMessage());

            return new JsonResponse(
                ['error' => 'Failed to process delivery report', 'messageId' => $messageId],
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
     * @param array<string, mixed> $payload
     */
    private function handleOptOut(array $payload): Response
    {
        $notification = $payload['opt_out_notification'] ?? [];
        $identity = $this->extractString($notification, 'identity', '');
        $channel = $this->extractString($notification, 'channel', '');
        $status = $this->extractString($notification, 'status', '');
        $contactId = $this->extractString($notification, 'contact_id', '');

        $this->logger->info("Processing OPT_OUT: identity={$identity}, channel={$channel}, status={$status}");

        if ($status !== 'OPT_OUT_SUCCEEDED') {
            $this->logger->warning("OPT_OUT did not succeed (status={$status}), skipping");
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        $patientId = $this->lookupPatientByContactOrIdentity($contactId, $identity);
        if ($patientId === null) {
            $this->logger->warning("No patient found for OPT_OUT: identity={$identity}, contact={$contactId}");
            return new JsonResponse(['status' => 'no_patient'], Response::HTTP_OK);
        }

        try {
            $this->consentService->optOut($patientId, $identity, "sinch_{$channel}");
            $this->logger->info("Recorded OPT_OUT for patient {$patientId} on {$channel}");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to record OPT_OUT for patient {$patientId}: " . $e->getMessage());
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    /**
     * Handle OPT_IN callback from Sinch consent management
     *
     * Fires on channels with native opt-in support (e.g. Viber BM).
     * SMS opt-ins arrive as MESSAGE_INBOUND and are handled by KeywordHandlerService.
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

        $this->logger->info("Processing OPT_IN: identity={$identity}, channel={$channel}, status={$status}");

        if ($status !== 'OPT_IN_SUCCEEDED') {
            $this->logger->warning("OPT_IN did not succeed (status={$status}), skipping");
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        $patientId = $this->lookupPatientByContactOrIdentity($contactId, $identity);
        if ($patientId === null) {
            $this->logger->warning("No patient found for OPT_IN: identity={$identity}, contact={$contactId}");
            return new JsonResponse(['status' => 'no_patient'], Response::HTTP_OK);
        }

        try {
            $this->consentService->optIn($patientId, $identity, "sinch_{$channel}");
            $this->logger->info("Recorded OPT_IN for patient {$patientId} on {$channel}");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to record OPT_IN for patient {$patientId}: " . $e->getMessage());
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    /**
     * Look up patient ID by Sinch contact ID or channel identity (phone number)
     */
    private function lookupPatientByContactOrIdentity(string $contactId, string $identity): ?int
    {
        if ($contactId !== '') {
            $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1";
            $result = QueryUtils::querySingleRow($sql, [$contactId]);
            if ($result) {
                return (int) $result['patient_id'];
            }
        }

        if ($identity !== '') {
            $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1";
            $result = QueryUtils::querySingleRow($sql, [$identity]);
            if ($result) {
                return (int) $result['patient_id'];
            }
        }

        return null;
    }

    /**
     * Handle unknown event types gracefully
     */
    private function handleUnknownEvent(string $eventType): Response
    {
        $this->logger->warning("Received unknown webhook event type: {$eventType}");

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
            $this->logger->debug("Message already stored, skipping: {$messageId}");
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
        $sql = "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1";
        $contact = QueryUtils::querySingleRow($sql, [$phoneNumber]);

        if (!$contact) {
            $this->logger->debug('No contact found for keyword response', ['phone' => $phoneNumber]);
            return null;
        }

        try {
            $this->messageService->sendToPatient(
                (int) $contact['patient_id'],
                $phoneNumber,
                $responseMessage,
                new MessageOptions(templateKey: 'keyword_response', skipConsentCheck: true)
            );
        } catch (\Throwable $e) {
            $errorId = bin2hex(random_bytes(4));
            $this->logger->error('Failed to send keyword response', [
                'phone' => $phoneNumber,
                'errorId' => $errorId,
                'exception' => $e,
            ]);
            return "Failed to send auto-response (ref: $errorId)";
        }

        $this->logger->info('Sent keyword auto-response', ['phone' => $phoneNumber]);
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
