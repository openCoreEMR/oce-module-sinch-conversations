<?php

/**
 * Configuration Service - Handles saving and validating module settings
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Service;

use OpenCoreEMR\ModuleConfig\ConfigService as LibConfigService;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Logging\ExceptionContext;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Logging\SystemLogger;

class ConfigService
{
    private readonly LibConfigService $libConfigService;
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config
    ) {
        $this->libConfigService = new LibConfigService();
        $this->logger = new SystemLogger();
    }

    /**
     * Save configuration settings to OpenEMR globals
     *
     * @param array<string, mixed> $settings
     * @return void
     * @throws ValidationException
     */
    public function saveSettings(array $settings): void
    {
        $this->validateSettings($settings);

        try {
            // Save each setting to globals table
            if (isset($settings['project_id'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_PROJECT_ID,
                    (string)$settings['project_id']
                );
            }

            if (isset($settings['app_id'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_APP_ID,
                    (string)$settings['app_id']
                );
            }

            if (isset($settings['api_key'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_API_KEY,
                    (string)$settings['api_key']
                );
            }

            if (isset($settings['api_secret']) && !empty($settings['api_secret'])) {
                $this->saveEncryptedSetting(
                    GlobalConfig::CONFIG_OPTION_API_SECRET,
                    (string)$settings['api_secret']
                );
            }

            if (isset($settings['region'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_REGION,
                    (string)$settings['region']
                );
            }

            if (isset($settings['default_channel'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL,
                    (string)$settings['default_channel']
                );
            }

            if (isset($settings['clinic_name'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_CLINIC_NAME,
                    (string)$settings['clinic_name']
                );
            }

            if (isset($settings['clinic_phone'])) {
                $this->saveSetting(
                    GlobalConfig::CONFIG_OPTION_CLINIC_PHONE,
                    (string)$settings['clinic_phone']
                );
            }

            $this->logger->info('Sinch Conversations settings saved successfully');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save settings', ['exception' => ExceptionContext::fromThrowable($e)]);
            throw new ValidationException("Failed to save settings", 0, $e);
        }
    }

    /**
     * Validate settings before saving
     *
     * @param array<string, mixed> $settings
     * @return void
     * @throws ValidationException
     */
    private function validateSettings(array $settings): void
    {
        // Validate region
        if (isset($settings['region'])) {
            $region = (string)$settings['region'];
            if (!in_array($region, ['us', 'eu'], true)) {
                throw new ValidationException("Region must be 'us' or 'eu'");
            }
        }

        // Validate channel
        if (isset($settings['default_channel'])) {
            $channel = (string)$settings['default_channel'];
            if (!in_array($channel, ['SMS', 'WHATSAPP', 'RCS'], true)) {
                throw new ValidationException("Default channel must be 'SMS', 'WHATSAPP', or 'RCS'");
            }
        }

        // Validate required fields if API is being configured
        if (
            isset($settings['project_id']) ||
            isset($settings['app_id']) ||
            isset($settings['api_key'])
        ) {
            if (empty($settings['project_id'])) {
                throw new ValidationException("Project ID is required");
            }
            if (empty($settings['app_id'])) {
                throw new ValidationException("App ID is required");
            }
            if (empty($settings['api_key'])) {
                throw new ValidationException("API Key is required");
            }
        }
    }

    private function saveSetting(string $key, string $value): void
    {
        $this->libConfigService->saveSetting($key, $value);
    }

    private function saveEncryptedSetting(string $key, string $value): void
    {
        $this->libConfigService->saveEncryptedSetting($key, $value);
    }
}
