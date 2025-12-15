#!/usr/bin/env php
<?php

/**
 * Sinch Configuration Inspector
 *
 * CLI tool to inspect Sinch Conversations API configuration
 *
 * Usage:
 *   php inspect.php
 *
 * Environment variables (optional):
 *   SINCH_PROJECT_ID
 *   SINCH_APP_ID
 *   SINCH_API_KEY
 *   SINCH_API_SECRET
 *   SINCH_REGION (us or eu)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Colors for terminal output
const COLOR_RESET = "\033[0m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_RED = "\033[31m";
const COLOR_BLUE = "\033[34m";
const COLOR_BOLD = "\033[1m";

function loadEnvFile(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if (!empty($key) && !getenv($key)) {
                putenv("{$key}={$value}");
            }
        }
    }
}

function getConfig(): array
{
    // Load .env file if it exists
    loadEnvFile(__DIR__ . '/.env');

    // Try environment variables first
    $config = [
        'project_id' => getenv('SINCH_PROJECT_ID'),
        'app_id' => getenv('SINCH_APP_ID'),
        'api_key' => getenv('SINCH_API_KEY'),
        'api_secret' => getenv('SINCH_API_SECRET'),
        'region' => getenv('SINCH_REGION') ?: 'us',
    ];

    // If not in env, try to load from OpenEMR globals
    if (empty($config['project_id'])) {
        $globalsFile = __DIR__ . '/../../../../globals.php';
        if (file_exists($globalsFile)) {
            require_once $globalsFile;

            global $GLOBALS;
            $config['project_id'] = $GLOBALS['sinch_project_id'] ?? '';
            $config['app_id'] = $GLOBALS['sinch_app_id'] ?? '';
            $config['api_key'] = $GLOBALS['sinch_api_key'] ?? '';
            $config['api_secret'] = $GLOBALS['sinch_api_secret'] ?? '';
            $config['region'] = $GLOBALS['sinch_region'] ?? 'us';
        }
    }

    return $config;
}

function getOAuthToken(array $config): string
{
    $authClient = new Client([
        'base_uri' => "https://{$config['region']}.auth.sinch.com",
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $response = $authClient->post('/oauth2/token', [
        'form_params' => [
            'grant_type' => 'client_credentials',
        ],
        'auth' => [$config['api_key'], $config['api_secret']],
    ]);

    $body = json_decode((string)$response->getBody(), true);

    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "OAuth2 failed: " . ($body['error_description'] ?? $body['error'] ?? 'Unknown error')
        );
    }

    return $body['access_token'];
}

function getApp(array $config, string $token): array
{
    $client = new Client([
        'base_uri' => 'https://us.conversation.api.sinch.com',
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $response = $client->get(
        "/v1/projects/{$config['project_id']}/apps/{$config['app_id']}",
        [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
        ]
    );

    $body = json_decode((string)$response->getBody(), true);

    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException("Failed to get app: " . ($body['error']['message'] ?? 'Unknown error'));
    }

    return $body;
}

function printHeader(string $text): void
{
    echo COLOR_BOLD . COLOR_BLUE . "\n" . str_repeat('=', 60) . "\n";
    echo $text . "\n";
    echo str_repeat('=', 60) . COLOR_RESET . "\n\n";
}

function printSection(string $title): void
{
    echo COLOR_BOLD . COLOR_GREEN . "\n{$title}:" . COLOR_RESET . "\n";
}

function printKeyValue(string $key, string $value, int $indent = 2): void
{
    $padding = str_repeat(' ', $indent);
    echo $padding . COLOR_YELLOW . $key . ": " . COLOR_RESET . $value . "\n";
}

function printWarning(string $message): void
{
    echo COLOR_RED . "⚠️  " . $message . COLOR_RESET . "\n";
}

function printSuccess(string $message): void
{
    echo COLOR_GREEN . "✓ " . $message . COLOR_RESET . "\n";
}

// Main execution
try {
    printHeader("Sinch Configuration Inspector");

    $config = getConfig();

    // Validate configuration
    $missing = [];
    foreach (['project_id', 'app_id', 'api_key', 'api_secret'] as $key) {
        if (empty($config[$key])) {
            $missing[] = strtoupper($key);
        }
    }

    if (!empty($missing)) {
        printWarning("Missing configuration: " . implode(', ', $missing));
        echo "\nSet environment variables:\n";
        foreach ($missing as $key) {
            echo "  export SINCH_{$key}=\"...\"\n";
        }
        exit(1);
    }

    printSection("Project Configuration");
    printKeyValue("Project ID", $config['project_id']);
    printKeyValue("App ID", $config['app_id']);
    printKeyValue("Region", $config['region']);

    // Get OAuth token
    echo "\nAuthenticating...";
    $token = getOAuthToken($config);
    printSuccess("Authenticated");

    // Get app configuration
    echo "Fetching app configuration...";
    $app = getApp($config, $token);
    printSuccess("Configuration retrieved");

    printSection("App Details");
    printKeyValue("Display Name", $app['display_name'] ?? 'N/A');
    printKeyValue("Conversation Metadata", $app['conversation_metadata_report_view'] ?? 'NONE');

    if (isset($app['dispatch_retention_policy'])) {
        printKeyValue("Retention Policy", $app['dispatch_retention_policy']['retention_type'] ?? 'N/A');
    }

    printSection("Channels");
    if (empty($app['channel_credentials'])) {
        printWarning("No channels configured!");
        echo "\nTo send messages, you need to configure at least one channel in your Sinch app.\n";
        echo "Visit: https://dashboard.sinch.com\n";
    } else {
        foreach ($app['channel_credentials'] as $channel) {
            $channelType = strtoupper($channel['channel'] ?? 'UNKNOWN');
            $state = $channel['state'] ?? 'UNKNOWN';

            echo "\n  " . COLOR_BOLD . $channelType . COLOR_RESET . "\n";
            printKeyValue("State", $state, 4);

            if (isset($channel['static_bearer'])) {
                printKeyValue("Bearer/Sender", $channel['static_bearer']['claimed_identity'] ?? 'N/A', 4);
            }

            if (isset($channel['static_token'])) {
                printKeyValue("Token", "Configured", 4);
            }

            if ($state !== 'ACTIVE') {
                printWarning("Channel is not ACTIVE - messages may fail");
            }
        }
    }

    echo "\n";
    printSuccess("Inspection complete");
    echo "\n";
} catch (\Throwable $e) {
    echo COLOR_RED . "\nError: " . $e->getMessage() . COLOR_RESET . "\n";
    if (getenv('DEBUG')) {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
