<?php

/**
 * Initializes the OpenCoreEmr Sinch Conversations Module
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenCoreEMR\ModuleConfig\ConfigAccessorInterface;
use OpenCoreEMR\ModuleConfig\ConfigFactory;
use OpenCoreEMR\ModuleConfig\GlobalsRegistrar;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusJsListener;
use OpenCoreEMR\Modules\SinchConversations\Listener\AppointmentSmsStatusListener;
use OpenCoreEMR\Modules\SinchConversations\Listener\PatientConsentListener;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;
use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-sinch-conversations";

    private readonly GlobalConfig $globalsConfig;
    private readonly ConfigFactory $configFactory;
    private readonly SessionAccessor $session;
    private readonly \Twig\Environment $twig;
    private readonly SystemLogger $logger;
    private ?EligibilityAlertRenderer $eligibilityAlertRenderer = null;
    private ?\Symfony\Component\HttpFoundation\Session\SessionInterface $csrfSession = null;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        ?Kernel $kernel = null,
        ?ConfigAccessorInterface $configAccessor = null,
        ?ConfigFactory $configFactory = null,
    ) {
        $descriptor = SinchModuleConfig::createConfigDescriptor();
        $this->configFactory = $configFactory ?? new ConfigFactory(
            $descriptor,
            OEGlobalsBag::getInstance()
        );
        $accessor = $configAccessor ?? $this->configFactory->createConfigAccessor();
        $this->globalsConfig = new GlobalConfig($accessor, $this->configFactory);
        $this->session = new SessionAccessor();

        $templatePath = \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
        $twig = new TwigContainer($templatePath, $kernel);
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

        $this->subscribeToPatientConsentEvents();
        $this->subscribeToAppointmentRenderEvents();

        $this->logger->debug('Sinch Conversations module is enabled');
    }

    private function subscribeToPatientConsentEvents(): void
    {
        $listener = $this->getPatientConsentListener();
        $this->eventDispatcher->addListener(
            PatientCreatedEvent::EVENT_HANDLE,
            $listener->onPatientCreated(...)
        );
        $this->eventDispatcher->addListener(
            PatientUpdatedEvent::EVENT_HANDLE,
            $listener->onPatientUpdated(...)
        );
    }

    private function subscribeToAppointmentRenderEvents(): void
    {
        $listener = $this->getAppointmentSmsStatusListener();
        $this->eventDispatcher->addListener(
            AppointmentRenderEvent::RENDER_BELOW_PATIENT,
            $listener->onRenderBelowPatient(...)
        );

        $jsListener = $this->getAppointmentSmsStatusJsListener();
        $this->eventDispatcher->addListener(
            AppointmentRenderEvent::RENDER_JAVASCRIPT,
            $jsListener->onRenderJavascript(...)
        );
    }

    private function addGlobalSettings(): void
    {
        $registrar = new GlobalsRegistrar(OEGlobalsBag::getInstance());
        $registrar->register(
            $this->eventDispatcher,
            SinchModuleConfig::createGlobalsSectionDescriptor()
        );
    }

    private function addMenuItems(): void
    {
        $this->eventDispatcher->addListener(
            MenuEvent::MENU_UPDATE,
            function (MenuEvent $event): void {
                if (!$this->globalsConfig->isEnabled()) {
                    return;
                }

                $menu = $event->getMenu();

                $menuItem = new \stdClass();
                $menuItem->requirement = 0;
                $menuItem->target = 'mod';
                $menuItem->menu_id = 'oce_sinch_conversations';
                $menuItem->label = xl('OpenCoreEMR Sinch Conversations');
                $menuItem->url = '/interface/modules/custom_modules/' . self::MODULE_NAME . '/public/index.php';
                $menuItem->children = [];
                $menuItem->acl_req = [];

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
     * Get App Configuration Client instance
     */
    public function getAppConfigurationClient(): \OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient
    {
        return new \OpenCoreEMR\Sinch\Conversation\Client\AppConfigurationClient($this->globalsConfig);
    }

    /**
     * Get Webhook Provisioning Service
     */
    public function getWebhookProvisioningService(): Service\WebhookProvisioningService
    {
        return new Service\WebhookProvisioningService(
            $this->globalsConfig,
            $this->getAppConfigurationClient(),
            $this->getConfigService()
        );
    }

    /**
     * Get Message Polling Service
     */
    public function getMessagePollingService(): Service\MessagePollingService
    {
        return new Service\MessagePollingService(
            $this->globalsConfig,
            $this->getConversationApiClient(),
            $this->getKeywordHandlerService(),
            $this->getMessageService()
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
     * Get Patient Consent Listener
     */
    public function getPatientConsentListener(): PatientConsentListener
    {
        return new PatientConsentListener($this->getConsentService());
    }

    /**
     * Get Appointment SMS Status Listener
     */
    public function getAppointmentSmsStatusListener(): AppointmentSmsStatusListener
    {
        return new AppointmentSmsStatusListener(
            $this->getMessageService(),
            $this->getEligibilityAlertRenderer()
        );
    }

    /**
     * Get Appointment SMS Status JS Listener
     */
    public function getAppointmentSmsStatusJsListener(): AppointmentSmsStatusJsListener
    {
        return new AppointmentSmsStatusJsListener();
    }

    /**
     * Get Eligibility Alert Renderer (memoized — stateless and shared
     * between the listener and the controller).
     */
    public function getEligibilityAlertRenderer(): EligibilityAlertRenderer
    {
        return $this->eligibilityAlertRenderer ??= new EligibilityAlertRenderer();
    }

    /**
     * Get the request's active Symfony session for CSRF token operations.
     *
     * CsrfUtils on oce-810 requires a real SessionInterface (it reads
     * 'csrf_private_key' from the session), so resolve the active session
     * established by globals.php rather than the module's $_SESSION wrapper.
     * Memoized so every controller shares one session instance per request.
     */
    public function getCsrfSession(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->csrfSession ??= SessionWrapperFactory::getInstance()->getActiveSession();
    }

    /**
     * Get Eligibility Controller
     */
    public function getEligibilityController(): Controller\EligibilityController
    {
        return new Controller\EligibilityController(
            $this->getMessageService(),
            $this->getEligibilityAlertRenderer(),
            $this->logger
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
            $this->getCsrfSession(),
            $this->twig,
            $this->logger
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
            $this->getCsrfSession(),
            $this->twig,
            $this->logger
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
     * Get Consent Sync Service
     */
    public function getConsentSyncService(): Service\ConsentSyncService
    {
        return new Service\ConsentSyncService(
            $this->globalsConfig,
            $this->getConversationApiClient(),
            $this->getConsentService()
        );
    }

    /**
     * Get Webhook Controller
     */
    public function getWebhookController(): Controller\WebhookController
    {
        return new Controller\WebhookController(
            $this->globalsConfig,
            $this->getKeywordHandlerService(),
            $this->getMessageService(),
            $this->getConsentService()
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
            $this->getWebhookProvisioningService(),
            $this->session,
            $this->getCsrfSession(),
            $this->twig,
            $this->logger
        );
    }

    /**
     * Get Appointment Reminder Service
     */
    public function getAppointmentReminderService(): Service\AppointmentReminderService
    {
        return new Service\AppointmentReminderService(
            $this->globalsConfig,
            $this->getTemplateService(),
            $this->getMessageService(),
            $this->getUpcomingAppointmentFinder()
        );
    }

    /**
     * Get Upcoming Appointment Finder
     *
     * Production wraps core OpenEMR's recurrence-aware fetchAllEvents().
     */
    public function getUpcomingAppointmentFinder(): Service\UpcomingAppointmentFinder
    {
        return new Service\CoreAppointmentFinder();
    }
}
