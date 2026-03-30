<?php

/**
 * Central accessor for OpenEMR globals via OEGlobalsBag
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;

/**
 * Delegates configuration reads to OEGlobalsBag, OpenEMR's typed wrapper
 * around $GLOBALS. This eliminates direct superglobal access from module code.
 *
 * @internal Use ConfigFactory::createConfigAccessor() instead of instantiating directly
 */
class GlobalsAccessor implements ConfigAccessorInterface
{
    private readonly OEGlobalsBag $bag;

    public function __construct()
    {
        $this->bag = OEGlobalsBag::getInstance();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag->get($key, $default) ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->bag->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->bag->has($key);
    }

    public function getString(string $key, string $default = ''): string
    {
        return $this->bag->getString($key, $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        return $this->bag->getBoolean($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return $this->bag->getInt($key, $default);
    }

    /**
     * Get the OpenEMR Kernel instance from globals
     *
     * OEGlobalsBag doesn't have a typed kernel accessor, so we
     * narrow the type manually here.
     *
     * @throws \RuntimeException If the kernel is not available
     */
    public function getKernel(): Kernel
    {
        $kernel = $this->bag->get('kernel');
        if (!$kernel instanceof Kernel) {
            throw new \RuntimeException('OpenEMR Kernel not available');
        }
        return $kernel;
    }
}
