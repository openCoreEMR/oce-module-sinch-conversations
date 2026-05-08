<?php

/**
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Common;

/**
 * Optional, type-narrowed reads from `mixed` data.
 *
 * `Json::decode()` returns `mixed` (per JSON's grammar — anything from a
 * scalar to a deeply nested object). Reading a leaf value out of one is
 * the same operation every time:
 *
 *   1. Walk N keys.
 *   2. At each step, return null if we ran off the end or hit a non-array.
 *   3. At the leaf, narrow to the expected type or return null.
 *
 * Without this helper every call site reinvents the chain of
 * `is_array() && isset() && is_array() && isset() && is_string()` guards
 * needed to keep PHP 8 from emitting "Trying to access array offset on
 * value of type ..." warnings. With it, the call site is one line.
 */
final class ArrayPath
{
    private function __construct()
    {
    }

    /**
     * Read a string value at the given key path, or null if the path
     * doesn't lead to a string.
     */
    public static function stringAt(mixed $data, string ...$path): ?string
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return is_string($data) ? $data : null;
    }

    /**
     * Return the first non-empty string found at any of the given
     * top-level keys, or null. Order matters.
     */
    public static function firstNonEmptyString(mixed $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::stringAt($data, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
