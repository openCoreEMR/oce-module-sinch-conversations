<?php

/**
 * Unit tests for ConversationApiClient
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConversationApiClientTest extends TestCase
{
    private const PROJECT_ID = 'test-project';
    private const APP_ID = 'test-app';
    private const API_KEY = 'test-key';
    private const API_SECRET = 'test-secret';

    private GlobalConfig&MockObject $mockConfig;

    /** @var array<int, array<string, mixed>> */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->mockConfig = $this->createMock(GlobalConfig::class);
        $this->mockConfig->method('getSinchProjectId')->willReturn(self::PROJECT_ID);
        $this->mockConfig->method('getSinchAppId')->willReturn(self::APP_ID);
        $this->mockConfig->method('getSinchApiKey')->willReturn(self::API_KEY);
        $this->mockConfig->method('getSinchApiSecret')->willReturn(self::API_SECRET);
        $this->mockConfig->method('getSinchRegion')->willReturn('us');

        $this->requestHistory = [];
    }

    // --- sendMessage tests ---

    public function testSendMessageSuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'message_id' => 'msg-123',
            ]) ?: '{}'),
        ]);

        $result = $client->sendMessage('contact-001', 'Hello');

        $this->assertSame('msg-123', $result['message_id']);
        $this->assertCount(1, $this->requestHistory);
        $this->assertSame('POST', $this->requestHistory[0]['request']->getMethod());
        $this->assertStringContainsString('/messages:send', (string) $this->requestHistory[0]['request']->getUri());
    }

    public function testSendMessageIncludesSmsChannelProperties(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"message_id": "msg-123"}'),
        ]);

        $client->sendMessage('contact-001', 'Hello', [
            'sender' => '+15551234567',
            'channel' => 'SMS',
        ]);

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertSame('+15551234567', $body['channel_properties']['SMS_SENDER']);
    }

    public function testSendMessageIncludesMetadata(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"message_id": "msg-123"}'),
        ]);

        $client->sendMessage('contact-001', 'Hello', [
            'metadata' => 'test-meta',
        ]);

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertSame('test-meta', $body['metadata']);
    }

    public function testSendMessageThrowsOnApiError(): void
    {
        $client = $this->createClient([
            new Response(400, [], json_encode([
                'error' => ['message' => 'Bad request'],
            ]) ?: '{}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Bad request');
        $client->sendMessage('contact-001', 'Hello');
    }

    // --- sendMessageByChannelIdentity tests ---

    public function testSendByChannelIdentitySuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"message_id": "msg-456"}'),
        ]);

        $result = $client->sendMessageByChannelIdentity('+15551234567', 'Hello');

        $this->assertSame('msg-456', $result['message_id']);
        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertSame('+15551234567', $body['recipient']['identified_by']['channel_identities'][0]['identity']);
        $this->assertSame('SMS', $body['recipient']['identified_by']['channel_identities'][0]['channel']);
    }

    public function testSendByChannelIdentityWithSmsSender(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"message_id": "msg-456"}'),
        ]);

        $client->sendMessageByChannelIdentity('+15551234567', 'Hello', 'SMS', [
            'sender' => '+15559876543',
        ]);

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertSame('+15559876543', $body['channel_properties']['SMS_SENDER']);
    }

    public function testSendByChannelIdentityWhatsApp(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"message_id": "msg-789"}'),
        ]);

        $client->sendMessageByChannelIdentity('+15551234567', 'Hello', 'WHATSAPP');

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertSame('WHATSAPP', $body['recipient']['identified_by']['channel_identities'][0]['channel']);
    }

    // --- getConversationMessages tests ---

    public function testGetConversationMessages(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'messages' => [['id' => 'msg-1'], ['id' => 'msg-2']],
            ]) ?: '{}'),
        ]);

        $result = $client->getConversationMessages('conv-001');

        $this->assertCount(2, $result);
        $this->assertStringContainsString('/conversations/conv-001/messages', (string) $this->requestHistory[0]['request']->getUri());
    }

    // --- getMessage tests ---

    public function testGetMessage(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"id": "msg-001", "direction": "TO_APP"}'),
        ]);

        $result = $client->getMessage('msg-001');

        $this->assertSame('msg-001', $result['id']);
        $this->assertStringContainsString('/messages/msg-001', (string) $this->requestHistory[0]['request']->getUri());
    }

    // --- createContact tests ---

    public function testCreateContact(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"contact_id": "new-contact"}'),
        ]);

        $result = $client->createContact('+15551234567', 'SMS');

        $this->assertSame('new-contact', $result['contact_id']);
        $this->assertSame('POST', $this->requestHistory[0]['request']->getMethod());
    }

    // --- getApp tests ---

    public function testGetApp(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'id' => self::APP_ID,
                'display_name' => 'Test App',
            ]) ?: '{}'),
        ]);

        $result = $client->getApp(self::APP_ID);

        $this->assertSame('Test App', $result['display_name']);
    }

    public function testGetAppThrowsOnEmpty(): void
    {
        $client = $this->createClient([]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('App ID is not configured');
        $client->getApp('');
    }

    // --- testConnection tests ---

    public function testTestConnectionSuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"messages": []}'),
        ]);

        $result = $client->testConnection();

        $this->assertTrue($result);
    }

    public function testTestConnectionThrowsOnEmptyProjectId(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('getSinchProjectId')->willReturn('');
        $mockConfig->method('getSinchApiKey')->willReturn(self::API_KEY);
        $mockConfig->method('getSinchApiSecret')->willReturn(self::API_SECRET);
        $mockConfig->method('getSinchRegion')->willReturn('us');

        $client = $this->createClientWithConfig($mockConfig, []);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Project ID is not configured');
        $client->testConnection();
    }

    // --- handleResponse error tests ---

    public function testApiErrorIncludesMessage(): void
    {
        $client = $this->createClient([
            new Response(403, [], json_encode([
                'error' => ['message' => 'Forbidden'],
            ]) ?: '{}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Forbidden');
        $client->getMessage('msg-001');
    }

    public function testApiErrorWithUnknownMessage(): void
    {
        $client = $this->createClient([
            new Response(500, [], '{}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unknown API error');
        $client->getMessage('msg-001');
    }

    // --- consent tests ---

    public function testGetConsentStatusFound(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'identities' => [['identity' => '15551234567']],
                'next_page_token' => '',
            ]) ?: '{}'),
        ]);

        $result = $client->getConsentStatus(self::APP_ID, '+15551234567');

        $this->assertSame('15551234567', $result['identity']);
    }

    public function testGetConsentStatusNotFound(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'identities' => [['identity' => '15559999999']],
                'next_page_token' => '',
            ]) ?: '{}'),
        ]);

        $result = $client->getConsentStatus(self::APP_ID, '+15551234567');

        $this->assertSame([], $result);
    }

    public function testGetConsentStatus404LazyList(): void
    {
        $client = $this->createClient([
            new Response(404, [], json_encode([
                'error' => [
                    'message' => "ListType for projectId 'x', appId 'y' and type 'OPT_OUT_ALL' does not exist",
                ],
            ]) ?: '{}'),
        ]);

        $result = $client->getConsentStatus(self::APP_ID, '+15551234567');

        $this->assertSame([], $result);
    }

    public function testGetConsentStatusThrowsOnEmptyAppId(): void
    {
        $client = $this->createClient([]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('App ID is required');
        $client->getConsentStatus('', '+15551234567');
    }

    public function testListOptOutsSuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'identities' => [
                    ['identity' => '15551111111'],
                    ['identity' => '15552222222'],
                ],
                'next_page_token' => '',
            ]) ?: '{}'),
        ]);

        $result = $client->listOptOuts(self::APP_ID);

        $this->assertCount(2, $result);
    }

    public function testListOptOutsPagination(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode([
                'identities' => [['identity' => '15551111111']],
                'next_page_token' => 'page2token',
            ]) ?: '{}'),
            new Response(200, [], json_encode([
                'identities' => [['identity' => '15552222222']],
                'next_page_token' => '',
            ]) ?: '{}'),
        ]);

        $result = $client->listOptOuts(self::APP_ID);

        $this->assertCount(2, $result);
        $this->assertCount(2, $this->requestHistory);
    }

    // --- Auth header tests ---

    public function testRequestsIncludeBearerToken(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"id": "msg-001"}'),
        ]);

        $client->getMessage('msg-001');

        $authHeader = $this->requestHistory[0]['request']->getHeaderLine('Authorization');
        $this->assertSame('Bearer test-token', $authHeader);
    }

    // --- OAuth tests ---

    public function testGetOAuth2TokenSuccess(): void
    {
        $client = $this->createClientWithoutToken([
            new Response(200, [], json_encode([
                'access_token' => 'fresh-token-123',
            ]) ?: '{}'),
        ]);

        $token = $client->getOAuth2Token();

        $this->assertSame('fresh-token-123', $token);
        $this->assertStringContainsString('auth.sinch.com/oauth2/token', (string) $this->requestHistory[0]['request']->getUri());
    }

    public function testGetOAuth2TokenThrowsOnMissingCredentials(): void
    {
        $mockConfig = $this->createMock(GlobalConfig::class);
        $mockConfig->method('getSinchApiKey')->willReturn('');
        $mockConfig->method('getSinchApiSecret')->willReturn('');
        $mockConfig->method('getSinchRegion')->willReturn('us');

        $client = $this->createClientWithConfig($mockConfig, [], false);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('API Key ID and Secret are required');
        $client->getOAuth2Token();
    }

    public function testGetOAuth2TokenThrowsOnAuthError(): void
    {
        $client = $this->createClientWithoutToken([
            new Response(401, [], json_encode([
                'error' => 'invalid_client',
                'error_description' => 'Bad credentials',
            ]) ?: '{}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Bad credentials');
        $client->getOAuth2Token();
    }

    public function testGetOAuth2TokenThrowsOnEmptyToken(): void
    {
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": ""}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('No access token');
        $client->getOAuth2Token();
    }

    public function testGetHeadersCachesToken(): void
    {
        // First call: OAuth token request + API call
        // Second call: only API call (token cached)
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "cached-token"}'),
            new Response(200, [], '{"id": "msg-001"}'),
            new Response(200, [], '{"id": "msg-002"}'),
        ]);

        $client->getMessage('msg-001');
        $client->getMessage('msg-002');

        // 3 total requests: 1 OAuth + 2 API calls (token reused)
        $this->assertCount(3, $this->requestHistory);
    }

    // --- Template tests ---

    public function testListTemplatesSuccess(): void
    {
        // OAuth token + template list
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "tmpl-token"}'),
            new Response(200, [], json_encode([
                'templates' => [
                    ['id' => 'tmpl-1', 'description' => 'Template 1'],
                    ['id' => 'tmpl-2', 'description' => 'Template 2'],
                ],
            ]) ?: '{}'),
        ]);

        $result = $client->listTemplates();

        $this->assertCount(2, $result);
        $this->assertStringContainsString('template.api.sinch.com', (string) $this->requestHistory[1]['request']->getUri());
    }

    public function testCreateTemplateSuccess(): void
    {
        // OAuth token + template create
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "tmpl-token"}'),
            new Response(200, [], '{"id": "new-tmpl"}'),
        ]);

        $result = $client->createTemplate([
            'template_name' => 'test_template',
            'body' => 'Hello {{ patient_name }}',
            'required_variables' => ['patient_name'],
        ]);

        $this->assertSame('new-tmpl', $result['id']);
        $body = json_decode((string) $this->requestHistory[1]['request']->getBody(), true);
        $this->assertSame('en-US', $body['default_translation']);
        $this->assertStringContainsString('{{patient_name}}', $body['translations'][0]['text_message']['text']);
    }

    /**
     * Pin the Sinch Templates v2 request payload shape.
     *
     * v2 takes message body fields (e.g. `text_message`) as direct fields on
     * the translation object, not wrapped in a `message` envelope. Sinch
     * rejects the wrapped shape with HTTP 400 "Translation must specify a
     * message." See https://developers.sinch.com/docs/conversation/templates.md
     */
    public function testCreateTemplateUsesV2TranslationShape(): void
    {
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "tmpl-token"}'),
            new Response(200, [], '{"id": "tmpl-shape"}'),
        ]);

        $client->createTemplate([
            'template_key' => 'shape_test',
            'template_name' => 'Shape Test',
            'description' => 'Pin v2 shape',
            'body' => 'Hello {{ name }}',
            'required_variables' => ['name'],
        ]);

        $body = json_decode((string) $this->requestHistory[1]['request']->getBody(), true);

        $this->assertSame('Pin v2 shape', $body['description']);
        $this->assertSame('en-US', $body['default_translation']);
        $this->assertCount(1, $body['translations']);

        $translation = $body['translations'][0];
        $this->assertSame('en-US', $translation['language_code']);
        $this->assertSame('1', $translation['version']);
        $this->assertSame([['key' => 'name', 'preview_value' => 'Name']], $translation['variables']);

        $this->assertArrayHasKey('text_message', $translation, 'text_message must be a direct field on the translation');
        $this->assertSame('Hello {{name}}', $translation['text_message']['text']);
        $this->assertArrayNotHasKey('message', $translation, 'v2 does not accept a message envelope');
    }

    // --- Retry logic tests ---

    public function testExecuteWithRetryRetriesOn429(): void
    {
        // OAuth + 429 + success
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "retry-token"}'),
            new Response(429, [], '{}'),
            new Response(200, [], '{"id": "tmpl-after-retry"}'),
        ]);

        $result = $client->createTemplate([
            'template_name' => 'retry_test',
            'body' => 'Hello',
        ]);

        $this->assertSame('tmpl-after-retry', $result['id']);
        // 3 requests: OAuth + 429 retry + success
        $this->assertCount(3, $this->requestHistory);
    }

    public function testExecuteWithRetryRetriesOn500(): void
    {
        // OAuth + 500 + success
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "retry-token"}'),
            new Response(500, [], '{}'),
            new Response(200, [], '{"id": "tmpl-after-500"}'),
        ]);

        $result = $client->createTemplate([
            'template_name' => 'retry_test',
            'body' => 'Hello',
        ]);

        $this->assertSame('tmpl-after-500', $result['id']);
    }

    public function testExecuteWithRetryReturnsClientError(): void
    {
        // OAuth + 400 (no retry for client errors)
        $client = $this->createClientWithoutToken([
            new Response(200, [], '{"access_token": "retry-token"}'),
            new Response(400, [], json_encode([
                'error' => ['message' => 'Bad template format'],
            ]) ?: '{}'),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Bad template format');
        $client->createTemplate([
            'template_name' => 'bad_template',
            'body' => '',
        ]);
    }

    // --- Helpers ---

    /**
     * Create a ConversationApiClient with mocked HTTP responses and pre-seeded auth token
     *
     * @param array<int, Response> $responses
     */
    private function createClient(array $responses): ConversationApiClient
    {
        return $this->createClientWithConfig($this->mockConfig, $responses);
    }

    /**
     * Create a client without a pre-seeded token (for OAuth/template tests)
     *
     * @param array<int, Response> $responses
     */
    private function createClientWithoutToken(array $responses): ConversationApiClient
    {
        return $this->createClientWithConfig($this->mockConfig, $responses, false);
    }

    /**
     * @param array<int, Response> $responses
     */
    private function createClientWithConfig(
        GlobalConfig&MockObject $config,
        array $responses,
        bool $seedToken = true
    ): ConversationApiClient {
        $mock = new MockHandler($responses);
        $history = Middleware::history($this->requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);

        $httpClient = new Client([
            'base_uri' => 'https://us.conversation.api.sinch.com',
            'handler' => $stack,
            'http_errors' => false,
        ]);
        $client = new ConversationApiClient($config, $httpClient);

        if ($seedToken) {
            $reflection = new \ReflectionClass($client);
            $tokenProperty = $reflection->getProperty('cachedAccessToken');
            $tokenProperty->setValue($client, 'test-token');
        }

        return $client;
    }
}
