<?php

/**
 * Unit tests for EnvironmentConfigAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\EnvironmentConfigAccessor;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use PHPUnit\Framework\TestCase;

class EnvironmentConfigAccessorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        // Save and clear env vars
        $envVars = [
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

        foreach ($envVars as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        // Restore original env vars
        foreach ($this->originalEnv as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("{$var}={$value}");
            }
        }
    }

    public function testGetStringFromEnv(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_PROJECT_ID=test-project');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals('test-project', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testGetStringReturnsDefaultWhenNotSet(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals('default', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID, 'default'));
    }

    public function testGetBooleanFromEnv(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_ENABLED=1');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue($accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED));
    }

    public function testGetBooleanReturnsFalseWhenNotSet(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertFalse($accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED));
    }

    public function testGetIntFromEnv(): void
    {
        // No int config options currently, but test the method via get()
        putenv('OCE_SINCH_CONVERSATIONS_PROJECT_ID=42');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals(42, $accessor->getInt(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testHasReturnsTrueWhenSet(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_APP_ID=app-123');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue($accessor->has(GlobalConfig::CONFIG_OPTION_APP_ID));
    }

    public function testHasReturnsFalseWhenNotSet(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertFalse($accessor->has(GlobalConfig::CONFIG_OPTION_APP_ID));
    }

    public function testGetDelegatesToGlobalsForNonModuleKeys(): void
    {
        $GLOBALS['webroot'] = '/openemr';

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals('/openemr', $accessor->getString('webroot'));

        unset($GLOBALS['webroot']);
    }

    public function testHasDelegatesToGlobalsForNonModuleKeys(): void
    {
        $GLOBALS['test_global_key'] = 'value';

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue($accessor->has('test_global_key'));

        unset($GLOBALS['test_global_key']);
    }

    public function testGetDelegatesToGlobalsForNonModuleKeys2(): void
    {
        $GLOBALS['site_addr_oath'] = 'https://example.com';

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals('https://example.com', $accessor->get('site_addr_oath'));

        unset($GLOBALS['site_addr_oath']);
    }

    public function testMultipleEnvVarsReadCorrectly(): void
    {
        putenv('OCE_SINCH_CONVERSATIONS_PROJECT_ID=proj-1');
        putenv('OCE_SINCH_CONVERSATIONS_APP_ID=app-1');
        putenv('OCE_SINCH_CONVERSATIONS_REGION=eu');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals('proj-1', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        $this->assertEquals('app-1', $accessor->getString(GlobalConfig::CONFIG_OPTION_APP_ID));
        $this->assertEquals('eu', $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION));
    }
}
