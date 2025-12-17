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

namespace OpenCoreEMR\Sinch\Conversation\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenCoreEMR\Sinch\Conversation\Config\ConfigInterface;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;

class AppConfigurationClient
{
    private const BASE_URL = 'https://us.conversation.api.sinch.com';
    private readonly Client $httpClient;
    private ?string $cachedAccessToken = null;

    public function __construct(private readonly ConfigInterface $config)
    {
        $this->httpClient = new Client([
            'base_uri' => self::BASE_URL,
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
            $body = json_decode((string)$response->getBody(), true);

            if ($statusCode !== 200) {
                $error = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
                throw new ApiException("OAuth2 authentication failed: {$error}", $statusCode);
            }

            $this->cachedAccessToken = $body['access_token'];
            return $this->cachedAccessToken;
        } catch (GuzzleException $e) {
            throw new ApiException("OAuth2 request failed: " . $e->getMessage());
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
            throw new ApiException("Failed to get app configuration: " . $e->getMessage());
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
            throw new ApiException("Failed to update app configuration: " . $e->getMessage());
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
            throw new ApiException("Failed to list apps: " . $e->getMessage());
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
            throw new ApiException("Failed to list webhooks: " . $e->getMessage());
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
            throw new ApiException("Failed to create webhook: " . $e->getMessage());
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
            throw new ApiException("Failed to update webhook: " . $e->getMessage());
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
            throw new ApiException("Failed to delete webhook: " . $e->getMessage());
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

        throw new ApiException("API request failed: {$message}", $statusCode);
    }
}
