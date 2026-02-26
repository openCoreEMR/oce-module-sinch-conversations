<?php

/**
 * Unit tests for ConfigFactory
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\ConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\EnvironmentConfigAccessor;
use OpenCoreEMR\Modules\SinchConversations\FileConfigAccessor;
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;
use PHPUnit\Framework\TestCase;

class ConfigFactoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR);
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR);
        putenv(ConfigFactory::SECRETS_FILE_ENV_VAR);
        $this->tmpDir = sys_get_temp_dir() . '/config_factory_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR);
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR);
        putenv(ConfigFactory::SECRETS_FILE_ENV_VAR);
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // --- Env config mode tests ---

    public function testIsEnvConfigModeReturnsFalseByDefault(): void
    {
        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsTrueWhenSetTo1(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $this->assertTrue(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsTrueWhenSetToTrue(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=true');

        $this->assertTrue(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsFalseWhenSetTo0(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=0');

        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsFalseWhenSetToFalse(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=false');

        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    // --- File config mode tests ---

    public function testIsFileConfigModeReturnsFalseByDefault(): void
    {
        $this->assertFalse(ConfigFactory::isFileConfigMode());
    }

    public function testIsFileConfigModeReturnsTrueWhenConfigFileEnvVarPointsToExistingFile(): void
    {
        $configFile = $this->tmpDir . '/config.yaml';
        file_put_contents($configFile, "enabled: true\n");
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR . '=' . $configFile);

        $this->assertTrue(ConfigFactory::isFileConfigMode());
    }

    public function testIsFileConfigModeReturnsTrueWhenSecretsFileEnvVarPointsToExistingFile(): void
    {
        $secretsFile = $this->tmpDir . '/secrets.yaml';
        file_put_contents($secretsFile, "api_secret: x\n");
        putenv(ConfigFactory::SECRETS_FILE_ENV_VAR . '=' . $secretsFile);

        $this->assertTrue(ConfigFactory::isFileConfigMode());
    }

    public function testIsFileConfigModeReturnsFalseWhenEnvVarPointsToNonexistentFile(): void
    {
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR . '=/nonexistent/config.yaml');

        $this->assertFalse(ConfigFactory::isFileConfigMode());
    }

    // --- External config mode tests ---

    public function testIsExternalConfigModeReturnsFalseByDefault(): void
    {
        $this->assertFalse(ConfigFactory::isExternalConfigMode());
    }

    public function testIsExternalConfigModeReturnsTrueForEnvConfig(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $this->assertTrue(ConfigFactory::isExternalConfigMode());
    }

    public function testIsExternalConfigModeReturnsTrueForFileConfig(): void
    {
        $configFile = $this->tmpDir . '/config.yaml';
        file_put_contents($configFile, "enabled: true\n");
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR . '=' . $configFile);

        $this->assertTrue(ConfigFactory::isExternalConfigMode());
    }

    // --- createConfigAccessor tests ---

    public function testCreateConfigAccessorReturnsGlobalsAccessorByDefault(): void
    {
        $accessor = ConfigFactory::createConfigAccessor();

        $this->assertInstanceOf(GlobalsAccessor::class, $accessor);
    }

    public function testCreateConfigAccessorReturnsEnvironmentAccessorWhenEnvSet(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $accessor = ConfigFactory::createConfigAccessor();

        $this->assertInstanceOf(EnvironmentConfigAccessor::class, $accessor);
    }

    public function testCreateConfigAccessorReturnsFileAccessorWhenConfigFileExists(): void
    {
        $configFile = $this->tmpDir . '/config.yaml';
        file_put_contents($configFile, "enabled: true\nproject_id: from-yaml\n");
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR . '=' . $configFile);

        $accessor = ConfigFactory::createConfigAccessor();

        $this->assertInstanceOf(FileConfigAccessor::class, $accessor);
        $this->assertSame('from-yaml', $accessor->getString('oce_sinch_conversations_project_id'));
    }

    public function testFileConfigTakesPrecedenceOverEnvConfig(): void
    {
        // Set up both file and env config
        $configFile = $this->tmpDir . '/config.yaml';
        file_put_contents($configFile, "enabled: true\n");
        putenv(ConfigFactory::CONFIG_FILE_ENV_VAR . '=' . $configFile);
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $accessor = ConfigFactory::createConfigAccessor();

        // File config wins
        $this->assertInstanceOf(FileConfigAccessor::class, $accessor);
    }

    // --- Constants ---

    public function testEnvConfigVarConstant(): void
    {
        $this->assertSame('OCE_SINCH_CONVERSATIONS_ENV_CONFIG', ConfigFactory::ENV_CONFIG_VAR);
    }

    public function testConfigFileEnvVarConstant(): void
    {
        $this->assertSame('OCE_SINCH_CONVERSATIONS_CONFIG_FILE', ConfigFactory::CONFIG_FILE_ENV_VAR);
    }

    public function testSecretsFileEnvVarConstant(): void
    {
        $this->assertSame('OCE_SINCH_CONVERSATIONS_SECRETS_FILE', ConfigFactory::SECRETS_FILE_ENV_VAR);
    }

    public function testConventionalConfigPathConstant(): void
    {
        $this->assertSame('/etc/oce/sinch-conversations/config.yaml', ConfigFactory::CONVENTIONAL_CONFIG_PATH);
    }

    public function testConventionalSecretsPathConstant(): void
    {
        $this->assertSame('/etc/oce/sinch-conversations/secrets.yaml', ConfigFactory::CONVENTIONAL_SECRETS_PATH);
    }
}
