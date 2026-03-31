<?php

/**
 * Unit tests for ConfigService
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Exception\ValidationException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    private ConfigService $service;

    protected function setUp(): void
    {
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $config = new GlobalConfig(new MockGlobalsAccessor([]), new MockConfigFactory());
        $this->service = new ConfigService($config);
    }

    // --- Validation ---

    public function testSaveSettingsRejectsInvalidRegion(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Region must be 'us' or 'eu'");

        $this->service->saveSettings(['region' => 'invalid']);
    }

    public function testSaveSettingsRejectsInvalidChannel(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Default channel must be");

        $this->service->saveSettings(['default_channel' => 'TELEGRAM']);
    }

    public function testSaveSettingsRequiresProjectIdWhenApiKeysPresent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Project ID is required');

        $this->service->saveSettings([
            'project_id' => '',
            'app_id' => 'app-1',
            'api_key' => 'key-1',
        ]);
    }

    public function testSaveSettingsRequiresAppIdWhenApiKeysPresent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('App ID is required');

        $this->service->saveSettings([
            'project_id' => 'proj-1',
            'app_id' => '',
            'api_key' => 'key-1',
        ]);
    }

    public function testSaveSettingsRequiresApiKeyWhenApiKeysPresent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('API Key is required');

        $this->service->saveSettings([
            'project_id' => 'proj-1',
            'app_id' => 'app-1',
            'api_key' => '',
        ]);
    }

    // --- Saving ---

    public function testSaveSettingsUpdatesGlobals(): void
    {
        $this->service->saveSettings([
            'project_id' => 'proj-1',
            'app_id' => 'app-1',
            'api_key' => 'key-1',
            'region' => 'us',
            'default_channel' => 'SMS',
            'clinic_name' => 'Test Clinic',
            'clinic_phone' => '+15551234567',
        ]);

        $queries = QueryUtils::getQueries();
        $upsertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO `globals`'));
        // Should have 7 upserts (project_id, app_id, api_key, region, channel, name, phone)
        $this->assertCount(7, $upsertQueries);
    }

    public function testSaveSettingsAcceptsValidRegions(): void
    {
        $this->service->saveSettings(['region' => 'us']);

        $queries = QueryUtils::getQueries();
        $this->assertNotEmpty($queries);

        QueryUtils::clearQueries();
        $this->service->saveSettings(['region' => 'eu']);

        $queries = QueryUtils::getQueries();
        $this->assertNotEmpty($queries);
    }

    public function testSaveSettingsAcceptsValidChannels(): void
    {
        foreach (['SMS', 'WHATSAPP', 'RCS'] as $channel) {
            QueryUtils::clearQueries();
            $this->service->saveSettings(['default_channel' => $channel]);

            $queries = QueryUtils::getQueries();
            $this->assertNotEmpty($queries, "Channel {$channel} should be accepted");
        }
    }

    public function testSaveSettingsSkipsUnsetFields(): void
    {
        $this->service->saveSettings(['clinic_name' => 'Test']);

        $queries = QueryUtils::getQueries();
        $upsertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO `globals`'));
        $this->assertCount(1, $upsertQueries);
    }
}
