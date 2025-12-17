<?php

/**
 * List Webhooks Command
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Command;

use OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient;
use OpenCoreEMR\Sinch\Conversation\Config\StandaloneConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class WebhookListCommand extends Command
{
    protected static $defaultName = 'sinch:webhook:list';

    protected function configure(): void
    {
        $this
            ->setDescription('List webhooks for a Sinch app')
            ->setHelp('Lists all webhooks configured for a Sinch Conversation app.')
            ->addOption('project-id', 'p', InputOption::VALUE_REQUIRED, 'Sinch Project ID')
            ->addOption('app-id', 'a', InputOption::VALUE_REQUIRED, 'Sinch App ID')
            ->addOption('api-key', 'k', InputOption::VALUE_REQUIRED, 'Sinch API Key ID')
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'Sinch Region (us or eu)', 'us');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sinch Webhooks');

        $config = $this->getConfiguration($input);

        $missing = [];
        foreach (['project_id', 'app_id', 'api_key', 'api_secret'] as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $io->error('Missing required configuration: ' . implode(', ', $missing));
            return Command::FAILURE;
        }

        try {
            $apiClient = new AppConfigurationClient(new StandaloneConfig($config));

            $webhooks = $apiClient->listWebhooks($config['app_id']);

            if (empty($webhooks)) {
                $io->warning('No webhooks configured for this app.');
                $io->note('Create webhooks to receive message events and delivery receipts.');
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($webhooks as $webhook) {
                $rows[] = [
                    $webhook['id'] ?? 'N/A',
                    $webhook['target'] ?? 'N/A',
                    implode(', ', $webhook['triggers'] ?? []),
                ];
            }

            $io->table(['Webhook ID', 'Target URL', 'Triggers'], $rows);
            $io->success(sprintf('Found %d webhook(s)', count($webhooks)));

            return Command::SUCCESS;
        } catch (ApiException $e) {
            $io->error('API Error: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * @param InputInterface $input
     * @return array<string, string>
     */
    private function getConfiguration(InputInterface $input): array
    {
        return [
            'project_id' => $input->getOption('project-id') ?: getenv('SINCH_PROJECT_ID') ?: '',
            'app_id' => $input->getOption('app-id') ?: getenv('SINCH_APP_ID') ?: '',
            'api_key' => $input->getOption('api-key') ?: getenv('SINCH_API_KEY') ?: '',
            'api_secret' => getenv('SINCH_API_SECRET') ?: '',
            'region' => $input->getOption('region') ?: getenv('SINCH_REGION') ?: 'us',
        ];
    }
}
