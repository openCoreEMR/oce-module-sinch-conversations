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

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Command;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Sinch\Conversation\Config\Region;

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

    public function getSinchRegion(): Region
    {
        return Region::tryFrom($this->config['region'] ?? '') ?? Region::Us;
    }

    public function getSinchApiBaseUrl(): string
    {
        return $this->getSinchRegion()->conversationApiBaseUrl();
    }
}
