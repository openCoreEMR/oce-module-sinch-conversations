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
 * Typed wrapper around PHP's `json_encode` / `json_decode`.
 *
 * The legacy functions return `string|false` and `mixed|false` respectively
 * and rely on the caller to remember `JSON_THROW_ON_ERROR`. Per ocemr-docs
 * `reference/php-conventions.md` § "No `false`-on-Error APIs", `json_encode`
 * and `json_decode` are off-limits at call sites — they live here, where the
 * `false` failure mode is converted to a `JsonException` and the return type
 * is honest.
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @throws \JsonException
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * Decode JSON into an associative array (or scalar / list of same).
     *
     * @return mixed
     * @throws \JsonException
     */
    public static function decode(string $json, int $flags = 0): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR | $flags);
    }
}
