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
    private readonly \Twig\Environment $twig;
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Kernel $kernel = new Kernel(),
        private readonly GlobalsAccessor $globals = new GlobalsAccessor()
    ) {
        $this->globalsConfig = new GlobalConfig($this->globals);

        $templatePath = \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
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
                    'OpenCoreEMR Sinch Conversations Module',
                    'Conversations'
                );

                $setting = new GlobalSetting(
                    xlt('Enable Sinch Conversations Module'),
                    'bool',
                    '0',
                    xlt('Enable or disable the Sinch Conversations integration for patient messaging')
                );
                $event->getGlobalsService()->appendToSection(
                    'Conversations',
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
                    'Conversations',
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
                    'Conversations',
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
                    'Conversations',
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
                    'Conversations',
                    GlobalConfig::CONFIG_OPTION_API_SECRET,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Sinch API Region'),
                    'select',
                    'us',
                    xlt('Select your Sinch API region'),
                    [
                        'us' => xl('United States'),
                        'eu' => xl('Europe'),
                    ]
                );
                $event->getGlobalsService()->appendToSection(
                    'Conversations',
                    GlobalConfig::CONFIG_OPTION_REGION,
                    $setting
                );

                $setting = new GlobalSetting(
                    xlt('Default Channel'),
                    'select',
                    'SMS',
                    xlt('Default messaging channel'),
                    [
                        'SMS' => xl('SMS'),
                        'WHATSAPP' => xl('WhatsApp'),
                        'RCS' => xl('RCS'),
                    ]
                );
                $event->getGlobalsService()->appendToSection(
                    'Conversations',
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
                    'Conversations',
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
                    'Conversations',
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

                $menuItem = [
                    'url' => '/interface/modules/custom_modules/' . self::MODULE_NAME . '/public/index.php',
                    'label' => xl('Conversations'),
                    'requirement' => 0,
                    'global_req' => GlobalConfig::CONFIG_OPTION_ENABLED,
                ];

                $menu->addMenuItemToSection('modules', 'oce_sinch_conversations', $menuItem);
            }
        );
    }
}
