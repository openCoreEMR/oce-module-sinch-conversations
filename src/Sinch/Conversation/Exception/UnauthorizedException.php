<?php

/**
 * Exception thrown when authentication is required (401)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class UnauthorizedException extends BaseException
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP 401 Unauthorized
     */
    public function getStatusCode(): int
    {
        return 401;
    }
}
