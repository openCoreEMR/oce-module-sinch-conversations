<?php

/**
 * Manages the configuration options for the OpenCoreEMR Sinch Conversations Module.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenEMR\Common\Auth\AuthGlobal;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Crypto\CryptoGen;
use Symfony\Component\HttpFoundation\IpUtils;

class GlobalConfig
{
    private readonly bool $isExternalConfigMode;

    public function __construct(
        private readonly ConfigAccessorInterface $configAccessor = new GlobalsAccessor()
    ) {
        $this->isExternalConfigMode = ConfigFactory::isExternalConfigMode();
    }

    public const CONFIG_OPTION_ENABLED = 'oce_sinch_conversations_enabled';

    /**
     * Check if configuration is managed externally (file or env vars)
     */
    public function isExternalConfigMode(): bool
    {
        return $this->isExternalConfigMode;
    }
    public const CONFIG_OPTION_PROJECT_ID = 'oce_sinch_conversations_project_id';
    public const CONFIG_OPTION_APP_ID = 'oce_sinch_conversations_app_id';
    public const CONFIG_OPTION_API_KEY = 'oce_sinch_conversations_api_key';
    public const CONFIG_OPTION_API_SECRET = 'oce_sinch_conversations_api_secret';
    public const CONFIG_OPTION_REGION = 'oce_sinch_conversations_region';
    public const CONFIG_OPTION_DEFAULT_CHANNEL = 'oce_sinch_conversations_default_channel';
    public const CONFIG_OPTION_CLINIC_NAME = 'oce_sinch_conversations_clinic_name';
    public const CONFIG_OPTION_CLINIC_PHONE = 'oce_sinch_conversations_clinic_phone';
    public const CONFIG_OPTION_WEBHOOK_USERNAME = 'oce_sinch_conversations_webhook_username';
    public const CONFIG_OPTION_WEBHOOK_PASSWORD = 'oce_sinch_conversations_webhook_password';
    public const CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST = 'oce_sinch_conversations_webhook_ip_allowlist';

    public function isEnabled(): bool
    {
        return $this->configAccessor->getBoolean(self::CONFIG_OPTION_ENABLED, false);
    }

    public function getProjectId(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_PROJECT_ID, '');
    }

    public function getSinchProjectId(): string
    {
        return $this->getProjectId();
    }

    public function getAppId(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_APP_ID, '');
    }

    public function getSinchAppId(): string
    {
        return $this->getAppId();
    }

    public function getApiKey(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_API_KEY, '');
    }

    public function getSinchApiKey(): string
    {
        return $this->getApiKey();
    }

    public function getApiSecret(): string
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_API_SECRET, '');
        if ($value !== '' && $value !== '0') {
            // In external config mode, secrets are stored as plaintext (no encryption)
            if ($this->isExternalConfigMode) {
                return $value;
            }
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getSinchApiSecret(): string
    {
        return $this->getApiSecret();
    }

    public function getRegion(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_REGION, 'us');
    }

    public function getSinchRegion(): string
    {
        return $this->getRegion();
    }

    public function getDefaultChannel(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_DEFAULT_CHANNEL, 'SMS');
    }

    public function getClinicName(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_CLINIC_NAME, '');
    }

    public function getClinicPhone(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_CLINIC_PHONE, '');
    }

    /**
     * Get the base URL for the Sinch Conversations API
     */
    /**
     * Check if the patient portal is enabled
     */
    public function isPortalEnabled(): bool
    {
        return $this->configAccessor->getBoolean('portal_onsite_two_enable', false);
    }

    /**
     * Get the patient portal base URL
     */
    public function getPortalUrl(): string
    {
        return $this->configAccessor->getString('portal_onsite_two_address', '');
    }

    public function getApiBaseUrl(): string
    {
        $region = $this->getRegion();
        return match ($region) {
            'us' => 'https://us.conversation.api.sinch.com',
            'eu' => 'https://eu.conversation.api.sinch.com',
            default => 'https://us.conversation.api.sinch.com',
        };
    }

    public function getWebhookUsername(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_USERNAME, '');
    }

    /**
     * Get the raw stored webhook password value (hash in env mode, encrypted hash in DB mode)
     */
    private function getStoredWebhookPassword(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_PASSWORD, '');
    }

    /**
     * Verify the webhook password
     *
     * In env config mode, supports both bcrypt hash and plaintext password in env var.
     * In DB mode, uses AuthGlobal::globalVerify() which handles decryption and verification.
     */
    public function verifyWebhookPassword(string $password): bool
    {
        $stored = $this->getStoredWebhookPassword();
        if ($stored === '' || $stored === '0') {
            return false;
        }

        // External config mode: check if stored value is a bcrypt hash or plaintext
        if ($this->isExternalConfigMode) {
            if (AuthHash::hashValid($stored)) {
                return AuthHash::passwordVerify($password, $stored);
            }
            return hash_equals($stored, $password);
        }

        // DB mode: use AuthGlobal which decrypts and verifies the global setting
        $authGlobal = new AuthGlobal(self::CONFIG_OPTION_WEBHOOK_PASSWORD);
        return $authGlobal->globalVerify($password);
    }

    /**
     * Get the webhook IP allowlist as an array of IP addresses or CIDR ranges
     * Supports both newline-delimited (from UI textarea) and comma-delimited (from env vars)
     *
     * @return array<int, string>
     */
    public function getWebhookIpAllowlist(): array
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST, '');
        if ($value === '' || $value === '0') {
            return [];
        }
        $parts = preg_split('/[\n,]+/', $value);
        if ($parts === false) {
            return [];
        }
        $entries = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $entries[] = $trimmed;
            }
        }
        return $entries;
    }

    /**
     * Check if webhook authentication is configured
     */
    public function isWebhookAuthConfigured(): bool
    {
        return !in_array($this->getWebhookUsername(), ['', '0'], true)
            && !in_array($this->getStoredWebhookPassword(), ['', '0'], true);
    }

    /**
     * Verify webhook Basic Auth credentials
     *
     * Username uses timing-safe comparison.
     * Password verification depends on config mode:
     * - Env mode: timing-safe direct comparison or bcrypt verify
     * - DB mode: password_verify() against bcrypt hash
     */
    public function verifyWebhookAuth(string $username, string $password): bool
    {
        if (!$this->isWebhookAuthConfigured()) {
            return false;
        }
        return hash_equals($this->getWebhookUsername(), $username)
            && $this->verifyWebhookPassword($password);
    }

    /**
     * Check if an IP address is in the allowlist
     * Supports both raw IPs and CIDR notation (e.g., 192.168.1.0/24)
     * Returns true if allowlist is empty (no restriction) or IP matches
     */
    public function isIpInAllowlist(string $ip): bool
    {
        $allowlist = $this->getWebhookIpAllowlist();
        if ($allowlist === []) {
            return true; // No allowlist = allow all
        }

        return IpUtils::checkIp($ip, $allowlist);
    }
}
