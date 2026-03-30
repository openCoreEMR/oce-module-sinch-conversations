<?php

/**
 * Exception thrown when Sinch Conversation API returns an error (500)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class ApiException extends BaseException
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP 500 Internal Server Error
     */
    public function getStatusCode(): int
    {
        return 500;
    }
}
