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

declare(strict_types=1);

namespace OpenEMR\Common\Csrf;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Mock CsrfUtils to avoid CSRF checks during tests.
 *
 * Signatures mirror the real oce-810 CsrfUtils (which requires a
 * SessionInterface) so call sites passing a session resolve correctly.
 */
class CsrfUtils
{
    private static bool $verifyResult = true;

    public static function collectCsrfToken(SessionInterface $session, string $subject = 'default'): string
    {
        return 'test-csrf-token';
    }

    public static function verifyCsrfToken($token, SessionInterface $session, string $subject = 'default'): bool
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
