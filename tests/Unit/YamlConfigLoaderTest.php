<?php

/**
 * Unit tests for YamlConfigLoader
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\YamlConfigLoader;
use OpenCoreEMR\Sinch\Conversation\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

class YamlConfigLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/yaml_loader_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    private function writeYaml(string $filename, string $content): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    // --- load() tests ---

    public function testLoadSingleFile(): void
    {
        $path = $this->writeYaml('config.yaml', "enabled: true\nproject_id: abc\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertSame(true, $result['enabled']);
        $this->assertSame('abc', $result['project_id']);
    }

    public function testLoadMultipleFilesMerges(): void
    {
        $path1 = $this->writeYaml('config.yaml', "enabled: true\nproject_id: abc\n");
        $path2 = $this->writeYaml('secrets.yaml', "api_secret: secret123\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path1, $path2]);

        $this->assertSame(true, $result['enabled']);
        $this->assertSame('abc', $result['project_id']);
        $this->assertSame('secret123', $result['api_secret']);
    }

    public function testLaterFilesOverrideEarlierFiles(): void
    {
        $path1 = $this->writeYaml('first.yaml', "region: us\n");
        $path2 = $this->writeYaml('second.yaml', "region: eu\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path1, $path2]);

        $this->assertSame('eu', $result['region']);
    }

    public function testLoadEmptyFileReturnsEmptyArray(): void
    {
        $path = $this->writeYaml('empty.yaml', '');

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertSame([], $result);
    }

    // --- Imports ---

    public function testImportsAreProcessed(): void
    {
        $this->writeYaml('secrets.yaml', "api_secret: imported-secret\n");
        $path = $this->writeYaml('config.yaml', "imports:\n  - { resource: secrets.yaml }\nenabled: true\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertSame(true, $result['enabled']);
        $this->assertSame('imported-secret', $result['api_secret']);
        $this->assertArrayNotHasKey('imports', $result);
    }

    public function testParentKeysOverrideImportedKeys(): void
    {
        $this->writeYaml('base.yaml', "region: us\nproject_id: base-project\n");
        $path = $this->writeYaml('config.yaml', "imports:\n  - { resource: base.yaml }\nregion: eu\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertSame('eu', $result['region']);
        $this->assertSame('base-project', $result['project_id']);
    }

    public function testStringImportFormat(): void
    {
        $this->writeYaml('secrets.yaml', "api_secret: secret-value\n");
        $path = $this->writeYaml('config.yaml', "imports:\n  - secrets.yaml\nenabled: true\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertSame(true, $result['enabled']);
        $this->assertSame('secret-value', $result['api_secret']);
    }

    public function testImportsKeyIsRemovedFromResult(): void
    {
        $this->writeYaml('other.yaml', "key: value\n");
        $path = $this->writeYaml('config.yaml', "imports:\n  - { resource: other.yaml }\nenabled: true\n");

        $loader = new YamlConfigLoader();
        $result = $loader->load([$path]);

        $this->assertArrayNotHasKey('imports', $result);
    }

    // --- Error handling ---

    public function testThrowsOnUnreadableFile(): void
    {
        $path = $this->writeYaml('unreadable.yaml', "enabled: true\n");
        chmod($path, 0000);

        $loader = new YamlConfigLoader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not readable');

        try {
            $loader->load([$path]);
        } finally {
            chmod($path, 0644);
        }
    }

    public function testThrowsOnMalformedYaml(): void
    {
        $path = $this->writeYaml('bad.yaml', "enabled: true\n  invalid: indentation\n");

        $loader = new YamlConfigLoader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid YAML');
        $loader->load([$path]);
    }

    public function testThrowsOnNonMappingYaml(): void
    {
        $path = $this->writeYaml('scalar.yaml', "just a string\n");

        $loader = new YamlConfigLoader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must contain a YAML mapping');
        $loader->load([$path]);
    }

    // --- hasConfigFiles() ---

    public function testHasConfigFilesReturnsTrueWhenFileExists(): void
    {
        $path = $this->writeYaml('config.yaml', "enabled: true\n");

        $loader = new YamlConfigLoader();

        $this->assertTrue($loader->hasConfigFiles([$path]));
    }

    public function testHasConfigFilesReturnsFalseWhenNoFilesExist(): void
    {
        $loader = new YamlConfigLoader();

        $this->assertFalse($loader->hasConfigFiles(['/nonexistent/config.yaml']));
    }

    public function testHasConfigFilesReturnsTrueWhenAnyFileExists(): void
    {
        $path = $this->writeYaml('secrets.yaml', "api_secret: x\n");

        $loader = new YamlConfigLoader();

        $this->assertTrue($loader->hasConfigFiles(['/nonexistent/config.yaml', $path]));
    }

    // --- resolveFilePaths() ---

    public function testResolveFilePathsReturnsOnlyExistingPaths(): void
    {
        $existing = $this->writeYaml('config.yaml', "enabled: true\n");

        $loader = new YamlConfigLoader();
        $result = $loader->resolveFilePaths(['/nonexistent/path.yaml', $existing]);

        $this->assertSame([$existing], $result);
    }

    public function testResolveFilePathsReturnsEmptyWhenNoneExist(): void
    {
        $loader = new YamlConfigLoader();
        $result = $loader->resolveFilePaths(['/nonexistent/a.yaml', '/nonexistent/b.yaml']);

        $this->assertSame([], $result);
    }
}
