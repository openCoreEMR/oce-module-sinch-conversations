<?php

/**
 * Unit tests for WebhookController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchConversations\Channel;
use OpenCoreEMR\Modules\SinchConversations\Controller\WebhookController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageOptions;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookControllerTest extends TestCase
{
    private const TEST_SECRET = 'test_secret';

    private GlobalConfig&MockObject $mockConfig;
    private KeywordHandlerService&MockObject $mockKeywordHandler;
    private MessageService&MockObject $mockMessageService;
    private ConsentService&MockObject $mockConsentService;
    private WebhookController $controller;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $this->mockConfig = $this->createMock(GlobalConfig::class);
        $this->mockConfig->method('isIpInAllowlist')->willReturn(true);
        $this->mockConfig->method('isWebhookAuthConfigured')->willReturn(true);
        $this->mockConfig->method('verifyWebhookSignature')->willReturn(true);

        $this->mockKeywordHandler = $this->createMock(KeywordHandlerService::class);
        $this->mockMessageService = $this->createMock(MessageService::class);
        $this->mockConsentService = $this->createMock(ConsentService::class);

        $this->controller = new WebhookController(
            $this->mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );
    }

    // --- HTTP method tests ---

    public function testRejectsGetRequests(): void
    {
        $request = $this->makeRequest('GET', []);

        $response = $this->controller->dispatch($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        $this->assertResponseContains($response, 'error', 'Method not allowed');
    }

    public function testRejectsPutRequests(): void
    {
        $request = $this->makeRequest('PUT', []);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
    }

    // --- Authentication tests ---

    public function testReturns404WhenIpNotInAllowlist(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(false);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        $request = $this->makeRequest('POST', ['message' => []]);

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenAuthNotConfigured(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(true);
        $mockConfig->method('isWebhookAuthConfigured')->willReturn(false);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        $request = $this->makeRequest('POST', ['message' => []]);

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns401WhenSignatureInvalid(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(true);
        $mockConfig->method('isWebhookAuthConfigured')->willReturn(true);
        $mockConfig->method('verifyWebhookSignature')->willReturn(false);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        $request = $this->makeRequest('POST', ['message' => []], 'wrong_secret');

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testReturns404WhenHmacHeadersMissing(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(true);
        $mockConfig->method('isWebhookAuthConfigured')->willReturn(true);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        // Create request without HMAC headers
        $request = $this->makeRequest('POST', ['message' => []], null);

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenAlgorithmUnsupported(): void
    {
        $body = json_encode(['message' => []]) ?: '{}';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => 'irrelevant',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA512',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns404WhenTimestampNonNumeric(): void
    {
        $body = json_encode(['message' => []]) ?: '{}';
        $nonce = bin2hex(random_bytes(16));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => 'irrelevant',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => 'not-a-number',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA256',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns401WhenTimestampStale(): void
    {
        $body = json_encode(['message' => []]) ?: '{}';
        $timestamp = (string) (time() - 600); // 10 minutes ago
        $nonce = bin2hex(random_bytes(16));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => 'irrelevant',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA256',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAcceptsValidAuth(): void
    {
        $request = $this->makeRequest('POST', ['unsupported_callback' => []]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRejects401WhenNonceReplayed(): void
    {
        // Simulate a duplicate key error from the nonce INSERT
        QueryUtils::setNextException(
            new \RuntimeException('Duplicate entry \'abc123\' for key \'PRIMARY\'')
        );

        $request = $this->makeRequest('POST', ['message' => []]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRecordsNonceOnSuccessfulAuth(): void
    {
        $request = $this->makeRequest('POST', ['unsupported_callback' => []]);

        $this->controller->dispatch($request);

        $queries = QueryUtils::getQueries();
        $nonceInserts = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['sql'], 'oce_sinch_webhook_nonces') && str_contains($q['sql'], 'INSERT')
        );
        $this->assertNotEmpty($nonceInserts, 'Nonce should be recorded after successful auth');
    }

    public function testPrunesExpiredNonces(): void
    {
        $request = $this->makeRequest('POST', ['unsupported_callback' => []]);

        $this->controller->dispatch($request);

        $queries = QueryUtils::getQueries();
        $pruneQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['sql'], 'oce_sinch_webhook_nonces') && str_contains($q['sql'], 'DELETE')
        );
        $this->assertNotEmpty($pruneQueries, 'Expired nonces should be pruned after successful auth');
    }

    public function testFailedHmacDoesNotTouchNonceTable(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(true);
        $mockConfig->method('isWebhookAuthConfigured')->willReturn(true);
        $mockConfig->method('verifyWebhookSignature')->willReturn(false);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        QueryUtils::clearQueries();

        $request = $this->makeRequest('POST', ['message' => []], 'wrong_secret');
        $controller->dispatch($request);

        $nonceQueries = array_filter(
            QueryUtils::getQueries(),
            fn (array $q): bool => str_contains($q['sql'], 'oce_sinch_webhook_nonces')
        );
        $this->assertEmpty($nonceQueries, 'Failed HMAC should not touch nonce table');
    }

    public function testReturns500OnNonceDbError(): void
    {
        // Simulate a non-duplicate DB exception during nonce insert
        QueryUtils::setNextException(
            new \RuntimeException('Connection lost during query')
        );

        $request = $this->makeRequest('POST', ['message' => []]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errorId', $body);
        $this->assertEquals('Internal error', $body['error']);
        // Ensure no internal details are leaked
        $this->assertStringNotContainsString('Connection lost', (string) $response->getContent());
    }

    // --- Payload parsing tests ---

    public function testReturns400ForEmptyPayload(): void
    {
        $body = '';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signedData = $body . '.' . $nonce . '.' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $signedData, self::TEST_SECRET, true));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA256',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturns400ForInvalidJson(): void
    {
        $body = '{invalid json{{{';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signedData = $body . '.' . $nonce . '.' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $signedData, self::TEST_SECRET, true));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA256',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturns400ForJsonArrayPayload(): void
    {
        $body = '[{"message": {}}]';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signedData = $body . '.' . $nonce . '.' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $signedData, self::TEST_SECRET, true));

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP' => $timestamp,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE' => $nonce,
            'HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM' => 'HmacSHA256',
        ], $body);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturns400ForNoEventKey(): void
    {
        $request = $this->makeRequest('POST', ['app_id' => 'test', 'project_id' => 'test']);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // --- Unknown event handling ---

    public function testHandlesUnknownEventGracefully(): void
    {
        $request = $this->makeRequest('POST', ['conversation_start_notification' => []]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'ignored');
    }

    // --- MESSAGE_INBOUND tests ---

    public function testHandlesMessageInbound(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-001', 'conv-001', 'contact-001', 'Hello'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-001'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-001'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ?",
            ['contact-001'],
            []
        );

        $this->mockKeywordHandler->expects($this->once())
            ->method('handleInboundMessage')
            ->with('+15551234567', 'Hello')
            ->willReturn(null);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');
        $this->assertResponseContains($response, 'messageId', 'msg-001');

        $queries = QueryUtils::getQueries();
        $insertMsgQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_messages')
        );
        $this->assertNotEmpty($insertMsgQueries);
    }

    public function testInboundMessageSkipsDuplicates(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-dup', 'conv-001', 'contact-001', 'Dup'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-dup'],
            [['id' => 99]]
        );

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $queries = QueryUtils::getQueries();
        $insertMsgQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_messages')
        );
        $this->assertEmpty($insertMsgQueries);
    }

    public function testInboundMessageCreatesConversationIfMissing(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-002', 'conv-new', 'contact-002', 'Hi'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-002'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-new'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ?",
            ['contact-002'],
            [['patient_id' => 42]]
        );

        $this->mockKeywordHandler->method('handleInboundMessage')->willReturn(null);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $queries = QueryUtils::getQueries();
        $insertConvQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'INSERT INTO oce_sinch_conversations')
        );
        $this->assertNotEmpty($insertConvQueries);
    }

    public function testInboundMessageTriggersKeywordResponse(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-stop', 'conv-003', 'contact-003', 'STOP'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-stop'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-003'],
            [['id' => 1]]
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockKeywordHandler->expects($this->once())
            ->method('handleInboundMessage')
            ->with('+15551234567', 'STOP')
            ->willReturn('You have been unsubscribed.');

        $this->mockMessageService->expects($this->once())
            ->method('sendToPatient')
            ->with(10, '+15551234567', 'You have been unsubscribed.', new MessageOptions(templateKey: 'keyword_response', skipConsentCheck: true));

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testKeywordResponseFailureDoesNotFailWebhook(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-help', 'conv-004', 'contact-004', 'HELP'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-help'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-004'],
            [['id' => 1]]
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockKeywordHandler->method('handleInboundMessage')->willReturn('Help text');
        $this->mockMessageService->method('sendToPatient')
            ->willThrowException(new \RuntimeException('API error'));

        $response = $this->controller->dispatch($request);

        // Inbound message was stored — webhook should still succeed
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('autoResponseError', $body);
        $this->assertStringStartsWith('Failed to send auto-response (ref: ', $body['autoResponseError']);
    }

    public function testKeywordResponseSkippedWhenNoContact(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-nc', 'conv-005', 'contact-005', 'HELP'));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-nc'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-005'],
            [['id' => 1]]
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? LIMIT 1",
            ['+15551234567'],
            []
        );

        $this->mockKeywordHandler->method('handleInboundMessage')->willReturn('Help text');
        $this->mockMessageService->expects($this->never())->method('sendToPatient');

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testInboundWithEmptyBodySkipsKeywordCheck(): void
    {
        $request = $this->makeRequest('POST', $this->makeInboundPayload('msg-empty', 'conv-006', 'contact-006', ''));

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_messages WHERE message_id = ?",
            ['msg-empty'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_conversations WHERE conversation_id = ?",
            ['conv-006'],
            [['id' => 1]]
        );

        $this->mockKeywordHandler->expects($this->never())->method('handleInboundMessage');

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    // --- MESSAGE_DELIVERY tests ---

    public function testHandlesMessageDelivery(): void
    {
        $request = $this->makeRequest('POST', [

            'message_delivery_report' => [
                'message_id' => 'msg-delivered',
                'status' => 'DELIVERED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');
        $this->assertResponseContains($response, 'messageId', 'msg-delivered');

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_messages')
        );
        $this->assertNotEmpty($updateQueries);
    }

    public function testDeliveryStatusSetsDeliveredAt(): void
    {
        $request = $this->makeRequest('POST', [

            'message_delivery_report' => ['message_id' => 'msg-d1', 'status' => 'DELIVERED'],
        ]);

        $this->controller->dispatch($request);

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_messages')
                && str_contains($q['sql'], 'delivered_at')
        );
        $this->assertNotEmpty($updateQueries);
    }

    public function testReadStatusSetsBothTimestamps(): void
    {
        $request = $this->makeRequest('POST', [

            'message_delivery_report' => ['message_id' => 'msg-r1', 'status' => 'READ'],
        ]);

        $this->controller->dispatch($request);

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_messages')
                && str_contains($q['sql'], 'read_at')
                && str_contains($q['sql'], 'delivered_at')
        );
        $this->assertNotEmpty($updateQueries);
    }

    public function testFailedStatusSetsOnlyStatus(): void
    {
        $request = $this->makeRequest('POST', [

            'message_delivery_report' => ['message_id' => 'msg-f1', 'status' => 'FAILED'],
        ]);

        $this->controller->dispatch($request);

        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter(
            $queries,
            fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_messages')
                && !str_contains($q['sql'], 'delivered_at')
        );
        $this->assertNotEmpty($updateQueries);
    }

    // --- Carrier block detection tests ---

    public function testDeliveryFailureSmpp255TriggersCarrierBlock(): void
    {
        // Mock message lookup: outbound message to +15551234567
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-255'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('setCarrierBlock')
            ->with(10, '+15551234567', 'SMPP error 255');

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(10, '+15551234567', 'carrier_block', Channel::SMS);

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-255',
                'status' => 'FAILED',
                'reason_code' => '255',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeliveryFailureSmpp61TriggersCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-61'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('setCarrierBlock')
            ->with(10, '+15551234567', 'SMPP error 61');

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(10, '+15551234567', 'carrier_block', Channel::SMS);

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-61',
                'status' => 'FAILED',
                'reason_code' => '61',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeliveryFailureSmpp151TriggersCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-151'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('setCarrierBlock')
            ->with(10, '+15551234567', 'SMPP error 151');

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(10, '+15551234567', 'carrier_block', Channel::SMS);

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-151',
                'status' => 'FAILED',
                'reason_code' => '151',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeliveryFailureOtherCodeDoesNotTriggerCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-400'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->never())->method('setCarrierBlock');
        $this->mockConsentService->expects($this->never())->method('optOut');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-400',
                'status' => 'FAILED',
                'reason_code' => '400',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeliveryFailureNoErrorCodeDoesNotTriggerCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-nocode'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->never())->method('setCarrierBlock');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-nocode',
                'status' => 'FAILED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSuccessfulDeliveryClearsCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-delivered-clear'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('getCarrierBlock')
            ->with(10, '+15551234567')
            ->willReturn(['carrier_blocked_at' => '2026-04-01', 'carrier_block_reason' => 'SMPP error 255']);

        $this->mockConsentService->expects($this->once())
            ->method('clearCarrierBlock')
            ->with(10, '+15551234567');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-delivered-clear',
                'status' => 'DELIVERED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSuccessfulDeliverySkipsClearWhenNotBlocked(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-delivered-ok'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('getCarrierBlock')
            ->with(10, '+15551234567')
            ->willReturn(null);

        $this->mockConsentService->expects($this->never())->method('clearCarrierBlock');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-delivered-ok',
                'status' => 'DELIVERED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeliveryFailureNoMessageRecordSkipsCarrierBlock(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-unknown'],
            []
        );

        $this->mockConsentService->expects($this->never())->method('setCarrierBlock');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-unknown',
                'status' => 'FAILED',
                'reason_code' => '255',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCarrierBlockUsesChannelErrorCodeFallback(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-nested'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('setCarrierBlock')
            ->with(10, '+15551234567', 'SMPP error 255');

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(10, '+15551234567', 'carrier_block', Channel::SMS);

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-nested',
                'status' => 'FAILED',
                'channel_identity' => [
                    'channel_error_code' => '255',
                ],
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCarrierBlockAppliesToAllPatientsSharingPhone(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-multi'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 5], ['patient_id' => 6], ['patient_id' => 7]]
        );

        $this->mockConsentService->expects($this->exactly(3))
            ->method('setCarrierBlock');

        $this->mockConsentService->expects($this->exactly(3))
            ->method('optOut');

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-multi',
                'status' => 'FAILED',
                'reason_code' => '255',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCarrierBlockErrorDoesNotFailWebhook(): void
    {
        QueryUtils::setMockResult(
            "SELECT to_identifier FROM oce_sinch_messages WHERE message_id = ? AND direction = 'outbound'",
            ['msg-fail-err'],
            [['to_identifier' => '+15551234567']]
        );
        QueryUtils::setMockResult(
            "SELECT DISTINCT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15551234567'],
            [['patient_id' => 10]]
        );

        $this->mockConsentService->method('setCarrierBlock')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $request = $this->makeRequest('POST', [
            'message_delivery_report' => [
                'message_id' => 'msg-fail-err',
                'status' => 'FAILED',
                'reason_code' => '255',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        // Delivery status was already recorded — webhook succeeds despite carrier block error
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');

        $logs = SystemLogger::getLogs();
        $errorLogs = array_filter($logs, fn($log) =>
            $log['level'] === 'error'
            && str_contains($log['message'], 'Carrier block detection failed')
        );
        $this->assertNotEmpty($errorLogs);
    }

    // --- OPT_OUT / OPT_IN tests ---

    public function testOptOutCallsConsentService(): void
    {
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1",
            ['contact-xyz'],
            [['patient_id' => 5]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(5, '+15559876543', 'sinch_VIBERBM');

        $request = $this->makeRequest('POST', [

            'opt_out_notification' => [
                'contact_id' => 'contact-xyz',
                'channel' => 'VIBERBM',
                'identity' => '+15559876543',
                'status' => 'OPT_OUT_SUCCEEDED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');
    }

    public function testOptOutIgnoresFailedStatus(): void
    {
        $this->mockConsentService->expects($this->never())->method('optOut');

        $request = $this->makeRequest('POST', [

            'opt_out_notification' => [
                'contact_id' => 'contact-xyz',
                'channel' => 'VIBERBM',
                'identity' => '+15559876543',
                'status' => 'OPT_OUT_FAILED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'ignored');
    }

    public function testOptOutHandlesUnknownPatient(): void
    {
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1",
            ['contact-unknown'],
            []
        );
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? ORDER BY patient_id ASC",
            ['+15559876543'],
            []
        );

        $this->mockConsentService->expects($this->never())->method('optOut');

        $request = $this->makeRequest('POST', [

            'opt_out_notification' => [
                'contact_id' => 'contact-unknown',
                'channel' => 'SMS',
                'identity' => '+15559876543',
                'status' => 'OPT_OUT_SUCCEEDED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'no_patient');
    }

    public function testOptOutFallsBackToIdentityLookup(): void
    {
        // Contact ID lookup fails
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1",
            ['contact-missing'],
            []
        );
        // Identity lookup succeeds (fetchRecords, no LIMIT)
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? ORDER BY patient_id ASC",
            ['+15559876543'],
            [['patient_id' => 7]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(7, '+15559876543', 'sinch_SMS');

        $request = $this->makeRequest('POST', [

            'opt_out_notification' => [
                'contact_id' => 'contact-missing',
                'channel' => 'SMS',
                'identity' => '+15559876543',
                'status' => 'OPT_OUT_SUCCEEDED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testOptOutAppliesToAllPatientsSharingIdentity(): void
    {
        // Contact ID lookup fails
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1",
            ['contact-shared'],
            []
        );
        // Multiple patients share this identity
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ? ORDER BY patient_id ASC",
            ['+15559876543'],
            [['patient_id' => 5], ['patient_id' => 6], ['patient_id' => 7]]
        );

        $this->mockConsentService->expects($this->exactly(3))
            ->method('optOut')
            ->willReturnCallback(function (int $pid, string $phone, string $method): void {
                $this->assertSame('+15559876543', $phone);
                $this->assertSame('sinch_SMS', $method);
                $this->assertContains($pid, [5, 6, 7]);
            });

        $request = $this->makeRequest('POST', [

            'opt_out_notification' => [
                'contact_id' => 'contact-shared',
                'channel' => 'SMS',
                'identity' => '+15559876543',
                'status' => 'OPT_OUT_SUCCEEDED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');
    }

    public function testOptInCallsConsentService(): void
    {
        QueryUtils::setMockResult(
            "SELECT patient_id FROM oce_sinch_contacts WHERE contact_id = ? LIMIT 1",
            ['contact-xyz'],
            [['patient_id' => 5]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('optIn')
            ->with(5, '+15559876543', 'sinch_VIBERBM');

        $request = $this->makeRequest('POST', [

            'opt_in_notification' => [
                'contact_id' => 'contact-xyz',
                'channel' => 'VIBERBM',
                'identity' => '+15559876543',
                'status' => 'OPT_IN_SUCCEEDED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'success');
    }

    public function testOptInIgnoresFailedStatus(): void
    {
        $this->mockConsentService->expects($this->never())->method('optIn');

        $request = $this->makeRequest('POST', [

            'opt_in_notification' => [
                'contact_id' => 'contact-xyz',
                'channel' => 'VIBERBM',
                'identity' => '+15559876543',
                'status' => 'OPT_IN_FAILED',
            ],
        ]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertResponseContains($response, 'status', 'ignored');
    }

    // --- Logging tests ---

    public function testLogsWebhookReceipt(): void
    {
        $request = $this->makeRequest('POST', ['unsupported_callback' => []]);

        $this->controller->dispatch($request);

        $logs = SystemLogger::getLogs();
        $found = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'info' && str_contains($log['message'], 'Webhook received')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected webhook receipt log');
    }

    public function testLogsEventProcessing(): void
    {
        $request = $this->makeRequest('POST', [

            'message_delivery_report' => ['message_id' => 'x', 'status' => 'QUEUED'],
        ]);

        $this->controller->dispatch($request);

        $logs = SystemLogger::getLogs();
        $found = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'info' && str_contains($log['message'], 'Processing webhook event')) {
                $this->assertArrayHasKey('context', $log);
                $this->assertIsArray($log['context']);
                $this->assertArrayHasKey('eventType', $log['context']);
                $this->assertSame('message_delivery_report', $log['context']['eventType']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected event processing log');
    }

    // --- Helpers ---

    /**
     * Create a Request with JSON body and HMAC-SHA256 signature headers
     *
     * @param array<string, mixed> $payload
     */
    private function makeRequest(
        string $method,
        array $payload,
        ?string $secret = self::TEST_SECRET
    ): Request {
        $body = json_encode($payload) ?: '{}';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $signedData = $body . '.' . $nonce . '.' . $timestamp;
            $signature = base64_encode(hash_hmac('sha256', $signedData, $secret, true));
            $headers['HTTP_X_SINCH_WEBHOOK_SIGNATURE'] = $signature;
            $headers['HTTP_X_SINCH_WEBHOOK_SIGNATURE_TIMESTAMP'] = $timestamp;
            $headers['HTTP_X_SINCH_WEBHOOK_SIGNATURE_NONCE'] = $nonce;
            $headers['HTTP_X_SINCH_WEBHOOK_SIGNATURE_ALGORITHM'] = 'HmacSHA256';
        }

        return Request::create('/webhook', $method, [], [], [], $headers, $body);
    }

    /**
     * Build a standard MESSAGE_INBOUND payload
     *
     * @return array<string, mixed>
     */
    private function makeInboundPayload(
        string $messageId,
        string $conversationId,
        string $contactId,
        string $text
    ): array {
        return [
            'message' => [
                'id' => $messageId,
                'conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'direction' => 'TO_APP',
                'channel_identity' => [
                    'channel' => 'SMS',
                    'identity' => '+15551234567',
                ],
                'contact_message' => [
                    'text_message' => [
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * Assert a JSON response contains a key with expected value
     */
    private function assertResponseContains(Response $response, string $key, string $expected): void
    {
        $content = json_decode($response->getContent() ?: '', true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey($key, $content);
        $this->assertStringContainsString($expected, (string) $content[$key]);
    }
}
