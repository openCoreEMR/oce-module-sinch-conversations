<?php

/**
 * Unit tests for ModuleAccessGuard
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\ModuleAccessGuard;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ModuleAccessGuardTest extends TestCase
{
    public function testReturnsNullWhenModuleActiveAndEnabled(): void
    {
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
        ]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => true
        );

        $this->assertNull($result);
    }

    public function testReturns404WhenModuleNotActiveInDatabase(): void
    {
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
        ]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => false
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $result->getStatusCode());
        $this->assertEquals('', $result->getContent());
    }

    public function testReturns404WhenModuleNotEnabled(): void
    {
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => false,
        ]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => true
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $result->getStatusCode());
    }

    public function testReturns404WhenModuleEnabledSettingMissing(): void
    {
        $configAccessor = new MockGlobalsAccessor([]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => true
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $result->getStatusCode());
    }

    public function testReturns404WhenBothConditionsFail(): void
    {
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => false,
        ]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => false
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $result->getStatusCode());
    }

    public function testModuleDirectoryPassedToChecker(): void
    {
        $receivedDirectory = null;
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
        ]);

        ModuleAccessGuard::check(
            'test-module-name',
            $configAccessor,
            function ($directory) use (&$receivedDirectory) {
                $receivedDirectory = $directory;
                return true;
            }
        );

        $this->assertEquals('test-module-name', $receivedDirectory);
    }

    public function testResponseContentType(): void
    {
        $configAccessor = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => false,
        ]);

        $result = ModuleAccessGuard::check(
            'oce-module-sinch-conversations',
            $configAccessor,
            fn() => true
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('', $result->getContent());
    }
}
