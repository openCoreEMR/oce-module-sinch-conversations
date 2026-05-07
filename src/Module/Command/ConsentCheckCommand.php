<?php

/**
 * Consent Check Command - Test ADR-0001 refutation conditions
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Command;

use OpenCoreEMR\Modules\SinchConversations\Common\Json;
use OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient;
use OpenCoreEMR\Sinch\Conversation\Config\StandaloneConfig;
use OpenCoreEMR\Sinch\Conversation\Exception\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sinch:consent:check',
    description: 'Test ADR-0001 refutation conditions against the live Sinch API',
)]
class ConsentCheckCommand extends Command
{
    use WritesExceptionChain;

    private const STATUS_PASSED = 'PASSED';
    private const STATUS_REFUTED = 'REFUTED';
    private const STATUS_INCONCLUSIVE = 'INCONCLUSIVE';

    protected function configure(): void
    {
        $this
            ->setDescription('Test ADR-0001 refutation conditions against the live Sinch API')
            ->setHelp(
                'Run diagnostic checks against the Sinch Consent Management API to validate ' .
                'assumptions in ADR-0001 (Dispatch Mode Consent Management). Each check maps to ' .
                'a refutation condition in the ADR.'
            )
            ->addOption('project-id', 'p', InputOption::VALUE_REQUIRED, 'Sinch Project ID')
            ->addOption('api-key', 'k', InputOption::VALUE_REQUIRED, 'Sinch API Key ID')
            // Known limitation: AppConfigurationClient hard-codes the US base URL.
            // The region option is captured here for future use but does not yet
            // affect the API endpoint. See #62 for planned API client improvements.
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'Sinch Region (us or eu)', 'us')
            ->addOption('app-id', 'a', InputOption::VALUE_REQUIRED, 'Sinch App ID')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Phone number to test (E.164 format)')
            ->addOption('test-send', null, InputOption::VALUE_NONE, 'Actually send a test message (costs money)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ADR-0001 Refutation Condition Check');

        $config = $this->getConfiguration($input);

        $missing = [];
        foreach (['project_id', 'api_key', 'api_secret'] as $key) {
            if ($config[$key] === '') {
                $missing[] = $key;
            }
        }

        if (count($missing) > 0) {
            $message = 'Missing required configuration: ' . implode(', ', $missing);
            if (in_array('api_secret', $missing, true)) {
                $message .= PHP_EOL
                    . 'Note: SINCH_API_SECRET must be set in the environment; it is not accepted as a CLI option.';
            }
            $io->error($message);
            return Command::FAILURE;
        }

        if ($config['app_id'] === '') {
            $io->error('App ID is required. Use --app-id or set SINCH_APP_ID env var.');
            return Command::FAILURE;
        }

        $apiClient = new AppConfigurationClient(new StandaloneConfig($config));
        $appId = $config['app_id'];
        $phone = $input->getOption('phone');
        $testSend = $input->getOption('test-send');

        /** @var array<int, array{check: string, condition: string, status: string, detail: string}> $results */
        $results = [];

        // Check 1: App Configuration
        $results[] = $this->checkAppConfiguration($io, $output, $apiClient, $appId);

        // Check 2: Consent API Discovery (Refutation Condition 1)
        $results = array_merge($results, $this->checkConsentApiDiscovery($io, $output, $apiClient, $appId, $phone));

        // Check 3: Send to Opted-Out Number (Refutation Condition 5)
        if (is_string($phone) && $phone !== '' && $testSend) {
            $results[] = $this->checkSendToOptedOut($io, $output, $apiClient, $phone);
        } elseif (is_string($phone) && $phone !== '' && !$testSend) {
            $results[] = [
                'check' => 'Send to Opted-Out',
                'condition' => 'RC-5: Platform blocks opted-out sends',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => 'Skipped: use --test-send to enable (costs money)',
            ];
        } else {
            $results[] = [
                'check' => 'Send to Opted-Out',
                'condition' => 'RC-5: Platform blocks opted-out sends',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => 'Skipped: --phone not provided',
            ];
        }

        // Print results table
        $io->newLine();
        $io->section('Results');

        $rows = [];
        foreach ($results as $result) {
            $status = $result['status'];
            $formatted = match ($status) {
                self::STATUS_PASSED => "<info>{$status}</info>",
                self::STATUS_REFUTED => "<error>{$status}</error>",
                default => "<comment>{$status}</comment>",
            };
            $rows[] = [$result['check'], $result['condition'], $formatted, $result['detail']];
        }

        $io->table(['Check', 'Condition', 'Status', 'Detail'], $rows);

        $refuted = array_filter($results, fn(array $r): bool => $r['status'] === self::STATUS_REFUTED);
        if (count($refuted) > 0) {
            $io->warning(sprintf('%d condition(s) refuted. Review ADR-0001 assumptions.', count($refuted)));
            return Command::FAILURE;
        }

        $io->success('All testable conditions passed or were inconclusive.');
        return Command::SUCCESS;
    }

    /**
     * Check 1: Verify app configuration and consent management status
     *
     * @return array{check: string, condition: string, status: string, detail: string}
     */
    private function checkAppConfiguration(
        SymfonyStyle $io,
        OutputInterface $output,
        AppConfigurationClient $apiClient,
        string $appId
    ): array {
        $io->section('Check 1: App Configuration');

        try {
            $app = $apiClient->getApp($appId);
        } catch (ApiException $e) {
            $io->error('Failed to fetch app: ' . $e->getMessage());
            $this->writeExceptionChain($io, $output, $e);
            return [
                'check' => 'App Config',
                'condition' => 'App is accessible',
                'status' => self::STATUS_REFUTED,
                'detail' => 'API call failed: ' . $this->messageWithCause($e),
            ];
        }

        $processingMode = $app['processing_mode'] ?? 'UNKNOWN';
        $io->text("App ID: {$appId}");
        $displayName = is_string($app['display_name'] ?? null) ? $app['display_name'] : 'N/A';
        $io->text("Display Name: {$displayName}");
        $io->text("Processing Mode: {$processingMode}");

        // Look for consent-related fields in the response
        $consentFields = [];
        foreach (['consent_management', 'opt_in', 'opt_out', 'consent'] as $field) {
            if (isset($app[$field])) {
                $consentFields[$field] = $app[$field];
            }
        }

        if (count($consentFields) > 0) {
            $io->text('Consent-related fields found: ' . implode(', ', array_keys($consentFields)));
            if ($io->isVerbose()) {
                try {
                    $json = Json::encode($consentFields, JSON_PRETTY_PRINT);
                } catch (\JsonException $e) {
                    $json = 'Failed to encode consent fields to JSON: ' . $e->getMessage();
                }
                $io->text($json);
            }
        } else {
            $io->text('No consent-related fields found in app response.');
        }

        $detail = sprintf(
            'mode=%s, consent_fields=%s',
            $processingMode,
            count($consentFields) > 0 ? implode(',', array_keys($consentFields)) : 'none'
        );

        $status = self::STATUS_PASSED;
        if ($processingMode !== 'DISPATCH') {
            $status = self::STATUS_REFUTED;
            $detail .= ', expected_mode=DISPATCH';
        }

        return [
            'check' => 'App Config',
            'condition' => 'App is accessible and in DISPATCH mode',
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * Check 2: Discover which consent API endpoints exist
     *
     * @param ?string $phone Phone number to test per-number consent query
     * @return array<int, array{check: string, condition: string, status: string, detail: string}>
     */
    private function checkConsentApiDiscovery(
        SymfonyStyle $io,
        OutputInterface $output,
        AppConfigurationClient $apiClient,
        string $appId,
        ?string $phone
    ): array {
        $io->section('Check 2: Consent API Discovery (RC-1)');
        $results = [];

        // Test listOptOuts
        $io->text('Testing listOptOuts endpoint...');
        try {
            $optOuts = $apiClient->listOptOuts($appId);
            $count = count($optOuts);
            $io->text("listOptOuts returned {$count} entries.");

            $results[] = [
                'check' => 'List Opt-Outs',
                'condition' => 'RC-1: Consent API exists',
                'status' => self::STATUS_PASSED,
                'detail' => $count > 0
                    ? "{$count} opt-out entries returned"
                    : 'Endpoint reachable, but consent list is empty (0 opt-out entries)',
            ];
        } catch (ApiException $e) {
            $io->text('listOptOuts failed: ' . $e->getMessage());
            $this->writeExceptionChain($io, $output, $e);

            $results[] = [
                'check' => 'List Opt-Outs',
                'condition' => 'RC-1: Consent API exists',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => 'Endpoint returned error: ' . $this->messageWithCause($e),
            ];
        }

        // Test getConsentStatus per-number (if phone provided)
        if (is_string($phone) && $phone !== '') {
            $io->text("Testing getConsentStatus for {$phone}...");
            try {
                $consent = $apiClient->getConsentStatus($appId, $phone);
                $hasData = count($consent) > 0;
                $io->text($hasData ? 'Consent data returned.' : 'No consent data (empty response).');

                if ($hasData && $io->isVerbose()) {
                    try {
                        $io->text(Json::encode($consent, JSON_PRETTY_PRINT));
                    } catch (\JsonException $e) {
                        $io->warning('Failed to JSON-encode consent payload: ' . $e->getMessage());
                    }
                }

                $results[] = [
                    'check' => 'Per-Number Consent',
                    'condition' => 'RC-1: Per-number consent queryable',
                    'status' => self::STATUS_PASSED,
                    'detail' => $hasData
                        ? 'Number ' . $phone . ' is opted out'
                        : 'Number ' . $phone . ' is not opted out (not in consent list)',
                ];
            } catch (ApiException $e) {
                $io->text('getConsentStatus failed: ' . $e->getMessage());
                $this->writeExceptionChain($io, $output, $e);
                $results[] = [
                    'check' => 'Per-Number Consent',
                    'condition' => 'RC-1: Per-number consent queryable',
                    'status' => self::STATUS_INCONCLUSIVE,
                    'detail' => 'Endpoint returned error: ' . $this->messageWithCause($e),
                ];
            }
        } else {
            $results[] = [
                'check' => 'Per-Number Consent',
                'condition' => 'RC-1: Per-number consent queryable',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => 'Skipped: --phone not provided',
            ];
        }

        return $results;
    }

    /**
     * Check 3: Attempt to send a message and observe behavior
     *
     * @return array{check: string, condition: string, status: string, detail: string}
     */
    private function checkSendToOptedOut(
        SymfonyStyle $io,
        OutputInterface $output,
        AppConfigurationClient $apiClient,
        string $phone
    ): array {
        $io->section('Check 3: Send to Opted-Out Number (RC-5)');
        $io->text("Sending test message to {$phone}...");

        try {
            $result = $apiClient->sendMessageByChannelIdentity(
                $phone,
                'ADR-0001 consent refutation test. Please disregard.'
            );

            $messageId = $result['id'] ?? $result['message_id'] ?? 'unknown';
            $io->text("Message accepted by API. Message ID: {$messageId}");
            $io->text('Check delivery status in Sinch dashboard to determine if it was blocked.');

            return [
                'check' => 'Send to Opted-Out',
                'condition' => 'RC-5: Platform blocks opted-out sends',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => "Message accepted (id={$messageId}). Check dashboard for delivery status.",
            ];
        } catch (ApiException $e) {
            $fullMessage = $this->messageWithCause($e);
            // Check the full exception chain for consent/opt-out signals.
            // Use specific terms to avoid false positives from words like
            // "optional", "optimistic", "non-blocking", etc.
            $lower = strtolower($fullMessage);
            $isConsentBlock = str_contains($lower, 'consent')
                || str_contains($lower, 'opt-out')
                || str_contains($lower, 'opt_out')
                || str_contains($lower, 'opted out')
                || str_contains($lower, 'blocked');

            if ($isConsentBlock) {
                $io->text('API rejected message (likely consent block): ' . $e->getMessage());
                $this->writeExceptionChain($io, $output, $e);
                return [
                    'check' => 'Send to Opted-Out',
                    'condition' => 'RC-5: Platform blocks opted-out sends',
                    'status' => self::STATUS_PASSED,
                    'detail' => 'API rejected with consent-related error',
                ];
            }

            $io->text('API rejected message: ' . $e->getMessage());
            $this->writeExceptionChain($io, $output, $e);
            return [
                'check' => 'Send to Opted-Out',
                'condition' => 'RC-5: Platform blocks opted-out sends',
                'status' => self::STATUS_INCONCLUSIVE,
                'detail' => 'API error (not clearly consent-related): ' . $fullMessage,
            ];
        }
    }

    /**
     * Build configuration from CLI options and env vars
     *
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
