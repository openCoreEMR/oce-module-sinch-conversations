<?php

/**
 * Command to enable an OpenEMR module
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:enable',
    description: 'Enable an installed module'
)]
class ModuleEnableCommand extends AbstractModuleCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument(
            'module',
            InputArgument::REQUIRED,
            'Module directory name (e.g., oce-module-sinch-conversations)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->bootstrapOpenEmr($input, $output)) {
            return Command::FAILURE;
        }

        $moduleName = $this->getModuleName($input);

        try {
            $this->getInstaller()->enable($moduleName);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
