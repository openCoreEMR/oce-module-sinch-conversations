<?php

/**
 * Traceable error ID generator
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

class ErrorId
{
    /**
     * Generate a short, unique error reference ID
     *
     * Used to correlate user-facing error messages with log entries
     * without exposing exception details. Returns an 8-character hex string.
     */
    public static function generate(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return substr(md5(uniqid((string) microtime(true), true)), 0, 8);
        }
    }
}
