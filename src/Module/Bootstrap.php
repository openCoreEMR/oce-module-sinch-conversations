<?php

/**
 * Initializes the OpenCoreEmr Sinch Conversations Module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-sinch-conversations";

    private readonly GlobalConfig $globalsConfig;
    private readonly SessionAccessor $session;
    private readonly \Twig\Environment $twig;
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Kernel $kernel = new Kernel(),
        private readonly GlobalsAccessor $globals = new GlobalsAccessor()
    ) {
        $this->globalsConfig = new GlobalConfig($this->globals);
        $this->session = new SessionAccessor();

        $templatePath = \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
        $twig = new TwigContainer($templatePath, $this->kernel);
        $this->twig = $twig->getTwig();

        $this->logger = new SystemLogger();
        $this->logger->debug('Sinch Conversations Bootstrap constructed');
    }

    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();
        $this->addMenuItems();

        if (!$this->globalsConfig->isEnabled()) {
            $this->logger->debug('Sinch Conversations is disabled. Skipping additional event subscriptions.');
            return;
        }

        $this->logger->debug('Sinch Conversations module is enabled');
    }

    private function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            function (GlobalsInitializedEvent $event): void {
                $event->getGlobalsService()->createSection(
                    'OpenCoreEMR Sinch Conversations'
                );

                $setting = new GlobalSetting(
                    xlt('Enable OpenCoreEMR Sinch Conversations Module'),
                    'bool',
                    '0',
                    xlt('Enable or disable the OpenCoreEMR Sinch Conversations integration for patient messaging')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_ENABLED,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch Project ID'),
                    'text',
                    '',
                    xlt('Your Sinch project ID from the Sinch dashboard')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_PROJECT_ID,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch App ID'),
                    'text',
                    '',
                    xlt('Your Sinch app ID for the Conversations API')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_APP_ID,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch API Key'),
                    'text',
                    '',
                    xlt('Your Sinch API key')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_API_KEY,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch API Secret'),
                    'encrypted',
                    '',
                    xlt('Your Sinch API secret (will be encrypted)')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_API_SECRET,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch API Region'),
                    'text',
                    'us',
                    xlt('Enter your Sinch API region (us or eu)')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_REGION,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Default Channel'),
                    'text',
                    'SMS',
                    xlt('Default messaging channel (SMS, WHATSAPP, or RCS)')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_DEFAULT_CHANNEL,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Clinic Name'),
                    'text',
                    '',
                    xlt('Clinic name to appear in messages (e.g., "Example Clinic")')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_CLINIC_NAME,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Clinic Phone'),
                    'text',
                    '',
                    xlt('Clinic phone number for message templates (e.g., "650-123-4567")')
                );
                $event->getGlobalsService()->appendToSection(
                    'OpenCoreEMR Sinch Conversations',
                    GlobalConfig::CONFIG_OPTION_CLINIC_PHONE,
                    $setting
                );
            }
        );
    }

    private function addMenuItems(): void
    {
        $this->eventDispatcher->addListener(
            MenuEvent::MENU_UPDATE,
            function (MenuEvent $event): void {
                $menu = $event->getMenu();

                $menuItem = new \stdClass();
                $menuItem->requirement = 0;
                $menuItem->target = 'mod';
                $menuItem->menu_id = 'oce_sinch_conversations';
                $menuItem->label = xl('OpenCoreEMR Sinch Conversations');
                $menuItem->url = '/interface/modules/custom_modules/' . self::MODULE_NAME . '/public/index.php';
                $menuItem->children = [];
                $menuItem->acl_req = [];
                $menuItem->global_req = [GlobalConfig::CONFIG_OPTION_ENABLED];

                // Add to the modules section
                foreach ($menu as $section) {
                    if ($section->menu_id === 'modimg') {
                        $section->children[] = $menuItem;
                        break;
                    }
                }

                $event->setMenu($menu);
            }
        );
    }

    /**
     * Get Conversation API Client instance
     */
    public function getConversationApiClient(): \OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient
    {
        return new \OpenCoreEMR\Sinch\Conversation\Client\ConversationApiClient($this->globalsConfig);
    }

    /**
     * Get Message Polling Service
     */
    public function getMessagePollingService(): Service\MessagePollingService
    {
        return new Service\MessagePollingService(
            $this->globalsConfig,
            $this->getConversationApiClient()
        );
    }

    /**
     * Get Message Service
     */
    public function getMessageService(): Service\MessageService
    {
        return new Service\MessageService(
            $this->globalsConfig,
            $this->getConversationApiClient()
        );
    }

    /**
     * Get Template Service
     */
    public function getTemplateService(): Service\TemplateService
    {
        return new Service\TemplateService($this->globalsConfig);
    }

    /**
     * Get Consent Service
     */
    public function getConsentService(): Service\ConsentService
    {
        return new Service\ConsentService(
            $this->globalsConfig,
            $this->getTemplateService(),
            $this->getMessageService()
        );
    }

    /**
     * Get Keyword Handler Service
     */
    public function getKeywordHandlerService(): Service\KeywordHandlerService
    {
        return new Service\KeywordHandlerService(
            $this->globalsConfig,
            $this->getConsentService(),
            $this->getTemplateService()
        );
    }

    /**
     * Get Inbox Controller
     */
    public function getInboxController(): Controller\InboxController
    {
        return new Controller\InboxController(
            $this->globalsConfig,
            $this->getMessagePollingService(),
            $this->session,
            $this->twig
        );
    }

    /**
     * Get Conversation Controller
     */
    public function getConversationController(): Controller\ConversationController
    {
        return new Controller\ConversationController(
            $this->globalsConfig,
            $this->getMessagePollingService(),
            $this->getMessageService(),
            $this->session,
            $this->twig
        );
    }

    /**
     * Get Config Service
     */
    public function getConfigService(): Service\ConfigService
    {
        return new Service\ConfigService($this->globalsConfig);
    }

    /**
     * Get Template Sync Service
     */
    public function getTemplateSyncService(): Service\TemplateSyncService
    {
        return new Service\TemplateSyncService(
            $this->globalsConfig,
            $this->getConversationApiClient()
        );
    }

    /**
     * Get Settings Controller
     */
    public function getSettingsController(): Controller\SettingsController
    {
        return new Controller\SettingsController(
            $this->globalsConfig,
            $this->getConfigService(),
            $this->getConversationApiClient(),
            $this->getTemplateSyncService(),
            $this->session,
            $this->twig
        );
    }
}
