<?php

/**
 * Mock OEGlobalsBag for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Minimal stand-in for OpenEMR's singleton globals bag. The real implementation
 * lives in tools/openemr/vendor/openemr/openemr/src/Core/OEGlobalsBag.php and is
 * intentionally not on the runtime autoloader (see issue #118 and
 * tools/openemr/README.md). This mock provides just enough surface for module
 * code under test to construct and call into.
 */
class OEGlobalsBag extends ParameterBag
{
    private static ?OEGlobalsBag $instance = null;

    public static function getInstance(): OEGlobalsBag
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Fall back to $GLOBALS, mirroring the real OEGlobalsBag so module code
     * that reads config via $GLOBALS[...] (e.g. tests setting
     * $GLOBALS['oce_sinch_conversations_enabled'] = '1') still resolves.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!parent::has($key) && array_key_exists($key, $GLOBALS)) {
            return $GLOBALS[$key];
        }
        return parent::get($key, $default);
    }

    public function has(string $key): bool
    {
        if (parent::has($key)) {
            return true;
        }
        return array_key_exists($key, $GLOBALS);
    }
}
