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
     * Get authorization headers
     *
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->getSinchApiKey(),
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

        $this->logger->error("Sinch API error {$statusCode}: {$message}");
        throw new ApiException("API request failed: {$message}", $statusCode);
    }
}
