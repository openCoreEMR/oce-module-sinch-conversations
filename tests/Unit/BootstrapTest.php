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

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\Controller\ConversationController;
use OpenCoreEMR\Modules\SinchConversations\Controller\InboxController;
use OpenCoreEMR\Modules\SinchConversations\Controller\SettingsController;
use OpenCoreEMR\Modules\SinchConversations\Controller\EligibilityController;
use OpenCoreEMR\Modules\SinchConversations\GlobalConfig;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusJsListener;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusListener;
use OpenCoreEMR\Modules\SinchConversations\Listener\PatientConsentListener;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenCoreEMR\Modules\SinchConversations\Service\ConfigService;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentService;
use OpenCoreEMR\Modules\SinchConversations\Service\ConsentSyncService;
use OpenCoreEMR\Modules\SinchConversations\Service\KeywordHandlerService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessagePollingService;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateService;
use OpenCoreEMR\Modules\SinchConversations\Service\TemplateSyncService;
use OpenCoreEMR\Modules\SinchConversations\SinchModuleConfig;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockConfigFactory;
use OpenCoreEMR\Modules\SinchConversations\Tests\Mocks\MockGlobalsAccessor;
use OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Services\Globals\GlobalsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class BootstrapTest extends TestCase
{
    /** @var list<string> */
    private const EXTERNAL_CONFIG_ENV_VARS = [
        'OCE_SINCH_CONVERSATIONS_ENV_CONFIG',
        'OCE_SINCH_CONVERSATIONS_CONFIG_FILE',
        'OCE_SINCH_CONVERSATIONS_SECRETS_FILE',
    ];

    private Bootstrap $bootstrap;
    private EventDispatcher $eventDispatcher;
    /** @var array<string, string|false> Original env var values to restore in tearDown */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Clear logs before each test
        SystemLogger::clearLogs();

        // Clear external config env vars so they don't interfere with tests.
        // Note: Bootstrap now honors injected accessors regardless of external
        // config mode, but we still clear these for clean test isolation.
        foreach (self::EXTERNAL_CONFIG_ENV_VARS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }

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

        // Restore original env var values
        foreach ($this->savedEnv as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("$var=$value");
            }
        }
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

    public function testGetPatientConsentListenerReturnsListener(): void
    {
        $listener = $this->bootstrap->getPatientConsentListener();

        $this->assertInstanceOf(PatientConsentListener::class, $listener);
    }

    public function testGetAppointmentSmsStatusListenerReturnsListener(): void
    {
        $listener = $this->bootstrap->getAppointmentSmsStatusListener();

        $this->assertInstanceOf(AppointmentSmsStatusListener::class, $listener);
    }

    public function testGetAppointmentSmsStatusJsListenerReturnsListener(): void
    {
        $listener = $this->bootstrap->getAppointmentSmsStatusJsListener();

        $this->assertInstanceOf(AppointmentSmsStatusJsListener::class, $listener);
    }

    public function testGetEligibilityAlertRendererReturnsMemoizedInstance(): void
    {
        $first = $this->bootstrap->getEligibilityAlertRenderer();
        $second = $this->bootstrap->getEligibilityAlertRenderer();

        $this->assertInstanceOf(EligibilityAlertRenderer::class, $first);
        $this->assertSame($first, $second);
    }

    public function testGetEligibilityControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getEligibilityController();

        $this->assertInstanceOf(EligibilityController::class, $controller);
    }

    public function testSubscribeToEventsRegistersPatientConsentListenersWhenEnabled(): void
    {
        $this->bootstrap->subscribeToEvents();

        $this->assertNotEmpty($this->eventDispatcher->getListeners(PatientCreatedEvent::EVENT_HANDLE));
        $this->assertNotEmpty($this->eventDispatcher->getListeners(PatientUpdatedEvent::EVENT_HANDLE));
    }

    public function testSubscribeToEventsRegistersAppointmentRenderListenerWhenEnabled(): void
    {
        // Pin the wiring so a typo'd event name or a future refactor that
        // drops subscribeToAppointmentRenderEvents() fails the build instead
        // of silently disabling the calendar SMS-eligibility badge.
        $this->bootstrap->subscribeToEvents();

        $this->assertNotEmpty(
            $this->eventDispatcher->getListeners(AppointmentRenderEvent::RENDER_BELOW_PATIENT)
        );
    }

    public function testSubscribeToEventsRegistersAppointmentRenderJsListenerWhenEnabled(): void
    {
        // The JS listener powers the live update on patient swap; without
        // it the badge would only refresh on a full form re-render.
        $this->bootstrap->subscribeToEvents();

        $this->assertNotEmpty(
            $this->eventDispatcher->getListeners(AppointmentRenderEvent::RENDER_JAVASCRIPT)
        );
    }

    public function testSubscribeToEventsDoesNotRegisterPatientConsentListenersWhenDisabled(): void
    {
        $disabledGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '0',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
        ]);
        $bootstrap = new Bootstrap($this->eventDispatcher, configAccessor: $disabledGlobals);

        $bootstrap->subscribeToEvents();

        $this->assertEmpty($this->eventDispatcher->getListeners(PatientCreatedEvent::EVENT_HANDLE));
        $this->assertEmpty($this->eventDispatcher->getListeners(PatientUpdatedEvent::EVENT_HANDLE));
    }

    public function testSubscribeToEventsDoesNotRegisterAppointmentRenderListenerWhenDisabled(): void
    {
        $disabledGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '0',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_APP_ID => 'test-app',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'us',
        ]);
        $bootstrap = new Bootstrap($this->eventDispatcher, configAccessor: $disabledGlobals);

        $bootstrap->subscribeToEvents();

        $this->assertEmpty(
            $this->eventDispatcher->getListeners(AppointmentRenderEvent::RENDER_BELOW_PATIENT)
        );
        $this->assertEmpty(
            $this->eventDispatcher->getListeners(AppointmentRenderEvent::RENDER_JAVASCRIPT)
        );
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

    public function testGetConsentSyncServiceReturnsService(): void
    {
        $service = $this->bootstrap->getConsentSyncService();

        $this->assertInstanceOf(ConsentSyncService::class, $service);
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

    // --- Menu visibility ---

    public function testMenuItemAddedWhenEnabled(): void
    {
        $this->bootstrap->subscribeToEvents();

        $modimg = new \stdClass();
        $modimg->menu_id = 'modimg';
        $modimg->children = [];

        $event = new MenuEvent([$modimg]);
        $this->eventDispatcher->dispatch($event, MenuEvent::MENU_UPDATE);

        $menu = $event->getMenu();
        $children = $menu[0]->children;
        $matchingChildren = array_filter(
            $children,
            static fn($child): bool => isset($child->menu_id)
                && $child->menu_id === 'oce_sinch_conversations',
        );
        $this->assertNotEmpty($matchingChildren, 'Expected oce_sinch_conversations menu item to be present');
    }

    public function testMenuItemNotAddedWhenDisabled(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '0',
        ]);

        $dispatcher = new EventDispatcher();
        $bootstrap = new Bootstrap($dispatcher, configAccessor: $mockGlobals);
        $bootstrap->subscribeToEvents();

        $modimg = new \stdClass();
        $modimg->menu_id = 'modimg';
        $modimg->children = [];

        $event = new MenuEvent([$modimg]);
        $dispatcher->dispatch($event, MenuEvent::MENU_UPDATE);

        $menu = $event->getMenu();
        $this->assertEmpty($menu[0]->children);
    }

    // --- Globals registration ---

    public function testGlobalsRegistersOnlyEnabledToggleAndSettingsLink(): void
    {
        $this->bootstrap->subscribeToEvents();

        $globalsService = new GlobalsService([], [], []);
        $event = new GlobalsInitializedEvent($globalsService);
        $this->eventDispatcher->dispatch($event, GlobalsInitializedEvent::EVENT_HANDLE);

        $metadata = $globalsService->getGlobalsMetadata();
        $section = $metadata['OpenCoreEMR Sinch Conversations'] ?? [];
        $keys = array_keys($section);

        $this->assertSame(
            [GlobalConfig::CONFIG_OPTION_ENABLED, GlobalConfig::CONFIG_OPTION_ENABLED . '_settings_link'],
            $keys,
            'Globals section should only contain the enabled toggle and settings link'
        );
    }

    public function testGlobalsDoesNotRegisterCredentialFields(): void
    {
        $this->bootstrap->subscribeToEvents();

        $globalsService = new GlobalsService([], [], []);
        $event = new GlobalsInitializedEvent($globalsService);
        $this->eventDispatcher->dispatch($event, GlobalsInitializedEvent::EVENT_HANDLE);

        $metadata = $globalsService->getGlobalsMetadata();
        $section = $metadata['OpenCoreEMR Sinch Conversations'] ?? [];
        $keys = array_keys($section);

        $forbiddenKeys = [
            GlobalConfig::CONFIG_OPTION_PROJECT_ID,
            GlobalConfig::CONFIG_OPTION_APP_ID,
            GlobalConfig::CONFIG_OPTION_API_KEY,
            GlobalConfig::CONFIG_OPTION_API_SECRET,
            GlobalConfig::CONFIG_OPTION_REGION,
            GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL,
            GlobalConfig::CONFIG_OPTION_CLINIC_NAME,
            GlobalConfig::CONFIG_OPTION_CLINIC_PHONE,
            GlobalConfig::CONFIG_OPTION_WEBHOOK_SECRET,
            GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST,
        ];

        foreach ($forbiddenKeys as $key) {
            $this->assertNotContains(
                $key,
                $keys,
                "Credential/config field '{$key}' should not be registered in globals"
            );
        }
    }

    public function testGlobalsSettingsLinkUsesHtmlDisplaySection(): void
    {
        $this->bootstrap->subscribeToEvents();

        $globalsService = new GlobalsService([], [], []);
        $event = new GlobalsInitializedEvent($globalsService);
        $this->eventDispatcher->dispatch($event, GlobalsInitializedEvent::EVENT_HANDLE);

        $metadata = $globalsService->getGlobalsMetadata();
        $section = $metadata['OpenCoreEMR Sinch Conversations'] ?? [];
        $linkEntry = $section[GlobalConfig::CONFIG_OPTION_ENABLED . '_settings_link'] ?? null;

        $this->assertNotNull($linkEntry, 'Settings link entry should exist');
        // GlobalSetting::format() returns [label, dataType, default, description, ?fieldOptions]
        $this->assertSame(
            GlobalSetting::DATA_TYPE_HTML_DISPLAY_SECTION,
            $linkEntry[1],
            'Settings link should use html_display_section type'
        );
    }
}
