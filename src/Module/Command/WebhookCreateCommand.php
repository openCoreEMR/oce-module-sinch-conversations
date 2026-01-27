<?php

/**
 * Create Webhook Command
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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class WebhookCreateCommand extends Command
{
    protected static $defaultName = 'sinch:webhook:create';

    protected function configure(): void
    {
        $this
            ->setDescription('Create a webhook for a Sinch app')
            ->setHelp('Creates a new webhook to receive message events and delivery receipts.')
            ->addArgument('url', InputArgument::REQUIRED, 'Webhook target URL (e.g., https://example.com/webhook)')
            ->addOption('project-id', 'p', InputOption::VALUE_REQUIRED, 'Sinch Project ID')
            ->addOption('app-id', 'a', InputOption::VALUE_REQUIRED, 'Sinch App ID')
            ->addOption('api-key', 'k', InputOption::VALUE_REQUIRED, 'Sinch API Key ID')
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'Sinch Region (us or eu)', 'us')
            ->addOption(
                'triggers',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Event triggers (e.g., MESSAGE_DELIVERY, MESSAGE_INBOUND)',
                ['MESSAGE_DELIVERY', 'MESSAGE_INBOUND']
            )
            ->addOption('secret', 's', InputOption::VALUE_REQUIRED, 'Webhook secret for signature verification');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create Sinch Webhook');

        $config = $this->getConfiguration($input);
        $url = $input->getArgument('url');
        $triggers = $input->getOption('triggers');
        $secret = $input->getOption('secret');

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

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $io->error('Invalid URL provided');
            return Command::FAILURE;
        }

        $webhookData = [
            'target' => $url,
            'triggers' => $triggers,
        ];

        if ($secret) {
            $webhookData['secret'] = $secret;
        }

        try {
            $apiClient = new AppConfigurationClient(new StandaloneConfig($config));

            $io->text("Creating webhook for {$url}...");
            $webhook = $apiClient->createWebhook($webhookData, $config['app_id']);

            $io->success('Webhook created successfully!');
            $io->definitionList(
                ['Webhook ID' => $webhook['id'] ?? 'N/A'],
                ['Target URL' => $webhook['target'] ?? 'N/A'],
                ['Triggers' => implode(', ', $webhook['triggers'] ?? [])]
            );

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
