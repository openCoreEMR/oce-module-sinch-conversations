<?php

/**
 * Sinch App Configuration Client
 *
 * Handles Sinch Conversations app configuration and channel management.
 * Separated from operational message/conversation client for clean architecture.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
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
use OpenCoreEMR\Sinch\Conversation\Config\ConfigInterface;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;

class AppConfigurationClient
{
    private const CONSENT_MAX_PAGES = 100;
    private readonly Client $httpClient;
    private ?string $cachedAccessToken = null;

    public function __construct(private readonly ConfigInterface $config)
    {
        $this->httpClient = new Client([
            'base_uri' => $config->getSinchApiBaseUrl(),
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * Get OAuth2 access token
     *
     * @return string Access token
     * @throws ApiException
     */
    public function getOAuth2Token(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

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
            $response = $authClient->post('/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
                'auth' => [$keyId, $keySecret],
            ]);

            $statusCode = $response->getStatusCode();
            try {
                $body = Json::decode((string)$response->getBody());
            } catch (\JsonException $e) {
                throw new ApiException('Malformed OAuth2 response', $statusCode, $e);
            }

            if ($statusCode !== 200) {
                $error = ArrayPath::firstNonEmptyString($body, 'error_description', 'error') ?? 'Unknown error';
                throw new ApiException("OAuth2 authentication failed: {$error}", $statusCode);
            }

            $accessToken = ArrayPath::stringAt($body, 'access_token');
            if ($accessToken === null || $accessToken === '') {
                throw new ApiException("OAuth2 response missing access_token", $statusCode);
            }

            $this->cachedAccessToken = $accessToken;
            return $accessToken;
        } catch (GuzzleException $e) {
            throw new ApiException('OAuth2 request failed', 0, $e);
        }
    }

    /**
     * Get app configuration
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
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/apps/{$appId}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to get app configuration', 0, $e);
        }
    }

    /**
     * Update app configuration
     *
     * @param array<string, mixed> $updates Configuration updates
     * @param string|null $appId App ID (uses configured app ID if not provided)
     * @return array<string, mixed> Updated app configuration
     * @throws ApiException
     */
    public function updateApp(array $updates, ?string $appId = null): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->patch(
                "/v1/projects/{$projectId}/apps/{$appId}",
                [
                    'headers' => array_merge(
                        $this->getHeaders(),
                        ['Content-Type' => 'application/json']
                    ),
                    'json' => $updates,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to update app configuration', 0, $e);
        }
    }

    /**
     * List all apps in the project
     *
     * @return array<int, array<string, mixed>> List of apps
     * @throws ApiException
     */
    public function listApps(): array
    {
        $projectId = $this->config->getSinchProjectId();

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/apps",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            $result = $this->handleResponse($response);
            return $result['apps'] ?? [];
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to list apps', 0, $e);
        }
    }

    /**
     * List webhooks for an app
     *
     * @param string|null $appId
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listWebhooks(?string $appId = null): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->get(
                "/v1/projects/{$projectId}/apps/{$appId}/webhooks",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            $data = $this->handleResponse($response);
            return $data['webhooks'] ?? [];
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to list webhooks', 0, $e);
        }
    }

    /**
     * Create a webhook
     *
     * @param array<string, mixed> $webhookData
     * @param string|null $appId
     * @return array<string, mixed> Created webhook
     * @throws ApiException
     */
    public function createWebhook(array $webhookData, ?string $appId = null): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->post(
                "/v1/projects/{$projectId}/apps/{$appId}/webhooks",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $webhookData,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to create webhook', 0, $e);
        }
    }

    /**
     * Update a webhook
     *
     * @param string $webhookId
     * @param array<string, mixed> $webhookData
     * @param string|null $appId
     * @return array<string, mixed> Updated webhook
     * @throws ApiException
     */
    public function updateWebhook(string $webhookId, array $webhookData, ?string $appId = null): array
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->patch(
                "/v1/projects/{$projectId}/apps/{$appId}/webhooks/{$webhookId}",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $webhookData,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to update webhook', 0, $e);
        }
    }

    /**
     * Delete a webhook
     *
     * @param string $webhookId
     * @param string|null $appId
     * @return bool
     * @throws ApiException
     */
    public function deleteWebhook(string $webhookId, ?string $appId = null): bool
    {
        $projectId = $this->config->getSinchProjectId();
        $appId = $appId ?? $this->config->getSinchAppId();

        if (empty($appId)) {
            throw new ApiException("App ID is required");
        }

        try {
            $response = $this->httpClient->delete(
                "/v1/projects/{$projectId}/apps/{$appId}/webhooks/{$webhookId}",
                [
                    'headers' => $this->getHeaders(),
                ]
            );

            $statusCode = $response->getStatusCode();
            return $statusCode >= 200 && $statusCode < 300;
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to delete webhook', 0, $e);
        }
    }

    /**
     * Get the consent status for a phone number
     *
     * Queries the Sinch Consent Management API using the validated
     * `/consents/{list_type}` endpoint structure. Queries the OPT_OUT_ALL
     * list page-by-page, returning early when the identity is found.
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
            throw new ApiException('Failed to get consent status', 0, $e);
        }

        if ($pageToken !== '') {
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

                $response = $this->httpClient->get(
                    $endpoint,
                    [
                        'headers' => $this->getHeaders(),
                        'query' => $query,
                    ]
                );

                $statusCode = $response->getStatusCode();

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
            throw new ApiException('Failed to fetch consent identities', 0, $e);
        }

        if ($pageToken !== '') {
            throw new ApiException(
                sprintf('Consent list requires more than %d pages; refusing partial results', $maxPages)
            );
        }

        return $allIdentities;
    }

    /**
     * Send a message using channel identity
     *
     * Used by consent check to test send behavior for opted-out numbers.
     *
     * Note: this duplicates ConversationApiClient::sendMessageByChannelIdentity().
     * A shared message-sending helper should be extracted to eliminate drift risk.
     *
     * @param string $channelIdentity Phone number
     * @param string $message Message text
     * @param string $channel SMS, WHATSAPP, etc
     * @return array<string, mixed> Response data
     * @throws ApiException
     */
    public function sendMessageByChannelIdentity(
        string $channelIdentity,
        string $message,
        string $channel = 'SMS'
    ): array {
        $projectId = $this->config->getSinchProjectId();
        $appId = $this->config->getSinchAppId();

        if ($appId === '') {
            throw new ApiException('Sinch app ID is not configured');
        }

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

        try {
            $response = $this->httpClient->post(
                "/v1/projects/{$projectId}/messages:send",
                [
                    'headers' => $this->getHeaders(),
                    'json' => $payload,
                ]
            );

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('Failed to send message', 0, $e);
        }
    }

    /**
     * Get request headers with authentication
     *
     * @return array<string, string>
     * @throws ApiException
     */
    private function getHeaders(): array
    {
        $token = $this->getOAuth2Token();

        return [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
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

        throw new ApiException("API request failed: {$message}", $statusCode);
    }
}
