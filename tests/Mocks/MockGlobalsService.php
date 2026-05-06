<?php

/**
 * Mock GlobalsService for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Globals;

/**
 * Minimal stand-in for OpenEMR's GlobalsService — just enough surface for the
 * GlobalsRegistrar pipeline used in this module's tests. See issue #118 and
 * tools/openemr/README.md.
 */
class GlobalsService
{
    /**
     * @param array<string, array<string, mixed>> $globalsMetadata
     * @param array<int, string> $userSpecificGlobals
     * @param array<int, string> $userSpecificTabs
     */
    public function __construct(
        private array $globalsMetadata = [],
        private array $userSpecificGlobals = [],
        private array $userSpecificTabs = [],
    ) {
    }

    public function createSection(string $section, bool|string $beforeSection = false): void
    {
        if (!isset($this->globalsMetadata[$section])) {
            $this->globalsMetadata[$section] = [];
        }
    }

    public function appendToSection(string $section, string $key, GlobalSetting $global): void
    {
        $this->globalsMetadata[$section][$key] = $global->format();
        if ($global->isUserSetting()) {
            $this->userSpecificGlobals[] = $key;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getGlobalsMetadata(): array
    {
        return $this->globalsMetadata;
    }

    /**
     * @return array<int, string>
     */
    public function getUserSpecificGlobals(): array
    {
        return $this->userSpecificGlobals;
    }

    /**
     * @return array<int, string>
     */
    public function getUserSpecificTabs(): array
    {
        return $this->userSpecificTabs;
    }
}
