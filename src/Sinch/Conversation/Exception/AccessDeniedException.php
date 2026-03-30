<?php

/**
 * Exception thrown when access is forbidden (403)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Exception;

class AccessDeniedException extends BaseException
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP 403 Forbidden
     */
    public function getStatusCode(): int
    {
        return 403;
    }
}
