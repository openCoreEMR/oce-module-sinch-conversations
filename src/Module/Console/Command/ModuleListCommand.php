<?php

/**
 * Command to list all registered OpenEMR modules
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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'module:list',
    description: 'List all registered OpenEMR modules'
)]
class ModuleListCommand extends AbstractModuleCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->bootstrapOpenEmr($input, $output)) {
            return Command::FAILURE;
        }

        $modules = $this->getInstaller()->listModules();

        $output->writeln('<info>Registered Modules:</info>');
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['ID', 'Directory', 'Installed', 'Enabled', 'Type']);

        foreach ($modules as $row) {
            $installed = $row['sql_run'] ? '<info>Yes</info>' : '<comment>No</comment>';
            $enabled = $row['mod_active'] ? '<info>Yes</info>' : '<comment>No</comment>';
            $type = $row['type'] == 0 ? 'Custom' : 'Laminas';

            $table->addRow([
                $row['mod_id'],
                $row['mod_directory'],
                $installed,
                $enabled,
                $type,
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
