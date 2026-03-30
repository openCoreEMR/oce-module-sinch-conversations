<?php

/**
 * Exception thrown when a requested resource is not found (404)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class NotFoundException extends BaseException
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP 404 Not Found
     */
    public function getStatusCode(): int
    {
        return 404;
    }
}
