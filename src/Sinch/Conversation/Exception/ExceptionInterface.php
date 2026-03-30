<?php

/**
 * Base exception interface for Sinch Conversation API exceptions
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Sinch\Conversation\Exception;

interface ExceptionInterface extends \Throwable
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int;
}
