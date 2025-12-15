<?php

/**
 * Standalone Configuration (CLI-friendly, no OpenEMR globals dependency)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Sinch\Conversation\Config;

class StandaloneConfig implements ConfigInterface
{
    /**
     * @param array<string, string> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function getSinchProjectId(): string
    {
        return $this->config['project_id'] ?? '';
    }

    public function getSinchAppId(): string
    {
        return $this->config['app_id'] ?? '';
    }

    public function getSinchApiKey(): string
    {
        return $this->config['api_key'] ?? '';
    }

    public function getSinchApiSecret(): string
    {
        return $this->config['api_secret'] ?? '';
    }

    public function getSinchRegion(): string
    {
        return $this->config['region'] ?? 'us';
    }
}
