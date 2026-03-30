<?php

/**
 * Load and merge YAML configuration files
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenCoreEMR\Sinch\Conversation\Exception\ConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse YAML config files, process imports, and merge into a flat array.
 *
 * Supports Symfony-style `imports` for splitting config across files:
 *
 *     imports:
 *       - { resource: secrets.yaml }
 *
 * Imported files resolve relative to the importing file's directory.
 * Parent file keys override imported file keys (later overrides earlier).
 */
class YamlConfigLoader
{
    /**
     * Load and merge multiple YAML config files
     *
     * Later files override earlier files. Each file's own keys override
     * keys from its imports.
     *
     * @param list<string> $filePaths absolute paths to YAML files
     * @return array<string, mixed> merged configuration
     * @throws ConfigurationException if a file exists but is not readable or contains invalid YAML
     */
    public function load(array $filePaths): array
    {
        $merged = [];
        foreach ($filePaths as $filePath) {
            $fileData = $this->loadFile($filePath);
            $merged = array_merge($merged, $fileData);
        }
        return $merged;
    }

    /**
     * Check if any config files exist at conventional or overridden paths
     *
     * @param list<string> $paths paths to check
     */
    public function hasConfigFiles(array $paths): bool
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter a list of paths to only those that exist
     *
     * @param list<string> $paths candidate paths
     * @return list<string> existing paths
     */
    public function resolveFilePaths(array $paths): array
    {
        $existing = [];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $existing[] = $path;
            }
        }
        return $existing;
    }

    /**
     * Load a single YAML file, processing any imports
     *
     * @return array<string, mixed>
     * @throws ConfigurationException if file is not readable or contains invalid YAML
     */
    private function loadFile(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new ConfigurationException(
                sprintf('Configuration file is not readable: %s', $filePath)
            );
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new ConfigurationException(
                sprintf('Failed to read configuration file: %s', $filePath)
            );
        }

        try {
            $data = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw new ConfigurationException(
                sprintf('Invalid YAML in configuration file %s: %s', $filePath, $e->getMessage()),
                0,
                $e
            );
        }

        if ($data === null) {
            return [];
        }

        if (!is_array($data)) {
            throw new ConfigurationException(
                sprintf('Configuration file must contain a YAML mapping, got %s: %s', get_debug_type($data), $filePath)
            );
        }

        // Process imports
        $importedData = [];
        if (isset($data['imports']) && is_array($data['imports'])) {
            $baseDir = dirname($filePath);
            foreach ($data['imports'] as $import) {
                $resource = is_array($import) ? ($import['resource'] ?? null) : $import;
                if ($resource === null || !is_string($resource)) {
                    continue;
                }
                $importPath = $baseDir . DIRECTORY_SEPARATOR . $resource;
                $importedData = array_merge($importedData, $this->loadFile($importPath));
            }
            unset($data['imports']);
        }

        // Parent file keys override imported keys
        /** @var array<string, mixed> */
        return array_merge($importedData, $data);
    }
}
