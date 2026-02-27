<?php

/**
 * File-based configuration accessor (YAML config files)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Read module configuration from YAML files, with env var overrides.
 *
 * Precedence: environment variables > YAML files > defaults.
 * OpenEMR system values (OE_SITE_DIR, webroot, etc.) delegate to GlobalsAccessor.
 *
 * @internal Use ConfigFactory::createConfigAccessor() instead of instantiating directly
 */
class FileConfigAccessor implements ConfigAccessorInterface
{
    /**
     * Map short YAML keys to internal config keys (oce_sinch_conversations_*)
     *
     * @var array<string, string>
     */
    private const KEY_MAP = [
        'enabled' => GlobalConfig::CONFIG_OPTION_ENABLED,
        'project_id' => GlobalConfig::CONFIG_OPTION_PROJECT_ID,
        'app_id' => GlobalConfig::CONFIG_OPTION_APP_ID,
        'api_key' => GlobalConfig::CONFIG_OPTION_API_KEY,
        'api_secret' => GlobalConfig::CONFIG_OPTION_API_SECRET,
        'region' => GlobalConfig::CONFIG_OPTION_REGION,
        'default_channel' => GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL,
        'clinic_name' => GlobalConfig::CONFIG_OPTION_CLINIC_NAME,
        'clinic_phone' => GlobalConfig::CONFIG_OPTION_CLINIC_PHONE,
    ];

    /**
     * Map internal config keys to environment variable names for override support.
     * Same mapping as EnvironmentConfigAccessor::KEY_MAP.
     *
     * @var array<string, string>
     */
    private const ENV_OVERRIDE_MAP = [
        GlobalConfig::CONFIG_OPTION_ENABLED => 'OCE_SINCH_CONVERSATIONS_ENABLED',
        GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'OCE_SINCH_CONVERSATIONS_PROJECT_ID',
        GlobalConfig::CONFIG_OPTION_APP_ID => 'OCE_SINCH_CONVERSATIONS_APP_ID',
        GlobalConfig::CONFIG_OPTION_API_KEY => 'OCE_SINCH_CONVERSATIONS_API_KEY',
        GlobalConfig::CONFIG_OPTION_API_SECRET => 'OCE_SINCH_CONVERSATIONS_API_SECRET',
        GlobalConfig::CONFIG_OPTION_REGION => 'OCE_SINCH_CONVERSATIONS_REGION',
        GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'OCE_SINCH_CONVERSATIONS_DEFAULT_CHANNEL',
        GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'OCE_SINCH_CONVERSATIONS_CLINIC_NAME',
        GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => 'OCE_SINCH_CONVERSATIONS_CLINIC_PHONE',
    ];

    /**
     * Reverse map: internal config key => short YAML key
     *
     * @var array<string, string>
     */
    private const REVERSE_KEY_MAP = [
        GlobalConfig::CONFIG_OPTION_ENABLED => 'enabled',
        GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'project_id',
        GlobalConfig::CONFIG_OPTION_APP_ID => 'app_id',
        GlobalConfig::CONFIG_OPTION_API_KEY => 'api_key',
        GlobalConfig::CONFIG_OPTION_API_SECRET => 'api_secret',
        GlobalConfig::CONFIG_OPTION_REGION => 'region',
        GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'default_channel',
        GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'clinic_name',
        GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => 'clinic_phone',
    ];

    /** @var ParameterBag<string, mixed> */
    private readonly ParameterBag $bag;
    private readonly GlobalsAccessor $globalsAccessor;

    /**
     * @param array<string, mixed> $yamlData merged data from YamlConfigLoader::load()
     */
    public function __construct(array $yamlData)
    {
        $this->globalsAccessor = new GlobalsAccessor();
        $this->bag = $this->buildBag($yamlData);
    }

    /**
     * Build a ParameterBag from YAML data with env var overrides
     *
     * Start with YAML values (mapped to internal keys), then override with
     * any set environment variables.
     *
     * @param array<string, mixed> $yamlData
     * @return ParameterBag<string, mixed>
     */
    private function buildBag(array $yamlData): ParameterBag
    {
        $params = [];

        // Map short YAML keys to internal config keys
        foreach (self::KEY_MAP as $yamlKey => $configKey) {
            if (array_key_exists($yamlKey, $yamlData)) {
                $params[$configKey] = $yamlData[$yamlKey];
            }
        }

        // Override with environment variables where set
        foreach (self::ENV_OVERRIDE_MAP as $configKey => $envVar) {
            $value = getenv($envVar);
            if ($value !== false) {
                $params[$configKey] = $value;
            }
        }

        return new ParameterBag($params);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->get($key, $default);
        }

        return $this->globalsAccessor->get($key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getString($key, $default);
        }

        return $this->globalsAccessor->getString($key, $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getBoolean($key, $default);
        }

        return $this->globalsAccessor->getBoolean($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getInt($key, $default);
        }

        return $this->globalsAccessor->getInt($key, $default);
    }

    public function has(string $key): bool
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->has($key);
        }

        return $this->globalsAccessor->has($key);
    }
}
