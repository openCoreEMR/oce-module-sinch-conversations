<?php

/**
 * Unit tests for Bootstrap
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\Controller\ConversationController;
use OpenCoreEMR\Modules\SinchConversations\Controller\InboxController;
use OpenCoreEMR\Modules\SinchConversations\Controller\SettingsController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class BootstrapTest extends TestCase
{
    private Bootstrap $bootstrap;
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        // Clear logs before each test
        SystemLogger::clearLogs();

        $this->eventDispatcher = new EventDispatcher();

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '1',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
            GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL => 'SMS',
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME => 'Test Clinic',
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE => '+15551234567',
        ]);

        $this->bootstrap = new Bootstrap($this->eventDispatcher, configAccessor: $mockGlobals);
    }

    protected function tearDown(): void
    {
        SystemLogger::clearLogs();
    }

    public function testBootstrapCanBeConstructed(): void
    {
        $this->assertInstanceOf(Bootstrap::class, $this->bootstrap);
    }

    public function testBootstrapLogsDebugMessageOnConstruction(): void
    {
        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter($logs, fn($log) => $log['level'] === 'debug');

        $this->assertNotEmpty($debugLogs);
        $constructLog = array_filter(
            $debugLogs,
            fn($log) => str_contains($log['message'], 'Sinch Conversations Bootstrap constructed')
        );
        $this->assertNotEmpty($constructLog);
    }

    public function testGetInboxControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getInboxController();

        $this->assertInstanceOf(InboxController::class, $controller);
    }

    public function testGetConversationControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getConversationController();

        $this->assertInstanceOf(ConversationController::class, $controller);
    }

    public function testGetSettingsControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getSettingsController();

        $this->assertInstanceOf(SettingsController::class, $controller);
    }

    public function testGetConversationApiClientReturnsClient(): void
    {
        $client = $this->bootstrap->getConversationApiClient();

        $this->assertInstanceOf(ConversationApiClient::class, $client);
    }

    public function testGetMessagePollingServiceReturnsService(): void
    {
        $service = $this->bootstrap->getMessagePollingService();

        $this->assertInstanceOf(MessagePollingService::class, $service);
    }

    public function testGetMessageServiceReturnsService(): void
    {
        $service = $this->bootstrap->getMessageService();

        $this->assertInstanceOf(MessageService::class, $service);
    }

    public function testGetTemplateServiceReturnsService(): void
    {
        $service = $this->bootstrap->getTemplateService();

        $this->assertInstanceOf(TemplateService::class, $service);
    }

    public function testGetConsentServiceReturnsService(): void
    {
        $service = $this->bootstrap->getConsentService();

        $this->assertInstanceOf(ConsentService::class, $service);
    }

    public function testGetKeywordHandlerServiceReturnsService(): void
    {
        $service = $this->bootstrap->getKeywordHandlerService();

        $this->assertInstanceOf(KeywordHandlerService::class, $service);
    }

    public function testGetConfigServiceReturnsService(): void
    {
        $service = $this->bootstrap->getConfigService();

        $this->assertInstanceOf(ConfigService::class, $service);
    }

    public function testGetTemplateSyncServiceReturnsService(): void
    {
        $service = $this->bootstrap->getTemplateSyncService();

        $this->assertInstanceOf(TemplateSyncService::class, $service);
    }

    public function testSubscribeToEventsCallsAddGlobalSettings(): void
    {
        $this->bootstrap->subscribeToEvents();

        // Verify that event listeners were added
        $listeners = $this->eventDispatcher->getListeners();
        $this->assertNotEmpty($listeners);
    }

    public function testSubscribeToEventsExitsEarlyWhenDisabled(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '0',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
        ]);

        $bootstrap = new Bootstrap($this->eventDispatcher, configAccessor: $mockGlobals);

        SystemLogger::clearLogs();
        $bootstrap->subscribeToEvents();

        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter(
            $logs,
            fn($log) => str_contains($log['message'], 'disabled')
        );

        $this->assertNotEmpty($debugLogs);
    }

    public function testSubscribeToEventsLogsEnabledWhenConfigured(): void
    {
        SystemLogger::clearLogs();
        $this->bootstrap->subscribeToEvents();

        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter(
            $logs,
            fn($log) => str_contains($log['message'], 'enabled')
        );

        $this->assertNotEmpty($debugLogs);
    }

    public function testModuleNameConstant(): void
    {
        $this->assertEquals('oce-module-sinch-conversations', Bootstrap::MODULE_NAME);
    }
}
