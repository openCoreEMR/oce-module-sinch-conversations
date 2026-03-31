<?php

/**
 * Mock config accessor for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Mocks;

use OpenCoreEMR\ModuleConfig\ConfigAccessorInterface;

class MockGlobalsAccessor implements ConfigAccessorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? $default;
        return is_string($value) ? $value : (string) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->data[$key] ?? $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
