<?php

/**
 * Module-specific configuration descriptors for oce-lib-module-config
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc. <https://www.opencoreemr.com>
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenCoreEMR\ModuleConfig\GlobalsSectionDescriptor;
use OpenCoreEMR\ModuleConfig\ModuleConfigDescriptor;

/**
 * Build the descriptors that parameterize oce-lib-module-config for this module.
 */
final class SinchModuleConfig
{
    public const MODULE_DIR_NAME = 'oce-module-sinch-conversations';
    public const SECTION_NAME = 'OpenCoreEMR Sinch Conversations';

    public static function createConfigDescriptor(): ModuleConfigDescriptor
    {
        return new ModuleConfigDescriptor(
            yamlKeyMap: [
                'enabled' => GlobalConfig::CONFIG_OPTION_ENABLED,
                'project_id' => GlobalConfig::CONFIG_OPTION_PROJECT_ID,
                'app_id' => GlobalConfig::CONFIG_OPTION_APP_ID,
                'api_key' => GlobalConfig::CONFIG_OPTION_API_KEY,
                'api_secret' => GlobalConfig::CONFIG_OPTION_API_SECRET,
                'region' => GlobalConfig::CONFIG_OPTION_REGION,
                'default_channel' => GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL,
                'clinic_name' => GlobalConfig::CONFIG_OPTION_CLINIC_NAME,
                'clinic_phone' => GlobalConfig::CONFIG_OPTION_CLINIC_PHONE,
                'webhook_secret' => GlobalConfig::CONFIG_OPTION_WEBHOOK_SECRET,
                'webhook_ip_allowlist' => GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST,
            ],
            envOverrideMap: [
                GlobalConfig::CONFIG_OPTION_ENABLED => 'OCE_SINCH_CONVERSATIONS_ENABLED',
                GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'OCE_SINCH_CONVERSATIONS_PROJECT_ID',
                GlobalConfig::CONFIG_OPTION_APP_ID => 'OCE_SINCH_CONVERSATIONS_APP_ID',
                GlobalConfig::CONFIG_OPTION_API_KEY => 'OCE_SINCH_CONVERSATIONS_API_KEY',
                GlobalConfig::CONFIG_OPTION_API_SECRET => 'OCE_SINCH_CONVERSATIONS_API_SECRET',
                GlobalConfig::CONFIG_OPTION_REGION => 'OCE_SINCH_CONVERSATIONS_REGION',
                GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'OCE_SINCH_CONVERSATIONS_DEFAULT_CHANNEL',
                GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'OCE_SINCH_CONVERSATIONS_CLINIC_NAME',
                GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => 'OCE_SINCH_CONVERSATIONS_CLINIC_PHONE',
                GlobalConfig::CONFIG_OPTION_WEBHOOK_SECRET => 'OCE_SINCH_CONVERSATIONS_WEBHOOK_SECRET',
                GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => 'OCE_SINCH_CONVERSATIONS_WEBHOOK_IP_ALLOWLIST',
            ],
            envConfigVar: 'OCE_SINCH_CONVERSATIONS_ENV_CONFIG',
            conventionalConfigPath: '/etc/oce/sinch-conversations/config.yaml',
            conventionalSecretsPath: '/etc/oce/sinch-conversations/secrets.yaml',
            configFileEnvVar: 'OCE_SINCH_CONVERSATIONS_CONFIG_FILE',
            secretsFileEnvVar: 'OCE_SINCH_CONVERSATIONS_SECRETS_FILE',
        );
    }

    public static function createGlobalsSectionDescriptor(): GlobalsSectionDescriptor
    {
        return new GlobalsSectionDescriptor(
            sectionName: self::SECTION_NAME,
            moduleDirName: self::MODULE_DIR_NAME,
            enableKey: GlobalConfig::CONFIG_OPTION_ENABLED,
            settingsDescription: 'API credentials, messaging configuration, and webhook settings'
                . ' are managed on the module settings page.',
        );
    }
}
