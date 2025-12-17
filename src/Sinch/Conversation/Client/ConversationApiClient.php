<?php

/**
 * Sinch Conversations API Client
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Sinch\Conversation\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use OpenEMR\Common\Logging\SystemLogger;

class ConversationApiClient
{
    private const BASE_URL = 'https://us.conversation.api.sinch.com';
    private readonly Client $httpClient;
    private readonly SystemLogger $logger;
    private ?string $cachedAccessToken = null;

    public function __construct(private readonly GlobalConfig $config)
    {
        $this->httpClient = new Client([
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to send message: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to send message: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to get conversation messages: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to get message: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to list messages: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to create contact: " . $e->getMessage());
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
            $this->logger->error("Sinch API error: " . $e->getMessage());
            throw new ApiException("Failed to get contact: " . $e->getMessage());
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
            $this->logger->error("Failed to get app configuration: " . $e->getMessage());
            throw new ApiException("Failed to get app configuration: " . $e->getMessage());
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
            $errorData = json_decode($body, true);
            $errorMessage = $errorData['error']['message'] ?? 'Authentication failed';

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
            $this->logger->error("Sinch API connection test failed: " . $e->getMessage());
            throw new ApiException("Connection test failed: " . $e->getMessage());
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

        $authClient = new Client([
            'base_uri' => "https://{$region}.auth.sinch.com",
            'timeout' => 30,
            'http_errors' => false,
        ]);

        try {
            $this->logger->debug("Requesting OAuth2 token from Sinch");

            $response = $authClient->post('/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
                'auth' => [$keyId, $keySecret],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($statusCode !== 200) {
                $error = json_decode($body, true);
                $errorMessage = $error['error_description'] ?? $error['error'] ?? 'Failed to get OAuth2 token';
                throw new ApiException("OAuth2 authentication failed: {$errorMessage}", $statusCode);
            }

            $data = json_decode($body, true);
            $accessToken = $data['access_token'] ?? '';

            if (empty($accessToken)) {
                throw new ApiException("No access token in OAuth2 response");
            }

            $this->logger->debug("OAuth2 token obtained successfully");
            return $accessToken;
        } catch (GuzzleException $e) {
            $this->logger->error("OAuth2 request failed: " . $e->getMessage());
            throw new ApiException("OAuth2 request failed: " . $e->getMessage());
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

        $templateClient = new Client([
            'base_uri' => "https://{$region}.template.api.sinch.com",
            'timeout' => 30,
            'http_errors' => false,
        ]);

        try {
            $this->logger->debug(
                "Creating template in Sinch",
                ['template_key' => $templateData['template_key'] ?? 'unknown']
            );

            $response = $this->executeWithRetry(
                fn(): \Psr\Http\Message\ResponseInterface => $templateClient->post(
                    "/v2/projects/{$projectId}/templates",
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
            $this->logger->error("Failed to create template: " . $e->getMessage());
            throw new ApiException("Failed to create template: " . $e->getMessage());
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

        $templateClient = new Client([
            'base_uri' => "https://{$region}.template.api.sinch.com",
            'timeout' => 30,
            'http_errors' => false,
        ]);

        try {
            $response = $templateClient->get(
                "/v2/projects/{$projectId}/templates",
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
            $this->logger->error("Failed to list templates: " . $e->getMessage());
            throw new ApiException("Failed to list templates: " . $e->getMessage());
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

        return [
            'description' => $templateData['description'] ?? $templateData['template_name'],
            'default_translation' => 'en-US',
            'translations' => [
                [
                    'language_code' => 'en-US',
                    'version' => '1',
                    'variables' => $variables,
                    'message' => [
                        'text_message' => [
                            'text' => $this->convertTemplateVariables($templateData['body']),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Convert {{ variable }} syntax to {{variable}} (Sinch format)
     *
     * @param string $body
     * @return string
     */
    private function convertTemplateVariables(string $body): string
    {
        // Convert {{ variable_name }} to {{variable_name}}
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
                        $this->logger->info("Request succeeded after {$attempt} retries");
                    }
                    return $response;
                }

                // Rate limit or server error - retry
                if ($statusCode === 429 || $statusCode >= 500) {
                    if ($attempt < $maxRetries) {
                        $this->logger->warning(
                            "Request failed with {$statusCode}, retrying in {$delayMs}ms (attempt " .
                            ($attempt + 1) . "/{$maxRetries})"
                        );
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
                    $this->logger->warning(
                        "Request threw exception: {$e->getMessage()}, retrying in {$delayMs}ms " .
                        "(attempt " . ($attempt + 1) . "/{$maxRetries})"
                    );
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
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function handleResponse($response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($statusCode >= 200 && $statusCode < 300) {
            return json_decode($body, true) ?? [];
        }

        $error = json_decode($body, true);
        $message = $error['error']['message'] ?? 'Unknown API error';

        // Log full error details for debugging
        $this->logger->error(
            sprintf(
                "Sinch API error %d: %s\nFull response: %s",
                $statusCode,
                $message,
                $body
            )
        );

        throw new ApiException("API request failed: {$message}", $statusCode);
    }
}
