<?php

/**
 * Base exception class for Sinch Conversation API
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Sinch\Conversation\Exception;

abstract class BaseException extends \RuntimeException implements ExceptionInterface
{
    /**
     * Get the HTTP status code for this exception
     *
     * @return int HTTP status code
     */
    abstract public function getStatusCode(): int;
}
