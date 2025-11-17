<?php

/**
 * Base exception interface for Sinch Conversation API exceptions
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

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
