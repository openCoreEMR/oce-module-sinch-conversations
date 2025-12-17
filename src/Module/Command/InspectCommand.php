<?php

/**
 * Sinch Configuration Inspector Command
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

class InspectCommand extends Command
{
    protected static $defaultName = 'sinch:inspect';

    protected function configure(): void
    {
        $this
            ->setDescription('Inspect Sinch Conversations API configuration')
            ->setHelp('Fetches and displays your Sinch app configuration, including channels and senders.')
            ->addOption(
                'project-id',
                'p',
                InputOption::VALUE_REQUIRED,
                'Sinch Project ID'
            )
            ->addOption(
                'app-id',
                'a',
                InputOption::VALUE_REQUIRED,
                'Sinch App ID'
            )
            ->addOption(
                'api-key',
                'k',
                InputOption::VALUE_REQUIRED,
                'Sinch API Key ID'
            )
            ->addOption(
                'region',
                'r',
                InputOption::VALUE_REQUIRED,
                'Sinch Region (us or eu)',
                'us'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Sinch Configuration Inspector');

        // Get configuration from options or environment
        $config = $this->getConfiguration($input);

        // Validate required fields
        $missing = [];
        foreach (['project_id', 'app_id', 'api_key', 'api_secret'] as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $io->error('Missing required configuration: ' . implode(', ', $missing));
            $io->note([
                'Provide configuration via:',
                '  1. Command options: --project-id, --app-id, --api-key, --region',
                '  2. Environment variables: SINCH_PROJECT_ID, SINCH_APP_ID, SINCH_API_KEY, SINCH_REGION',
                '  3. API Secret (REQUIRED): SINCH_API_SECRET environment variable only (never CLI)',
            ]);
            return Command::FAILURE;
        }

        try {
            // Create API client (without OpenEMR globals)
            $apiClient = $this->createApiClient($config);

            $io->section('Project Configuration');
            $io->listing([
                "Project ID: {$config['project_id']}",
                "App ID: {$config['app_id']}",
                "Region: {$config['region']}",
            ]);

            // Authenticate
            $io->text('Authenticating...');
            $token = $apiClient->getOAuth2Token();
            $io->success('✓ Authenticated');

            // Get app configuration
            $io->text('Fetching app configuration...');
            $appConfig = $apiClient->getApp($config['app_id']);
            $io->success('✓ Configuration retrieved');

            // Display app details
            $io->section('App Details');
            $io->definitionList(
                ['Display Name' => $appConfig['display_name'] ?? 'N/A'],
                ['Metadata Reporting' => $appConfig['conversation_metadata_report_view'] ?? 'NONE']
            );

            if (isset($appConfig['dispatch_retention_policy'])) {
                $io->definitionList(
                    ['Retention Policy' => $appConfig['dispatch_retention_policy']['retention_type'] ?? 'N/A']
                );
            }

            // Display channels
            $io->section('Channels');

            if (empty($appConfig['channel_credentials'])) {
                $io->warning('⚠️  No channels configured!');
                $io->note([
                    'To send messages, configure at least one channel in your Sinch app.',
                    'Visit: https://dashboard.sinch.com',
                ]);
            } else {
                foreach ($appConfig['channel_credentials'] as $channel) {
                    $channelType = strtoupper($channel['channel'] ?? 'UNKNOWN');
                    $state = $channel['state'] ?? 'UNKNOWN';

                    $io->text("<fg=cyan;options=bold>{$channelType}</>");

                    $details = ["  State: {$state}"];

                    if (isset($channel['static_bearer']['claimed_identity'])) {
                        $details[] = "  Sender: {$channel['static_bearer']['claimed_identity']}";
                    }

                    if (isset($channel['static_token'])) {
                        $details[] = "  Token: Configured";
                    }

                    $io->listing($details);

                    if ($state !== 'ACTIVE') {
                        $io->warning("  ⚠️  Channel is not ACTIVE - messages may fail");
                    }
                }
            }

            $io->success('Inspection complete');

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
     * Get configuration from input options or environment variables
     *
     * @param InputInterface $input
     * @return array<string, string>
     */
    private function getConfiguration(InputInterface $input): array
    {
        return [
            'project_id' => $input->getOption('project-id') ?: getenv('SINCH_PROJECT_ID') ?: '',
            'app_id' => $input->getOption('app-id') ?: getenv('SINCH_APP_ID') ?: '',
            'api_key' => $input->getOption('api-key') ?: getenv('SINCH_API_KEY') ?: '',
            'api_secret' => getenv('SINCH_API_SECRET') ?: '', // Only from env var, never CLI
            'region' => $input->getOption('region') ?: getenv('SINCH_REGION') ?: 'us',
        ];
    }

    /**
     * Create API client with given configuration
     *
     * @param array<string, string> $config
     * @return AppConfigurationClient
     */
    private function createApiClient(array $config): AppConfigurationClient
    {
        $configObject = new StandaloneConfig($config);
        return new AppConfigurationClient($configObject);
    }
}
