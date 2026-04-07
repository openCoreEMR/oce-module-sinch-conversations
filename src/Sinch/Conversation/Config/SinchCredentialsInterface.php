<?php

/**
 * Sinch API credentials interface.
 *
 * Defines the credential getters required by Sinch API clients. Distinct from
 * the generic OpenEMR module config layer (oce-lib-module-config).
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Config;

interface SinchCredentialsInterface
{
    public function getSinchProjectId(): string;

    public function getSinchAppId(): string;

    public function getSinchApiKey(): string;

    public function getSinchApiSecret(): string;

    public function getSinchRegion(): Region;

    /**
     * Resolve the Conversations API base URL for the configured region.
     *
     * Implementations MUST return `$this->getSinchRegion()->conversationApiBaseUrl()`;
     * the indirection exists so callers that already have a config don't
     * need a second `Region` injection.
     */
    public function getSinchApiBaseUrl(): string;
}
