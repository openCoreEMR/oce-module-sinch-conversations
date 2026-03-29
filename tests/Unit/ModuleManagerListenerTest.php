<?php

/**
 * Unit tests for ModuleManagerListener
 *
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc.
 * @link      https://www.opencoreemr.com
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenEMR\Common\Database\QueryUtils;
use PHPUnit\Framework\TestCase;

class ModuleManagerListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../ModuleManagerListener.php';
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
    }

    public function testGetModuleNamespace(): void
    {
        $this->assertSame(
            'OpenCoreEMR\\Modules\\SinchConversations\\',
            \ModuleManagerListener::getModuleNamespace()
        );
    }

    public function testGetModuleSourcePath(): void
    {
        $this->assertSame(
            '/src/Module',
            \ModuleManagerListener::getModuleSourcePath()
        );
    }

    public function testInitListenerSelfReturnsInstance(): void
    {
        $instance = \ModuleManagerListener::initListenerSelf();
        $this->assertInstanceOf(\ModuleManagerListener::class, $instance);
    }

    public function testInstallRegistersBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('install', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('INSERT INTO `background_services`', $queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]['sql']);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][0]);
    }

    public function testEnableRegistersAndActivatesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('enable', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(2, $queries);

        // First: upsert to ensure the row exists
        $this->assertStringContainsString('INSERT INTO `background_services`', $queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $queries[0]['sql']);

        // Second: activate
        $this->assertStringContainsString('UPDATE `background_services` SET `active` = ?', $queries[1]['sql']);
        $this->assertSame(1, $queries[1]['binds'][0]);
        $this->assertSame('oce_sinch_reminders', $queries[1]['binds'][1]);
    }

    public function testDisableDeactivatesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('disable', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('UPDATE `background_services` SET `active` = ?', $queries[0]['sql']);
        $this->assertSame(0, $queries[0]['binds'][0]);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][1]);
    }

    public function testUnregisterDeletesBackgroundService(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('unregister', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('DELETE FROM `background_services`', $queries[0]['sql']);
        $this->assertSame('oce_sinch_reminders', $queries[0]['binds'][0]);
    }

    public function testDatabaseFailureReturnsErrorWithTraceableRef(): void
    {
        QueryUtils::setNextException(new \RuntimeException('connection lost'));

        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('install', 1, 'Success');

        $this->assertNotSame('Success', $result);
        $this->assertMatchesRegularExpression('/ref: [0-9a-f]{8}/', $result);
        $this->assertStringNotContainsString('connection lost', $result);
    }

    public function testUnknownActionReturnsCurrentStatus(): void
    {
        $listener = \ModuleManagerListener::initListenerSelf();
        $result = $listener->moduleManagerAction('nonexistent_action', 1, 'Success');

        $this->assertSame('Success', $result);

        $queries = QueryUtils::getQueries();
        $this->assertCount(0, $queries);
    }
}
