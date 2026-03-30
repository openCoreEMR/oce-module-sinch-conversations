<?php

/**
 * Mock GlobalsAccessor for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Mocks;

use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;

class MockGlobalsAccessor extends GlobalsAccessor
{
    /**
     * @var array<string, mixed>
     */
    private array $mockData = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->mockData = $data;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->mockData[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->mockData[$key] ?? $default;
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int)($this->mockData[$key] ?? $default);
    }

    /**
     * @param string $key
     * @param bool $default
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->mockData[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function has(string $key): bool
    {
        return isset($this->mockData[$key]);
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        $this->mockData[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->mockData;
    }
}
