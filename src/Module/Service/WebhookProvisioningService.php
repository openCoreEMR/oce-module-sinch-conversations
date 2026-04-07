<?php

/**
 * Webhook Provisioning Service
 *
 * Manages the lifecycle of this module's Sinch webhook via the Sinch
 * Conversation API. Identifies "our" webhook among the app's webhooks by
 * matching the host of its target URL against the OpenEMR site address.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient;
use OpenCoreEMR\Sinch\Conversation\Exception\ConfigurationException;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;

class WebhookProvisioningService
{
    /** @var list<string> */
    public const REQUIRED_TRIGGERS = ['MESSAGE_INBOUND', 'MESSAGE_DELIVERY', 'OPT_IN', 'OPT_OUT'];

    public const MAX_WEBHOOKS = 5;

    public const STATE_NOT_CONFIGURED = 'not_configured';
    public const STATE_NOT_PROVISIONED = 'not_provisioned';
    public const STATE_ACTIVE = 'active';
    public const STATE_NEEDS_UPDATE = 'needs_update';

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly AppConfigurationClient $appConfigClient,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * Inspect the current state of this module's webhook registration on Sinch.
     *
     * @return array{
     *     state: string,
     *     webhook: array<string, mixed>|null,
     *     triggers_match: bool,
     *     total_webhooks: int,
     *     target_url: string,
     *     required_triggers: list<string>,
     *     max_webhooks: int,
     * }
     */
    public function getStatus(): array
    {
        $targetUrl = $this->config->getWebhookTargetUrl();
        if ($targetUrl === '') {
            return [
                'state' => self::STATE_NOT_CONFIGURED,
                'webhook' => null,
                'triggers_match' => false,
                'total_webhooks' => 0,
                'target_url' => '',
                'required_triggers' => self::REQUIRED_TRIGGERS,
                'max_webhooks' => self::MAX_WEBHOOKS,
            ];
        }

        $webhooks = $this->appConfigClient->listWebhooks();
        $ours = null;
        foreach ($webhooks as $webhook) {
            $target = (string)($webhook['target'] ?? '');
            if ($this->isOurWebhook($target)) {
                $ours = $webhook;
                break;
            }
        }

        if ($ours === null) {
            return [
                'state' => self::STATE_NOT_PROVISIONED,
                'webhook' => null,
                'triggers_match' => false,
                'total_webhooks' => count($webhooks),
                'target_url' => $targetUrl,
                'required_triggers' => self::REQUIRED_TRIGGERS,
                'max_webhooks' => self::MAX_WEBHOOKS,
            ];
        }

        $rawTriggers = (array)($ours['triggers'] ?? []);
        $triggers = [];
        foreach ($rawTriggers as $trigger) {
            $triggers[] = is_string($trigger) ? $trigger : '';
        }
        $triggersMatch = $this->triggersMatch($triggers);

        return [
            'state' => $triggersMatch ? self::STATE_ACTIVE : self::STATE_NEEDS_UPDATE,
            'webhook' => $ours,
            'triggers_match' => $triggersMatch,
            'total_webhooks' => count($webhooks),
            'target_url' => $targetUrl,
            'required_triggers' => self::REQUIRED_TRIGGERS,
            'max_webhooks' => self::MAX_WEBHOOKS,
        ];
    }

    /**
     * Create a new webhook on Sinch for this module.
     *
     * Generates a fresh HMAC secret, registers the webhook with all required
     * triggers, and persists the secret locally.
     *
     * @return array<string, mixed> The created webhook object from Sinch
     * @throws ConfigurationException When the site address is not configured
     * @throws ValidationException When a webhook already exists or the app is at capacity
     */
    public function provision(): array
    {
        $status = $this->getStatus();

        if ($status['state'] === self::STATE_NOT_CONFIGURED) {
            throw new ConfigurationException(
                'OpenEMR site address (qualified_site_addr) is not configured; cannot build webhook URL.'
            );
        }

        if ($status['webhook'] !== null) {
            throw new ValidationException(
                'A webhook for this tenant already exists. Use update to rotate its secret.'
            );
        }

        if ($status['total_webhooks'] >= self::MAX_WEBHOOKS) {
            throw new ValidationException(sprintf(
                'Sinch app is at the %d-webhook limit; free a slot before provisioning.',
                self::MAX_WEBHOOKS
            ));
        }

        $secret = $this->generateSecret();
        $webhook = $this->appConfigClient->createWebhook([
            'target' => $status['target_url'],
            'triggers' => self::REQUIRED_TRIGGERS,
            'secret' => $secret,
        ]);

        $this->configService->saveWebhookSecret($secret);

        return $webhook;
    }

    /**
     * Update the existing webhook with the current required triggers and a fresh secret.
     *
     * @return array<string, mixed> The updated webhook object from Sinch
     * @throws ValidationException When no webhook exists to update
     */
    public function update(): array
    {
        $status = $this->getStatus();

        if ($status['webhook'] === null) {
            throw new ValidationException('No webhook exists for this tenant; provision one first.');
        }

        $webhookId = (string)($status['webhook']['id'] ?? '');
        if ($webhookId === '') {
            throw new ValidationException('Existing webhook has no id; cannot update.');
        }

        $secret = $this->generateSecret();
        $webhook = $this->appConfigClient->updateWebhook($webhookId, [
            'target' => $status['target_url'],
            'triggers' => self::REQUIRED_TRIGGERS,
            'secret' => $secret,
        ]);

        $this->configService->saveWebhookSecret($secret);

        return $webhook;
    }

    /**
     * Delete the existing webhook from Sinch and clear the local secret.
     *
     * @return bool True if a webhook was deleted, false if nothing was registered
     */
    public function remove(): bool
    {
        $status = $this->getStatus();

        if ($status['webhook'] === null) {
            $this->configService->clearWebhookSecret();
            return false;
        }

        $webhookId = (string)($status['webhook']['id'] ?? '');
        if ($webhookId === '') {
            throw new ValidationException('Existing webhook has no id; cannot remove.');
        }

        $deleted = $this->appConfigClient->deleteWebhook($webhookId);
        $this->configService->clearWebhookSecret();

        return $deleted;
    }

    /**
     * Determine whether a webhook target URL belongs to this OpenEMR tenant.
     *
     * Compares the host component of the target URL with the host of this
     * tenant's qualified site address (case-insensitive).
     */
    private function isOurWebhook(string $target): bool
    {
        $siteAddr = $this->config->getQualifiedSiteAddr();
        if ($siteAddr === '' || $target === '') {
            return false;
        }

        $ourHost = parse_url($siteAddr, PHP_URL_HOST);
        $targetHost = parse_url($target, PHP_URL_HOST);

        if (!is_string($ourHost) || !is_string($targetHost)) {
            return false;
        }

        return strcasecmp($ourHost, $targetHost) === 0;
    }

    /**
     * @param list<string> $triggers
     */
    private function triggersMatch(array $triggers): bool
    {
        $actual = array_map('strtoupper', $triggers);
        sort($actual);
        $expected = self::REQUIRED_TRIGGERS;
        sort($expected);
        return $actual === $expected;
    }

    /**
     * Generate a cryptographically random secret suitable for HMAC-SHA256 validation.
     */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
