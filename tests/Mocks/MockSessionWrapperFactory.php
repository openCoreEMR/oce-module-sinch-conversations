<?php

/**
 * Mock SessionWrapperFactory for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Session;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Minimal stand-in for OpenEMR's singleton session factory. The real
 * implementation lives in tools/openemr and is intentionally not on the
 * runtime autoloader (see issue #118 and tools/openemr/README.md). This mock
 * hands module code an in-memory Symfony session so CsrfUtils call sites can
 * resolve a SessionInterface under test.
 */
class SessionWrapperFactory
{
    private static ?SessionWrapperFactory $instance = null;

    private ?SessionInterface $activeSession = null;

    public static function getInstance(): SessionWrapperFactory
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function getActiveSession(): SessionInterface
    {
        return $this->activeSession ??= new Session(new MockArraySessionStorage());
    }
}
