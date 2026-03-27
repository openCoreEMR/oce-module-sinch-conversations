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

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Controller;

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
        $this->mockConfig->method('verifyWebhookAuth')->willReturn(true);

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

        $request = $this->makeRequest('POST', ['trigger' => 'MESSAGE_INBOUND']);

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

        $request = $this->makeRequest('POST', ['trigger' => 'MESSAGE_INBOUND']);

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns401WhenCredentialsInvalid(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('isIpInAllowlist')->willReturn(true);
        $mockConfig->method('isWebhookAuthConfigured')->willReturn(true);
        $mockConfig->method('verifyWebhookAuth')->willReturn(false);

        $controller = new WebhookController(
            $mockConfig,
            $this->mockKeywordHandler,
            $this->mockMessageService,
            $this->mockConsentService
        );

        $request = $this->makeRequest('POST', ['trigger' => 'MESSAGE_INBOUND'], 'wrong', 'wrong');

        $response = $controller->dispatch($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAcceptsValidAuth(): void
    {
        $request = $this->makeRequest('POST', ['trigger' => 'UNKNOWN_EVENT']);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    // --- Payload parsing tests ---

    public function testReturns400ForEmptyPayload(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'PHP_AUTH_USER' => 'user',
            'PHP_AUTH_PW' => 'pass',
        ], '');

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturns400ForInvalidJson(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'PHP_AUTH_USER' => 'user',
            'PHP_AUTH_PW' => 'pass',
        ], '{invalid json{{{');

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testReturns400ForMissingTrigger(): void
    {
        $request = $this->makeRequest('POST', ['message' => ['id' => 'test']]);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertResponseContains($response, 'error', 'Missing trigger type');
    }

    public function testReturns400ForEmptyTrigger(): void
    {
        $request = $this->makeRequest('POST', ['trigger' => '']);

        $response = $this->controller->dispatch($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // --- Unknown event handling ---

    public function testHandlesUnknownEventGracefully(): void
    {
        $request = $this->makeRequest('POST', ['trigger' => 'CONVERSATION_START']);

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
            'trigger' => 'MESSAGE_DELIVERY',
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
            'trigger' => 'MESSAGE_DELIVERY',
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
            'trigger' => 'MESSAGE_DELIVERY',
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
            'trigger' => 'MESSAGE_DELIVERY',
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
            'trigger' => 'OPT_OUT',
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
            'trigger' => 'OPT_OUT',
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
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15559876543'],
            []
        );

        $this->mockConsentService->expects($this->never())->method('optOut');

        $request = $this->makeRequest('POST', [
            'trigger' => 'OPT_OUT',
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
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
            ['+15559876543'],
            [['patient_id' => 7]]
        );

        $this->mockConsentService->expects($this->once())
            ->method('optOut')
            ->with(7, '+15559876543', 'sinch_SMS');

        $request = $this->makeRequest('POST', [
            'trigger' => 'OPT_OUT',
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
            "SELECT patient_id FROM oce_sinch_contacts WHERE channel_identity = ?",
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
            'trigger' => 'OPT_OUT',
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
            'trigger' => 'OPT_IN',
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
            'trigger' => 'OPT_IN',
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
        $request = $this->makeRequest('POST', ['trigger' => 'UNKNOWN_EVENT']);

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
            'trigger' => 'MESSAGE_DELIVERY',
            'message_delivery_report' => ['message_id' => 'x', 'status' => 'QUEUED'],
        ]);

        $this->controller->dispatch($request);

        $logs = SystemLogger::getLogs();
        $found = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'info' && str_contains($log['message'], 'Processing webhook event')) {
                $this->assertArrayHasKey('context', $log);
                $this->assertIsArray($log['context']);
                $this->assertArrayHasKey('trigger', $log['context']);
                $this->assertSame('MESSAGE_DELIVERY', $log['context']['trigger']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected event processing log');
    }

    // --- Helpers ---

    /**
     * Create a Request with JSON body and Basic Auth
     *
     * @param array<string, mixed> $payload
     */
    private function makeRequest(
        string $method,
        array $payload,
        string $user = 'test_user',
        string $pass = 'test_pass'
    ): Request {
        return Request::create(
            '/webhook',
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'PHP_AUTH_USER' => $user,
                'PHP_AUTH_PW' => $pass,
            ],
            json_encode($payload) ?: '{}'
        );
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
            'trigger' => 'MESSAGE_INBOUND',
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
