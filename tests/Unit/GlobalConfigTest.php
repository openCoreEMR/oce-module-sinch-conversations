<?php

/**
 * Unit tests for GlobalConfig
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use PHPUnit\Framework\TestCase;

class GlobalConfigTest extends TestCase
{
    private MockGlobalsAccessor $mockGlobals;
    private GlobalConfig $config;

    protected function setUp(): void
    {
        $this->mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app-id',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-api-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-api-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
            GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'SMS',
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]);

        $this->config = new GlobalConfig($this->mockGlobals);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isEnabled());
    }

    public function testGetProjectId(): void
    {
        $this->assertEquals('test-project-id', $this->config->getProjectId());
    }

    public function testGetSinchProjectId(): void
    {
        $this->assertEquals('test-project-id', $this->config->getSinchProjectId());
    }

    public function testGetAppId(): void
    {
        $this->assertEquals('test-app-id', $this->config->getAppId());
    }

    public function testGetSinchAppId(): void
    {
        $this->assertEquals('test-app-id', $this->config->getSinchAppId());
    }

    public function testGetApiKey(): void
    {
        $this->assertEquals('test-api-key', $this->config->getApiKey());
    }

    public function testGetSinchApiKey(): void
    {
        $this->assertEquals('test-api-key', $this->config->getSinchApiKey());
    }

    public function testGetApiSecret(): void
    {
        $this->assertEquals('test-api-secret', $this->config->getApiSecret());
    }

    public function testGetApiSecretEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_API_SECRET => '',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('', $config->getApiSecret());
    }

    public function testGetSinchApiSecret(): void
    {
        $this->assertEquals('test-api-secret', $this->config->getSinchApiSecret());
    }

    public function testGetRegion(): void
    {
        $this->assertEquals('us', $this->config->getRegion());
    }

    public function testGetRegionDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('us', $config->getRegion());
    }

    public function testGetSinchRegion(): void
    {
        $this->assertEquals('us', $this->config->getSinchRegion());
    }

    public function testGetDefaultChannel(): void
    {
        $this->assertEquals('SMS', $this->config->getDefaultChannel());
    }

    public function testGetDefaultChannelDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('SMS', $config->getDefaultChannel());
    }

    public function testGetClinicName(): void
    {
        $this->assertEquals('Test Clinic', $this->config->getClinicName());
    }

    public function testGetClinicPhone(): void
    {
        $this->assertEquals('+15551234567', $this->config->getClinicPhone());
    }

    public function testGetApiBaseUrlUs(): void
    {
        $this->assertEquals('https://us.conversation.api.sinch.com', $this->config->getApiBaseUrl());
    }

    public function testGetApiBaseUrlEu(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_REGION => 'eu',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('https://eu.conversation.api.sinch.com', $config->getApiBaseUrl());
    }

    public function testGetApiBaseUrlUnknownRegion(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_REGION => 'unknown',
        ]);
        $config = new GlobalConfig($mockGlobals);

        // Should default to US
        $this->assertEquals('https://us.conversation.api.sinch.com', $config->getApiBaseUrl());
    }
}
