<?php

/**
 * Factory for creating configuration accessors
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * Factory for creating the appropriate configuration accessor.
 *
 * When OCE_SINCH_CONVERSATIONS_ENV_CONFIG=1 is set, configuration is read from
 * environment variables instead of the database-backed OpenEMR globals.
 */
class ConfigFactory
{
    public const ENV_CONFIG_VAR = 'OCE_SINCH_CONVERSATIONS_ENV_CONFIG';

    /**
     * Check if environment-only config mode is enabled
     */
    public static function isEnvConfigMode(): bool
    {
        $value = getenv(self::ENV_CONFIG_VAR);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Create the appropriate config accessor based on environment
     */
    public static function createConfigAccessor(): ConfigAccessorInterface
    {
        if (self::isEnvConfigMode()) {
            return new EnvironmentConfigAccessor();
        }
        return new GlobalsAccessor();
    }
}
