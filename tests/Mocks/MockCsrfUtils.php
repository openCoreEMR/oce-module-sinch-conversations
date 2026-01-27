<?php

/**
 * Mock CsrfUtils for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Csrf;

/**
 * Mock CsrfUtils to avoid CSRF checks during tests
 */
class CsrfUtils
{
    private static bool $verifyResult = true;

    public static function collectCsrfToken(): string
    {
        return 'test-csrf-token';
    }

    public static function verifyCsrfToken(string $token): bool
    {
        return self::$verifyResult;
    }

    public static function csrfNotVerified(): void
    {
        throw new \Exception('CSRF token verification failed');
    }

    public static function setVerifyResult(bool $result): void
    {
        self::$verifyResult = $result;
    }

    public static function reset(): void
    {
        self::$verifyResult = true;
    }
}
