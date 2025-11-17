<?php

/**
 * Central accessor for OpenEMR globals
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * Provides centralized access to OpenEMR globals.
 * This class serves as a single point of abstraction for globals access,
 * making it easier to update or refactor in the future.
 */
class GlobalsAccessor
{
    /**
     * Get a value from globals
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $GLOBALS[$key] ?? $default;
    }

    /**
     * Set a value in globals
     */
    public function set(string $key, mixed $value): void
    {
        $GLOBALS[$key] = $value;
    }

    /**
     * Check if a key exists in globals
     */
    public function has(string $key): bool
    {
        return isset($GLOBALS[$key]);
    }

    /**
     * Get a string value from globals
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Get a boolean value from globals
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        // Handle string/numeric boolean values
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get an integer value from globals
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_int($value) ? $value : (int)$value;
    }

    /**
     * Get all globals
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $GLOBALS;
    }
}
