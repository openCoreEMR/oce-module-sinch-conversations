<?php

/**
 * Module installer service for OpenEMR custom modules
 *
 * Handles register, install, enable, disable, and unregister operations.
 *
 * @package   OpenEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Console;

use OpenEMR\Common\Database\QueryUtils;
use Symfony\Component\Console\Output\OutputInterface;

class ModuleInstaller
{
    private readonly string $customModulesPath;

    public function __construct(private readonly string $openemrPath, private readonly OutputInterface $output)
    {
        $this->customModulesPath = $this->openemrPath . '/interface/modules/custom_modules';
    }

    /**
     * Get module info from database
     *
     * @return array<string, mixed>|null
     */
    public function getModuleInfo(string $moduleName): ?array
    {
        $sql = "SELECT * FROM modules WHERE mod_directory = ?";
        $result = QueryUtils::querySingleRow($sql, [$moduleName]);
        return $result ?: null;
    }

    /**
     * Extract mod_id from module info array
     *
     * @param array<string, mixed> $module
     */
    private function getModId(array $module): int
    {
        $modId = $module['mod_id'] ?? null;
        if (!is_numeric($modId)) {
            throw new \RuntimeException('Invalid mod_id in module record');
        }
        return (int) $modId;
    }

    /**
     * Register a module in the database
     */
    public function register(string $moduleName): void
    {
        $this->output->writeln("Registering module: <info>$moduleName</info>");

        $existing = $this->getModuleInfo($moduleName);
        if ($existing) {
            $modId = $existing['mod_id'] ?? 'unknown';
            $modIdStr = is_scalar($modId) ? (string) $modId : 'unknown';
            $this->output->writeln("  Module is already registered (ID: $modIdStr)");
            return;
        }

        $moduleDir = $this->customModulesPath . '/' . $moduleName;
        if (!is_dir($moduleDir)) {
            throw new \RuntimeException("Module directory not found: $moduleDir");
        }

        $infoFile = $moduleDir . '/info.txt';
        if (file_exists($infoFile)) {
            $lines = file($infoFile);
            $name = trim($lines[0] ?? $moduleName);
        } else {
            $name = $moduleName;
        }

        $maxId = QueryUtils::querySingleRow("SELECT MAX(section_id) as max_id FROM module_acl_sections", []);
        $sectionId = ($maxId['max_id'] ?? 0) + 1;

        $sql = "INSERT INTO modules SET
                mod_id = ?,
                mod_name = ?,
                mod_active = 0,
                mod_ui_name = ?,
                mod_relative_link = ?,
                mod_directory = ?,
                type = 0,
                date = NOW()";

        $uiName = ucwords(strtolower(str_replace('-', ' ', $moduleName)));
        $relPath = $moduleName . '/index.php';

        QueryUtils::sqlStatementThrowException($sql, [
            $sectionId,
            $name,
            $uiName,
            strtolower($relPath),
            $moduleName
        ]);

        $moduleId = QueryUtils::querySingleRow("SELECT mod_id FROM modules WHERE mod_directory = ?", [$moduleName]);
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO module_acl_sections VALUES (?, ?, 0, ?, ?)",
            [$moduleId['mod_id'], $name, strtolower($moduleName), $moduleId['mod_id']]
        );

        $this->output->writeln("  Registered with ID: <info>{$moduleId['mod_id']}</info>");
    }

    /**
     * Install module SQL
     */
    public function install(string $moduleName): void
    {
        $this->output->writeln("Installing module: <info>$moduleName</info>");

        $module = $this->getModuleInfo($moduleName);
        if (!$module) {
            throw new \RuntimeException("Module not registered. Run register first.");
        }

        if ($module['sql_run']) {
            $this->output->writeln("  SQL already installed");
            return;
        }

        $moduleDir = $this->customModulesPath . '/' . $moduleName;
        $installScript = $this->findInstallScript($moduleDir);

        if (!$installScript) {
            $this->output->writeln("  No SQL install script found (skipping)");
            $this->markSqlInstalled($this->getModId($module), $moduleName);
            return;
        }

        $this->output->writeln("  Running: <comment>$installScript</comment>");

        $fileName = basename($installScript);
        $dir = dirname($installScript);

        $sqlUpgradeService = new \OpenEMR\Services\Utils\SQLUpgradeService();
        $sqlUpgradeService->setRenderOutputToScreen(false);

        ob_start();
        $sqlUpgradeService->upgradeFromSqlFile($fileName, $dir);
        ob_end_clean();

        $this->markSqlInstalled($this->getModId($module), $moduleName);
        $this->output->writeln("  SQL installation complete");
    }

    /**
     * Enable a module
     */
    public function enable(string $moduleName): void
    {
        $this->output->writeln("Enabling module: <info>$moduleName</info>");

        $module = $this->getModuleInfo($moduleName);
        if (!$module) {
            throw new \RuntimeException("Module not registered. Run register first.");
        }

        if ($module['mod_active']) {
            $this->output->writeln("  Module is already enabled");
            return;
        }

        $this->callModuleListener($moduleName, 'preenable', $this->getModId($module));

        QueryUtils::sqlStatementThrowException(
            "UPDATE modules SET mod_active = 1, date = NOW() WHERE mod_id = ?",
            [$module['mod_id']]
        );

        $this->callModuleListener($moduleName, 'enable', $this->getModId($module));

        $this->output->writeln("  Module enabled");
    }

    /**
     * Disable a module
     */
    public function disable(string $moduleName): void
    {
        $this->output->writeln("Disabling module: <info>$moduleName</info>");

        $module = $this->getModuleInfo($moduleName);
        if (!$module) {
            throw new \RuntimeException("Module not found in database.");
        }

        if (!$module['mod_active']) {
            $this->output->writeln("  Module is already disabled");
            return;
        }

        $this->callModuleListener($moduleName, 'predisable', $this->getModId($module));

        QueryUtils::sqlStatementThrowException(
            "UPDATE modules SET mod_active = 0, date = NOW() WHERE mod_id = ?",
            [$module['mod_id']]
        );

        $this->callModuleListener($moduleName, 'disable', $this->getModId($module));

        $this->output->writeln("  Module disabled");
    }

    /**
     * Unregister a module
     */
    public function unregister(string $moduleName): void
    {
        $this->output->writeln("Unregistering module: <info>$moduleName</info>");

        $module = $this->getModuleInfo($moduleName);
        if (!$module) {
            $this->output->writeln("  Module not found in database");
            return;
        }

        if ($module['mod_active']) {
            throw new \RuntimeException("Cannot unregister an enabled module. Disable it first.");
        }

        $this->callModuleListener($moduleName, 'unregister', $this->getModId($module));

        QueryUtils::sqlStatementThrowException("DELETE FROM modules WHERE mod_id = ?", [$module['mod_id']]);
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM module_acl_sections WHERE module_id = ?",
            [$module['mod_id']]
        );

        $this->output->writeln("  Module unregistered");
    }

    /**
     * List all registered modules
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModules(): array
    {
        $sql = "SELECT mod_id, mod_name, mod_directory, mod_active, sql_run, type FROM modules ORDER BY mod_directory";
        return QueryUtils::fetchRecords($sql, []);
    }

    /**
     * Find the SQL install script
     */
    private function findInstallScript(string $moduleDir): ?string
    {
        $candidates = [
            $moduleDir . '/sql/install.sql',
            $moduleDir . '/sql/table.sql',
            $moduleDir . '/install.sql',
            $moduleDir . '/table.sql',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Mark SQL as installed
     */
    private function markSqlInstalled(int $modId, string $moduleName): void
    {
        $moduleDir = $this->customModulesPath . '/' . $moduleName;
        $version = $this->getModuleVersion($moduleDir);

        QueryUtils::sqlStatementThrowException(
            "UPDATE modules SET sql_run = 1, sql_version = ?, date = NOW() WHERE mod_id = ?",
            [$version, $modId]
        );
    }

    /**
     * Get module version from version.php
     */
    private function getModuleVersion(string $moduleDir): ?string
    {
        $versionFile = $moduleDir . '/version.php';
        if (!file_exists($versionFile)) {
            return null;
        }

        $v_major = $v_minor = $v_patch = 0;
        include $versionFile;

        return "$v_major.$v_minor.$v_patch";
    }

    /**
     * Call ModuleManagerListener if it exists
     */
    private function callModuleListener(string $moduleName, string $action, int $modId): void
    {
        $moduleDir = $this->customModulesPath . '/' . $moduleName;
        $listenerFile = $moduleDir . '/ModuleManagerListener.php';

        if (!file_exists($listenerFile)) {
            return;
        }

        require_once $listenerFile;

        if (!class_exists('ModuleManagerListener')) {
            return;
        }

        try {
            $namespace = \ModuleManagerListener::getModuleNamespace();
            if (!empty($namespace)) {
                $sourcePath = \ModuleManagerListener::getModuleSourcePath();
                $classLoader = new \OpenEMR\Core\ModulesClassLoader($this->openemrPath);
                $classLoader->registerNamespaceIfNotExists($namespace, $moduleDir . $sourcePath);
            }

            $instance = \ModuleManagerListener::initListenerSelf();
            $result = $instance->moduleManagerAction($action, $modId, 'Success');
            if ($result !== 'Success') {
                $this->output->writeln("  <comment>ModuleManagerListener ($action): $result</comment>");
            }
        } catch (\Throwable $e) {
            $errorId = \OpenCoreEMR\Modules\SinchConversations\ErrorId::generate();
            (new \OpenEMR\Common\Logging\SystemLogger())->error(
                'ModuleManagerListener error',
                ['errorId' => $errorId, 'action' => $action, 'exception' => $e]
            );
            $this->output->writeln(
                "  <comment>ModuleManagerListener ($action) failed (ref: $errorId)</comment>"
            );
        }
    }
}
