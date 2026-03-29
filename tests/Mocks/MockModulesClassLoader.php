<?php

/**
 * Mock ModulesClassLoader for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Core;

/**
 * Mock ModulesClassLoader to avoid requiring a real vendor/autoload.php
 *
 * Replaces the real class so background_service_entry.php can be tested
 * without a full OpenEMR installation.
 */
class ModulesClassLoader
{
    /** @var array<int, array{namespace: string, paths: string|string[]}> */
    private static array $registered = [];

    public function __construct(string $webRootPath)
    {
        // No-op: real class does require $webRootPath/vendor/autoload.php
    }

    /**
     * @param string          $namespace
     * @param string[]|string $paths
     */
    public function registerNamespaceIfNotExists(string $namespace, string|array $paths): bool
    {
        self::$registered[] = ['namespace' => $namespace, 'paths' => $paths];
        return true;
    }

    /**
     * @return array<int, array{namespace: string, paths: string|string[]}>
     */
    public static function getRegistered(): array
    {
        return self::$registered;
    }

    public static function clearRegistered(): void
    {
        self::$registered = [];
    }
}
