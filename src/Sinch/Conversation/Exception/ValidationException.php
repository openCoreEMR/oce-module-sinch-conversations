<?php

/**
 * Exception thrown when input validation fails (400)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class ValidationException extends BaseException
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP 400 Bad Request
     */
    public function getStatusCode(): int
    {
        return 400;
    }
}
