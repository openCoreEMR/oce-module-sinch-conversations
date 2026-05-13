<?php

/**
 * Sinch Conversations API region.
 *
 * Backed enum because the value is persisted in OpenEMR globals and sent
 * to Sinch as a URL component. Adding a new region (e.g. `Au`) means
 * adding a case here and getting PHPStan failures from every match
 * statement that doesn't yet handle it — which is the point.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Config;

enum Region: string
{
    case Us = 'us';
    case Eu = 'eu';

    /**
     * Conversations API base URL for this region (no trailing slash).
     */
    public function conversationApiBaseUrl(): string
    {
        return match ($this) {
            self::Us => 'https://us.conversation.api.sinch.com',
            self::Eu => 'https://eu.conversation.api.sinch.com',
        };
    }

    /**
     * OAuth2 token endpoint base URL for this region (no trailing slash).
     *
     * Used when exchanging an API key/secret pair for a short-lived
     * bearer token via Sinch's central auth service.
     */
    public function authBaseUrl(): string
    {
        return match ($this) {
            self::Us => 'https://us.auth.sinch.com',
            self::Eu => 'https://eu.auth.sinch.com',
        };
    }

    /**
     * Template Management API base URL for this region (no trailing slash).
     *
     * Distinct host from the Conversations API; used by the
     * template-sync paths (createTemplate, listTemplates).
     */
    public function templateApiBaseUrl(): string
    {
        return match ($this) {
            self::Us => 'https://us.template.api.sinch.com',
            self::Eu => 'https://eu.template.api.sinch.com',
        };
    }
}
