<?php

/**
 * Manages the configuration options for the OpenCoreEMR Sinch Conversations Module.
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenEMR\Common\Crypto\CryptoGen;

class GlobalConfig
{
    public function __construct(
        private readonly GlobalsAccessor $globals = new GlobalsAccessor()
    ) {
    }

    public const CONFIG_OPTION_ENABLED = 'oce_sinch_conversations_enabled';
    public const CONFIG_OPTION_PROJECT_ID = 'oce_sinch_conversations_project_id';
    public const CONFIG_OPTION_APP_ID = 'oce_sinch_conversations_app_id';
    public const CONFIG_OPTION_API_KEY = 'oce_sinch_conversations_api_key';
    public const CONFIG_OPTION_API_SECRET = 'oce_sinch_conversations_api_secret';
    public const CONFIG_OPTION_REGION = 'oce_sinch_conversations_region';
    public const CONFIG_OPTION_DEFAULT_CHANNEL = 'oce_sinch_conversations_default_channel';
    public const CONFIG_OPTION_CLINIC_NAME = 'oce_sinch_conversations_clinic_name';
    public const CONFIG_OPTION_CLINIC_PHONE = 'oce_sinch_conversations_clinic_phone';

    public function isEnabled(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_ENABLED, false);
    }

    public function getProjectId(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_PROJECT_ID, '');
    }

    public function getSinchProjectId(): string
    {
        return $this->getProjectId();
    }

    public function getAppId(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_APP_ID, '');
    }

    public function getSinchAppId(): string
    {
        return $this->getAppId();
    }

    public function getApiKey(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_API_KEY, '');
    }

    public function getSinchApiKey(): string
    {
        return $this->getApiKey();
    }

    public function getApiSecret(): string
    {
        $value = $this->globals->getString(self::CONFIG_OPTION_API_SECRET, '');
        if (!empty($value)) {
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
        return $this->globals->getString(self::CONFIG_OPTION_REGION, 'us');
    }

    public function getSinchRegion(): string
    {
        return $this->getRegion();
    }

    public function getDefaultChannel(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_DEFAULT_CHANNEL, 'SMS');
    }

    public function getClinicName(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_CLINIC_NAME, '');
    }

    public function getClinicPhone(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_CLINIC_PHONE, '');
    }

    /**
     * Get the base URL for the Sinch Conversations API
     */
    public function getApiBaseUrl(): string
    {
        $region = $this->getRegion();
        return match ($region) {
            'us' => 'https://us.conversation.api.sinch.com',
            'eu' => 'https://eu.conversation.api.sinch.com',
            default => 'https://us.conversation.api.sinch.com',
        };
    }
}
