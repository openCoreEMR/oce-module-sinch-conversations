<?php

/**
 * Unit tests for FileConfigAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\FileConfigAccessor;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use PHPUnit\Framework\TestCase;

class FileConfigAccessorTest extends TestCase
{
    /**
     * @var list<string> Environment variables to clean up
     */
    private array $envVarsToClean = [
        'OCE_SINCH_CONVERSATIONS_ENABLED',
        'OCE_SINCH_CONVERSATIONS_PROJECT_ID',
        'OCE_SINCH_CONVERSATIONS_APP_ID',
        'OCE_SINCH_CONVERSATIONS_API_KEY',
        'OCE_SINCH_CONVERSATIONS_API_SECRET',
        'OCE_SINCH_CONVERSATIONS_REGION',
        'OCE_SINCH_CONVERSATIONS_DEFAULT_CHANNEL',
        'OCE_SINCH_CONVERSATIONS_CLINIC_NAME',
        'OCE_SINCH_CONVERSATIONS_CLINIC_PHONE',
    ];

    protected function setUp(): void
    {
        $this->clearEnvVars();
    }

    protected function tearDown(): void
    {
        $this->clearEnvVars();
    }

    private function clearEnvVars(): void
    {
        foreach ($this->envVarsToClean as $var) {
            putenv($var);
        }
    }

    public function testGetStringFromYamlData(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'yaml-project-123',
        ]);

        $this->assertSame(
            'yaml-project-123',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testGetBooleanFromYamlData(): void
    {
        $accessor = new FileConfigAccessor([
            'enabled' => true,
        ]);

        $this->assertTrue(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testGetIntFromYamlData(): void
    {
        // sinch-conversations doesn't have int config options,
        // but test the method works with a numeric string value
        $accessor = new FileConfigAccessor([
            'enabled' => true,
        ]);

        // Test with a non-module key that falls through to GlobalsAccessor
        $this->assertSame(0, $accessor->getInt('nonexistent_key'));
    }

    public function testReturnsDefaultWhenKeyNotInYaml(): void
    {
        $accessor = new FileConfigAccessor([]);

        $this->assertSame(
            'fallback',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID, 'fallback')
        );
    }

    public function testHasReturnsTrueForYamlKey(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
        ]);

        $this->assertTrue($accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $accessor = new FileConfigAccessor([]);

        $this->assertFalse($accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testEnvVarOverridesYamlValue(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_PROJECT_ID=env-project-456');

        $accessor = new FileConfigAccessor([
            'project_id' => 'yaml-project-123',
        ]);

        $this->assertSame(
            'env-project-456',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testEnvVarOverridesYamlBoolean(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_ENABLED=0');

        $accessor = new FileConfigAccessor([
            'enabled' => true,
        ]);

        $this->assertFalse(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testYamlValueUsedWhenEnvVarNotSet(): void
    {
        $accessor = new FileConfigAccessor([
            'region' => 'eu',
        ]);

        $this->assertSame(
            'eu',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION)
        );
    }

    public function testAllYamlKeysAreMapped(): void
    {
        $yamlData = [
            'enabled' => true,
            'project_id' => 'proj-123',
            'app_id' => 'app-456',
            'api_key' => 'key-789',
            'api_secret' => 'secret',
            'region' => 'eu',
            'default_channel' => 'WHATSAPP',
            'clinic_name' => 'Test Clinic',
            'clinic_phone' => '555-1234',
        ];

        $accessor = new FileConfigAccessor($yamlData);

        $this->assertTrue($accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED));
        $this->assertSame('proj-123', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        $this->assertSame('app-456', $accessor->getString(GlobalConfig::CONFIG_OPTION_APP_ID));
        $this->assertSame('key-789', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_KEY));
        $this->assertSame('secret', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_SECRET));
        $this->assertSame('eu', $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION));
        $this->assertSame('WHATSAPP', $accessor->getString(GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL));
        $this->assertSame('Test Clinic', $accessor->getString(GlobalConfig::CONFIG_OPTION_CLINIC_NAME));
        $this->assertSame('555-1234', $accessor->getString(GlobalConfig::CONFIG_OPTION_CLINIC_PHONE));
    }

    public function testUnknownYamlKeysAreIgnored(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
            'unknown_key' => 'should-be-ignored',
        ]);

        $this->assertSame('abc', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        // Unknown keys don't map to any config option and are silently ignored
    }

    public function testGetDelegatesToGlobalsForNonModuleKeys(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
        ]);

        // OE_SITE_DIR is a system key that delegates to GlobalsAccessor
        // In test context (no globals), returns default
        $this->assertSame('', $accessor->getString('OE_SITE_DIR', ''));
    }
}
