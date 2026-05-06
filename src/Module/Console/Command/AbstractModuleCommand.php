<?php

/**
 * Abstract base command for module management commands
 *
 * Handles OpenEMR bootstrapping and common options.
 *
 * @package   OpenEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Console\Command;

use OpenCoreEMR\Modules\SinchConversations\Console\ModuleInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractModuleCommand extends Command
{
    protected ?string $openemrPath = null;
    protected ?ModuleInstaller $installer = null;

    protected function configure(): void
    {
        $this->addOption(
            'site',
            's',
            InputOption::VALUE_REQUIRED,
            'OpenEMR site name',
            'default'
        );

        $this->addOption(
            'openemr-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'Path to OpenEMR installation'
        );
    }

    /**
     * Bootstrap OpenEMR and create the installer
     */
    protected function bootstrapOpenEmr(InputInterface $input, OutputInterface $output): bool
    {
        $pathOption = $input->getOption('openemr-path');
        $this->openemrPath = is_string($pathOption) ? $pathOption : $this->findOpenEmrPath();

        if (!$this->openemrPath) {
            $output->writeln('<error>Error: Could not find OpenEMR installation.</error>');
            $output->writeln('Use --openemr-path=/path/to/openemr to specify the path.');
            return false;
        }

        $globalsPath = $this->openemrPath . '/interface/globals.php';
        if (!file_exists($globalsPath)) {
            $output->writeln("<error>Error: Cannot find OpenEMR globals at: $globalsPath</error>");
            return false;
        }

        // Set up OpenEMR environment
        $siteOption = $input->getOption('site');
        $_GET['site'] = is_string($siteOption) ? $siteOption : 'default';

        /** @var bool $ignoreAuth */
        $ignoreAuth = true;
        /** @var bool $sessionAllowWrite */
        $sessionAllowWrite = true;

        // These variables are used by globals.php
        $GLOBALS['ignoreAuth'] = $ignoreAuth;
        $GLOBALS['sessionAllowWrite'] = $sessionAllowWrite;

        require_once $globalsPath;

        $this->installer = new ModuleInstaller($this->openemrPath, $output);

        return true;
    }

    /**
     * Get the module installer (throws if not initialized)
     */
    protected function getInstaller(): ModuleInstaller
    {
        if (!$this->installer instanceof ModuleInstaller) {
            throw new \RuntimeException('Installer not initialized. Call bootstrapOpenEmr() first.');
        }
        return $this->installer;
    }

    /**
     * Get module name from input argument
     */
    protected function getModuleName(InputInterface $input): string
    {
        $module = $input->getArgument('module');
        if (!is_string($module)) {
            throw new \InvalidArgumentException('Module name must be a string');
        }
        return $module;
    }

    /**
     * Find OpenEMR installation path
     */
    private function findOpenEmrPath(): ?string
    {
        $candidates = [
            // Docker container path (when module is symlinked into OpenEMR)
            '/var/www/localhost/htdocs/openemr',
            // Development: OpenEMR installed under tools/openemr (current layout — see issue #118)
            __DIR__ . '/../../../../tools/openemr/vendor/openemr/openemr',
            // Development: module has OpenEMR as a (legacy) composer dependency
            __DIR__ . '/../../../../vendor/openemr/openemr',
            __DIR__ . '/../../../../../vendor/openemr/openemr',
            // Development: module is inside OpenEMR's custom_modules
            __DIR__ . '/../../../../../../..',
            __DIR__ . '/../../../../../openemr',
        ];

        foreach ($candidates as $path) {
            $realPath = realpath($path);
            if ($realPath && file_exists($realPath . '/interface/globals.php')) {
                return $realPath;
            }
        }

        return null;
    }
}
