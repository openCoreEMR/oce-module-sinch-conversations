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

    // --- Webhook config tests ---

    public function testGetWebhookUsername(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'webhook_user',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('webhook_user', $config->getWebhookUsername());
    }

    public function testGetWebhookUsernameDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('', $config->getWebhookUsername());
    }

    public function testIsWebhookAuthConfiguredReturnsTrueWhenBothSet(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'user',
            GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => 'hashed_pass',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertTrue($config->isWebhookAuthConfigured());
    }

    public function testIsWebhookAuthConfiguredReturnsFalseWhenUsernameEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => '',
            GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => 'hashed_pass',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isWebhookAuthConfigured());
    }

    public function testIsWebhookAuthConfiguredReturnsFalseWhenPasswordEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'user',
            GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => '',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isWebhookAuthConfigured());
    }

    public function testIsWebhookAuthConfiguredReturnsFalseWhenBothEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isWebhookAuthConfigured());
    }

    public function testVerifyWebhookAuthReturnsFalseWhenNotConfigured(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->verifyWebhookAuth('user', 'pass'));
    }

    public function testGetWebhookIpAllowlistEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals([], $config->getWebhookIpAllowlist());
    }

    public function testGetWebhookIpAllowlistCommaDelimited(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => '10.0.0.1,10.0.0.2,192.168.1.0/24',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals(['10.0.0.1', '10.0.0.2', '192.168.1.0/24'], $config->getWebhookIpAllowlist());
    }

    public function testGetWebhookIpAllowlistNewlineDelimited(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => "10.0.0.1\n10.0.0.2\n192.168.1.0/24",
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals(['10.0.0.1', '10.0.0.2', '192.168.1.0/24'], $config->getWebhookIpAllowlist());
    }

    public function testGetWebhookIpAllowlistTrimsWhitespace(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => " 10.0.0.1 , 10.0.0.2 ",
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals(['10.0.0.1', '10.0.0.2'], $config->getWebhookIpAllowlist());
    }

    public function testIsIpInAllowlistReturnsTrueWhenEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertTrue($config->isIpInAllowlist('1.2.3.4'));
    }

    public function testIsIpInAllowlistReturnsTrueForMatchingIp(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => '10.0.0.1,10.0.0.2',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertTrue($config->isIpInAllowlist('10.0.0.1'));
    }

    public function testIsIpInAllowlistReturnsFalseForNonMatchingIp(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => '10.0.0.1,10.0.0.2',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isIpInAllowlist('192.168.1.1'));
    }

    public function testIsIpInAllowlistSupportsCidr(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => '192.168.1.0/24',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertTrue($config->isIpInAllowlist('192.168.1.50'));
        $this->assertFalse($config->isIpInAllowlist('192.168.2.1'));
    }

    public function testVerifyWebhookPasswordReturnsFalseForEmptyStored(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => '',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->verifyWebhookPassword('anything'));
    }
}
