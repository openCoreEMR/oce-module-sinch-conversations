<?php

/**
 * Standalone Configuration for CLI Commands
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Command;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;

/**
 * Configuration adapter for CLI usage without OpenEMR globals
 */
class StandaloneConfig extends GlobalConfig
{
    /**
     * @param array<string, string> $config
     */
    public function __construct(private readonly array $config)
    {
        // Don't call parent constructor - we're not using GlobalsAccessor
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

    public function getApiBaseUrl(): string
    {
        $region = $this->getSinchRegion();
        return match ($region) {
            'us' => 'https://us.conversation.api.sinch.com',
            'eu' => 'https://eu.conversation.api.sinch.com',
            default => 'https://us.conversation.api.sinch.com',
        };
    }
}
