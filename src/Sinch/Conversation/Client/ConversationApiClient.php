<?php

/**
 * Sinch Conversations API Client
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenCoreEMR\Modules\SinchConversations\Common\ArrayPath;
use OpenCoreEMR\Modules\SinchConversations\Common\Json;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenEMR\Common\Logging\SystemLogger;

class ConversationApiClient
{
    private const BASE_URL = 'https://us.conversation.api.sinch.com';
    private const CONSENT_MAX_PAGES = 100;
    private readonly Client $httpClient;
    private readonly SystemLogger $logger;
    private ?string $cachedAccessToken = null;

    public function __construct(
        private readonly GlobalConfig $config,
        ?Client $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'http_errors' => false,
        ]);
        $this->logger = new SystemLogger();
    }

    /**
     * Send a message to a contact
     *
     * @param string $contactId Sinch contact ID
     * @param string $message Message text
     * @param array<string, mixed> $options Additional options (channel, media, etc)
     * @return array<string, mixed> Response data
     * @throws ApiException
     */
    public function sendMessage(string $contactId, string $message, array $options = []): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $this->config->getSinchAppId();

        $payload = [
            'app_id' => $appId,
            'recipient' => [
                'contact_id' => $contactId,
            ],
            'message' => [
                'text_message' => [
                    'text' => $message,
                ],
            ],
        ];

        // Add sender/originator for SMS if specified
        if (isset($options['sender']) && isset($options['channel']) && $options['channel'] === 'SMS') {
            $payload['channel_properties'] = [
                'SMS_SENDER' => $options['sender'],
            ];
        }

        if (isset($options['channel_priority'])) {
            $payload['channel_priority_order'] = $options['channel_priority'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        try {
            $this->logger->debug(
                "Sending message via Sinch",
                [
                    'endpoint' => "/v1/projects/{$projectId}/messages:send",
                    'payload' => $payload,
                ]
            );

            $response = $this->httpClient->post(
                "/v1/projects/{$projectId}/messages:send",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $payload,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to send message', ['exception' => $e]);
            throw new ApiException('Failed to send message', 0, $e);
        }
    }

    /**
     * Send a message using channel identity (for DISPATCH mode apps)
     *
     * @param string $channelIdentity Phone number, WhatsApp ID, etc
     * @param string $message Message text
     * @param string $channel SMS, WHATSAPP, RCS, etc
     * @param array<string, mixed> $options Additional options (metadata, etc)
     * @return array<string, mixed> Response data
     * @throws ApiException
     */
    public function sendMessageByChannelIdentity(
        string $channelIdentity,
        string $message,
        string $channel = 'SMS',
        array $options = []
    ): array {
        $projectId = $this->config->getSinchProjectId();
        $appId = $this->config->getSinchAppId();

        $payload = [
            'app_id' => $appId,
            'recipient' => [
                'identified_by' => [
                    'channel_identities' => [
                        [
                            'channel' => $channel,
                            'identity' => $channelIdentity,
                        ],
                    ],
                ],
            ],
            'message' => [
                'text_message' => [
                    'text' => $message,
                ],
            ],
        ];

        // Add sender/originator for SMS if specified
        if ($channel === 'SMS' && isset($options['sender'])) {
            $payload['channel_properties'] = [
                'SMS_SENDER' => $options['sender'],
            ];
        }

        if (isset($options['channel_priority'])) {
            $payload['channel_priority_order'] = $options['channel_priority'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        try {
            $this->logger->debug(
                "Sending message via Sinch (DISPATCH mode)",
                [
                    'endpoint' => "/v1/projects/{$projectId}/messages:send",
                    'payload' => $payload,
                ]
            );

            $response = $this->httpClient->post(
                "/v1/projects/{$projectId}/messages:send",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $payload,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to send message (DISPATCH)', ['exception' => $e]);
            throw new ApiException('Failed to send message', 0, $e);
        }
    }

    /**
     * Get messages for a conversation
     *
     * @param string $conversationId
     * @param array<string, mixed> $filters start_time, page_size, etc
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getConversationMessages(string $conversationId, array $filters = []): array
    {
        $projectId = $this->config->getSinchProjectId();

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/conversations/{$conversationId}/messages",
                [
                    'headers' => $this->getHeaders(),
                    'query' => $filters,
                ]
            );

            $data = $this->handleResponse($response);
            return $data['messages'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get conversation messages', ['exception' => $e]);
            throw new ApiException('Failed to get conversation messages', 0, $e);
        }
    }

    /**
     * Get a specific message by ID
     *
     * @param string $messageId
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getMessage(string $messageId): array
    {
        $projectId = $this->config->getSinchProjectId();

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/messages/{$messageId}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get message', ['exception' => $e]);
            throw new ApiException('Failed to get message', 0, $e);
        }
    }

    /**
     * List messages with filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listMessages(array $filters = []): array
    {
        $projectId = $this->config->getSinchProjectId();

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/messages",
                [
                    'headers' => $this->getHeaders(),
                    'query' => $filters,
                ]
            );

            $data = $this->handleResponse($response);
            return $data['messages'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to list messages', ['exception' => $e]);
            throw new ApiException('Failed to list messages', 0, $e);
        }
    }

    /**
     * Create or update a contact
     *
     * @param string $channelIdentity Phone number, WhatsApp ID, etc
     * @param string $channel SMS, WHATSAPP, RCS, etc
     * @param array<string, mixed> $options display_name, metadata, etc
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function createContact(string $channelIdentity, string $channel = 'SMS', array $options = []): array
    {
        $projectId = $this->config->getSinchProjectId();

        $payload = [
            'channel_identities' => [
                [
                    'channel' => $channel,
                    'identity' => $channelIdentity,
                ],
            ],
        ];

        if (isset($options['display_name'])) {
            $payload['display_name'] = $options['display_name'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        try {
            $this->logger->debug(
                "Creating contact in Sinch",
                [
                    'endpoint' => "/v1/projects/{$projectId}/contacts",
                    'payload' => $payload,
                ]
            );

            $response = $this->httpClient->post(
                "/v1/projects/{$projectId}/contacts",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $payload,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create contact', ['exception' => $e]);
            throw new ApiException('Failed to create contact', 0, $e);
        }
    }

    /**
     * Get contact by ID
     *
     * @param string $contactId
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getContact(string $contactId): array
    {
        $projectId = $this->config->getSinchProjectId();

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/contacts/{$contactId}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get contact', ['exception' => $e]);
            throw new ApiException('Failed to get contact', 0, $e);
        }
    }

    /**
     * Get app configuration details
     *
     * @param string|null $appId App ID (uses configured app ID if not provided)
     * @return array<string, mixed> App configuration
     * @throws ApiException
     */
    public function getApp(?string $appId = null): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is not configured");
        }

        try {
            $this->logger->debug("Fetching app configuration", ['app_id' => $appId]);

            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/apps/{$appId}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get app configuration', ['exception' => $e]);
            throw new ApiException('Failed to get app configuration', 0, $e);
        }
    }

    /**
     * Test API connection by making a lightweight request
     *
     * @return bool True if connection successful
     * @throws ApiException
     */
    public function testConnection(): bool
    {
        $projectId = $this->config->getSinchProjectId();

        if (empty($projectId)) {
            throw new ApiException("Project ID is not configured");
        }

        try {
            // Make a lightweight request to verify credentials
            $headers = $this->getHeaders();
            $this->logger->debug(
                "Making API test request to Sinch",
                [
                    'project_id' => $projectId,
                    'endpoint' => "/v1/projects/{$projectId}/messages",
                    'headers' => array_merge(
                        $headers,
                        ['Authorization' => 'Bearer ***'] // Mask the token
                    ),
                    'query' => ['page_size' => 1],
                ]
            );

            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/messages",
                [
                    'headers' => $headers,
                    'query' => ['page_size' => 1],
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $responseHeaders = $response->getHeaders();

            $this->logger->debug(
                "Sinch API test response received",
                [
                    'status_code' => $statusCode,
                    'headers' => $responseHeaders,
                    'body' => $body,
                ]
            );

            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            // Handle error responses
            try {
                $errorMessage = ArrayPath::stringAt(Json::decode($body), 'error', 'message')
                    ?? 'Authentication failed';
            } catch (\JsonException) {
                // Body was not JSON; fall through to header-based message extraction.
                $errorMessage = 'Authentication failed';
            }

            // Check WWW-Authenticate header for additional error details
            $wwwAuth = $responseHeaders['www-authenticate'][0] ?? '';
            if (!empty($wwwAuth) && str_contains($wwwAuth, 'error_description=')) {
                // Extract error description from WWW-Authenticate header
                if (preg_match('/error_description="([^"]+)"/', $wwwAuth, $matches)) {
                    $errorMessage = $matches[1];
                }
            }

            throw new ApiException(
                "API authentication failed: {$errorMessage}",
                $statusCode
            );
        } catch (GuzzleException $e) {
            $this->logger->error('Sinch API connection test failed', ['exception' => $e]);
            throw new ApiException('Connection test failed', 0, $e);
        }
    }

    /**
     * Get OAuth2 access token
     *
     * @return string Access token
     * @throws ApiException
     */
    public function getOAuth2Token(): string
    {
        $region = $this->config->getSinchRegion();
        $keyId = $this->config->getSinchApiKey();
        $keySecret = $this->config->getSinchApiSecret();

        if (empty($keyId) || empty($keySecret)) {
            throw new ApiException("API Key ID and Secret are required for OAuth2 authentication");
        }

        try {
            $this->logger->debug("Requesting OAuth2 token from Sinch");

            $response = $this->httpClient->post(
                "https://{$region}.auth.sinch.com/oauth2/token",
                [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                    ],
                    'auth' => [$keyId, $keySecret],
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($statusCode !== 200) {
                try {
                    $errorMessage = ArrayPath::firstNonEmptyString(Json::decode($body), 'error_description', 'error')
                        ?? 'Failed to get OAuth2 token';
                } catch (\JsonException) {
                    $errorMessage = 'Failed to get OAuth2 token';
                }
                throw new ApiException("OAuth2 authentication failed: {$errorMessage}", $statusCode);
            }

            try {
                $accessToken = ArrayPath::stringAt(Json::decode($body), 'access_token');
            } catch (\JsonException $e) {
                throw new ApiException('Malformed OAuth2 response', $statusCode, $e);
            }

            if ($accessToken === null || $accessToken === '') {
                throw new ApiException("No access token in OAuth2 response");
            }

            $this->logger->debug("OAuth2 token obtained successfully");
            return $accessToken;
        } catch (GuzzleException $e) {
            $this->logger->error('OAuth2 request failed', ['exception' => $e]);
            throw new ApiException('OAuth2 request failed', 0, $e);
        }
    }

    /**
     * Create a template in Sinch Template Management API
     *
     * @param array<string, mixed> $templateData Template definition
     * @return array<string, mixed> Created template with ID
     * @throws ApiException
     */
    public function createTemplate(array $templateData): array
    {
        $projectId = $this->config->getSinchProjectId();
        $region = $this->config->getSinchRegion();
        $accessToken = $this->getOAuth2Token();

        try {
            $this->logger->debug(
                "Creating template in Sinch",
                ['template_key' => $templateData['template_key'] ?? 'unknown']
            );

            $response = $this->executeWithRetry(
                fn(): \Psr\Http\Message\ResponseInterface => $this->httpClient->post(
                    "https://{$region}.template.api.sinch.com/v2/projects/{$projectId}/templates",
                    [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => "Bearer {$accessToken}",
                        ],
                        'json' => $this->formatTemplateForSinch($templateData),
                    ]
                )
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create template', ['exception' => $e]);
            throw new ApiException('Failed to create template', 0, $e);
        }
    }

    /**
     * List templates from Sinch
     *
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listTemplates(): array
    {
        $projectId = $this->config->getSinchProjectId();
        $region = $this->config->getSinchRegion();
        $accessToken = $this->getOAuth2Token();

        try {
            $response = $this->httpClient->get(
                "https://{$region}.template.api.sinch.com/v2/projects/{$projectId}/templates",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$accessToken}",
                    ],
                ]
            );

            $data = $this->handleResponse($response);
            return $data['templates'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to list templates', ['exception' => $e]);
            throw new ApiException('Failed to list templates', 0, $e);
        }
    }

    /**
     * Format template data for Sinch API v2
     *
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>
     */
    private function formatTemplateForSinch(array $templateData): array
    {
        // Extract variables from template body
        $variables = [];
        foreach ($templateData['required_variables'] ?? [] as $varName) {
            $variables[] = [
                'key' => $varName,
                'preview_value' => ucwords(str_replace('_', ' ', $varName)),
            ];
        }

        // Sinch Templates v2 takes the message body fields (e.g. `text_message`)
        // as direct fields on the translation object, not wrapped in a `message`
        // envelope. See https://developers.sinch.com/docs/conversation/templates.md
        // (Version 2 example).
        return [
            'description' => $templateData['description'] ?? $templateData['template_name'],
            'default_translation' => 'en-US',
            'translations' => [
                [
                    'language_code' => 'en-US',
                    'version' => '1',
                    'variables' => $variables,
                    'text_message' => [
                        'text' => self::normalizeTemplateBody($templateData['body']),
                    ],
                ],
            ],
        ];
    }

    /**
     * Normalize a template body to the exact text Sinch will receive:
     * `{{ variable_name }}` → `{{variable_name}}` (Sinch's required form).
     *
     * Exposed publicly so that anything which needs to reason about the
     * payload Sinch actually stores (e.g. content-versioned descriptions
     * in TemplateSyncService) hashes the same string this client sends.
     */
    public static function normalizeTemplateBody(string $body): string
    {
        return preg_replace('/\{\{\s*(\w+)\s*\}\}/', '{{$1}}', $body) ?? $body;
    }

    /**
     * Execute HTTP request with retry logic and exponential backoff
     *
     * @param callable(): \Psr\Http\Message\ResponseInterface $requestCallback Function that performs the HTTP request
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $initialDelayMs Initial delay in milliseconds
     * @return \Psr\Http\Message\ResponseInterface
     * @throws ApiException
     */
    private function executeWithRetry(
        callable $requestCallback,
        int $maxRetries = 3,
        int $initialDelayMs = 100
    ): \Psr\Http\Message\ResponseInterface {
        $attempt = 0;
        $delayMs = $initialDelayMs;

        while ($attempt <= $maxRetries) {
            try {
                $response = $requestCallback();
                $statusCode = $response->getStatusCode();

                // Success - return immediately
                if ($statusCode >= 200 && $statusCode < 300) {
                    if ($attempt > 0) {
                        $this->logger->info('Request succeeded after retries', ['attempts' => $attempt]);
                    }
                    return $response;
                }

                // Rate limit or server error - retry
                if ($statusCode === 429 || $statusCode >= 500) {
                    if ($attempt < $maxRetries) {
                        $this->logger->warning('Retrying failed request', [
                            'status_code' => $statusCode,
                            'delay_ms' => $delayMs,
                            'attempt' => $attempt + 1,
                            'max_retries' => $maxRetries,
                        ]);
                        usleep($delayMs * 1000); // Convert ms to microseconds
                        $delayMs *= 2; // Exponential backoff
                        $attempt++;
                        continue;
                    }
                }

                // Client error or final attempt - return response
                return $response;
            } catch (GuzzleException $e) {
                if ($attempt < $maxRetries) {
                    $this->logger->warning('Retrying after exception', [
                        'exception' => $e,
                        'delay_ms' => $delayMs,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                    ]);
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }

        // This should never be reached, but just in case
        throw new ApiException("Request failed after {$maxRetries} retries");
    }

    /**
     * Get the consent status for a phone number
     *
     * Queries the Sinch Consent Management API using the validated
     * `/consents/{list_type}` endpoint structure. Queries the OPT_OUT_ALL
     * list and searches for the given identity across all pages.
     *
     * @return array<string, mixed> Consent data or empty array if not found
     * @throws ApiException
     */
    public function getConsentStatus(string $appId, string $channelIdentity): array
    {
        if ($appId === '') {
            throw new ApiException('App ID is required to get consent status');
        }

        $projectId = $this->config->getSinchProjectId();
        $listType = 'OPT_OUT_ALL';
        $endpoint = "/v1/projects/{$projectId}/apps/{$appId}/consents/{$listType}";
        $normalized = ltrim($channelIdentity, '+');

        $pageToken = '';
        $maxPages = self::CONSENT_MAX_PAGES;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $query = [];
                if ($pageToken !== '') {
                    $query['page_token'] = $pageToken;
                }

                $this->logger->debug(
                    'Searching consent identities for match',
                    [
                        'endpoint' => $endpoint,
                        'page' => $page,
                        'channel_identity' => $channelIdentity,
                    ]
                );

                $response = $this->httpClient->get(
                    $endpoint,
                    [
                        'headers' => $this->getHeaders(),
                        'query' => $query,
                    ]
                );

                $statusCode = $response->getStatusCode();

                if ($statusCode === 404) {
                    $body = (string) $response->getBody();
                    if (str_contains($body, 'ListType') && str_contains($body, 'does not exist')) {
                        return [];
                    }
                    throw new ApiException('Consent API returned 404: ' . $body, $statusCode);
                }

                $data = $this->handleResponse($response);

                $rawIdentities = $data['identities'] ?? [];
                if (is_array($rawIdentities)) {
                    foreach ($rawIdentities as $entry) {
                        if (is_array($entry) && ($entry['identity'] ?? '') === $normalized) {
                            return $entry;
                        }
                    }
                }

                $nextPageToken = $data['next_page_token'] ?? '';
                $pageToken = is_string($nextPageToken) ? $nextPageToken : '';
                if ($pageToken === '') {
                    break;
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Consent status request failed',
                [
                    'endpoint' => $endpoint,
                    'channel_identity' => $channelIdentity,
                    'exception' => $e,
                ]
            );
            throw new ApiException('Failed to get consent status', 0, $e);
        }

        if ($pageToken !== '') {
            $this->logger->warning(
                'Consent status pagination limit reached',
                ['endpoint' => $endpoint, 'max_pages' => $maxPages]
            );
            throw new ApiException(
                sprintf('Consent list requires more than %d pages; refusing partial results', $maxPages)
            );
        }

        return [];
    }

    /**
     * List all opted-out numbers for an app
     *
     * Queries the Sinch Consent Management API using the validated
     * `/consents/OPT_OUT_ALL` endpoint and collects identities across
     * all pages.
     *
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listOptOuts(string $appId): array
    {
        if ($appId === '') {
            throw new ApiException('App ID is required to list opt-outs');
        }

        return $this->fetchAllConsentIdentities($appId);
    }

    /**
     * Fetch all consent identities, following pagination
     *
     * Iterates through all pages of the consent list using
     * `next_page_token` until the full list is retrieved.
     *
     * @return list<array<mixed, mixed>>
     * @throws ApiException
     */
    private function fetchAllConsentIdentities(string $appId): array
    {
        $projectId = $this->config->getSinchProjectId();
        $listType = 'OPT_OUT_ALL';
        $endpoint = "/v1/projects/{$projectId}/apps/{$appId}/consents/{$listType}";

        $allIdentities = [];
        $pageToken = '';
        $maxPages = self::CONSENT_MAX_PAGES;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $query = [];
                if ($pageToken !== '') {
                    $query['page_token'] = $pageToken;
                }

                $this->logger->debug(
                    'Fetching consent identities',
                    [
                        'endpoint' => $endpoint,
                        'page' => $page,
                        'has_page_token' => $pageToken !== '',
                    ]
                );

                $response = $this->httpClient->get(
                    $endpoint,
                    [
                        'headers' => $this->getHeaders(),
                        'query' => $query,
                    ]
                );

                $statusCode = $response->getStatusCode();

                $this->logger->debug(
                    'Consent identities response',
                    [
                        'endpoint' => $endpoint,
                        'status_code' => $statusCode,
                        'page' => $page,
                    ]
                );

                // 404 can mean lazily-created empty list OR misconfiguration
                if ($statusCode === 404) {
                    $body = (string) $response->getBody();
                    if (str_contains($body, 'ListType') && str_contains($body, 'does not exist')) {
                        return [];
                    }
                    throw new ApiException('Consent API returned 404: ' . $body, $statusCode);
                }

                $data = $this->handleResponse($response);

                $rawIdentities = $data['identities'] ?? [];
                if (is_array($rawIdentities)) {
                    foreach ($rawIdentities as $entry) {
                        if (is_array($entry)) {
                            $allIdentities[] = $entry;
                        }
                    }
                }

                $nextPageToken = $data['next_page_token'] ?? '';
                $pageToken = is_string($nextPageToken) ? $nextPageToken : '';
                if ($pageToken === '') {
                    break;
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Consent identities request failed',
                ['endpoint' => $endpoint, 'exception' => $e]
            );
            throw new ApiException('Failed to fetch consent identities', 0, $e);
        }

        if ($pageToken !== '') {
            $this->logger->warning(
                'Consent identities pagination limit reached',
                ['endpoint' => $endpoint, 'max_pages' => $maxPages]
            );
            throw new ApiException(
                sprintf('Consent list requires more than %d pages; refusing partial results', $maxPages)
            );
        }

        return $allIdentities;
    }

    /**
     * Get authorization headers with OAuth2 token
     *
     * @return array<string, string>
     * @throws ApiException
     */
    private function getHeaders(): array
    {
        // Get OAuth2 token if not cached
        if ($this->cachedAccessToken === null) {
            $this->cachedAccessToken = $this->getOAuth2Token();
        }

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->cachedAccessToken,
        ];
    }

    /**
     * Handle API response
     *
     * Returns whatever the response body decodes to as an array (object → string-keyed,
     * top-level array → list-keyed). Empty body and non-array decoded values both
     * normalise to `[]`. Callers that need a specific shape are responsible for
     * narrowing it.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return array<array-key, mixed>
     * @throws ApiException
     */
    private function handleResponse($response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($statusCode >= 200 && $statusCode < 300) {
            if ($body === '') {
                return [];
            }
            try {
                $decoded = Json::decode($body);
            } catch (\JsonException $e) {
                throw new ApiException('Malformed Sinch response', $statusCode, $e);
            }
            return is_array($decoded) ? $decoded : [];
        }

        try {
            $message = ArrayPath::stringAt(Json::decode($body), 'error', 'message') ?? 'Unknown API error';
        } catch (\JsonException) {
            // Non-JSON error body; keep the generic message.
            $message = 'Unknown API error';
        }

        $this->logger->error('Sinch API error response', [
            'status_code' => $statusCode,
            'message' => $message,
        ]);
        $this->logger->debug('Sinch API error response body', [
            'status_code' => $statusCode,
            'body' => $body,
        ]);

        throw new ApiException("API request failed: {$message}", $statusCode);
    }
}
