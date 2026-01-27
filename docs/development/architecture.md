# Module Architecture

OpenEMR modules follow a **Symfony-inspired MVC architecture** with:
- **Controllers** in `src/Module/Controller/` handling business logic
- **Twig templates** in `templates/` for all HTML rendering
- **Services** in `src/Module/Service/` for business operations
- **Minimal public entry points** in `public/` that bootstrap and dispatch

## File Structure Convention

```
oce-module-sinch-conversations/
├── public/
│   ├── index.php          # Main entry point (25-35 lines)
│   ├── conversation.php   # Conversation thread view
│   ├── settings.php       # Settings page
│   └── assets/            # Static assets (CSS, JS, images)
├── src/
│   ├── Module/            # Main module code
│   │   ├── Bootstrap.php      # Module initialization and DI
│   │   ├── Controller/        # Request handlers
│   │   │   ├── InboxController.php
│   │   │   ├── ConversationController.php
│   │   │   └── SettingsController.php
│   │   ├── Command/           # CLI commands
│   │   │   ├── AppListCommand.php
│   │   │   ├── InspectCommand.php
│   │   │   ├── WebhookCreateCommand.php
│   │   │   └── WebhookListCommand.php
│   │   ├── Service/           # Business logic
│   │   │   ├── ConfigService.php
│   │   │   ├── MessageService.php
│   │   │   ├── ConsentService.php
│   │   │   └── ...
│   │   ├── GlobalConfig.php
│   │   └── GlobalsAccessor.php
│   └── Sinch/Conversation/    # Sinch API abstraction
│       ├── Client/            # API clients
│       ├── Config/            # Configuration
│       └── Exception/         # Custom exceptions
├── templates/
│   ├── conversation/
│   ├── inbox/
│   └── settings/
└── composer.json
```

## Namespace Structure

This module uses a dual namespace structure:

- `OpenCoreEMR\Modules\SinchConversations\` - Module-specific code (controllers, services, bootstrap)
- `OpenCoreEMR\Sinch\Conversation\` - Sinch API abstraction layer (clients, exceptions, config)

## Public Entry Point Pattern

Public PHP files should be short! Just dispatch a controller and send a response. Follow this pattern:

```php
<?php
/**
 * [Description of endpoint]
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    [Author Name] <email@example.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->getInboxController();

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? 'default';

// Dispatch to controller and send response
$response = $controller->dispatch($action);
$response->send();
```

## Bootstrap Pattern

The `Bootstrap.php` class should provide factory methods for controllers:

```php
<?php

namespace OpenCoreEMR\Modules\SinchConversations;

use OpenCoreEMR\Modules\SinchConversations\Controller\InboxController;
use OpenCoreEMR\Modules\SinchConversations\Service\MessageService;
use OpenEMR\Core\Kernel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-sinch-conversations";

    private readonly GlobalConfig $globalsConfig;
    private readonly \Twig\Environment $twig;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Kernel $kernel = new Kernel(),
        private readonly GlobalsAccessor $globals = new GlobalsAccessor()
    ) {
        $this->globalsConfig = new GlobalConfig($this->globals);

        $templatePath = \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
        $twig = new TwigContainer($templatePath, $this->kernel);
        $this->twig = $twig->getTwig();
    }

    /**
     * Get InboxController instance
     */
    public function getInboxController(): InboxController
    {
        return new InboxController(
            $this->globalsConfig,
            new MessageService($this->globalsConfig),
            $this->twig
        );
    }
}
```

## CLI Commands

This module includes CLI commands for automation and debugging:

| Command | Description |
|---------|-------------|
| `sinch:app:list` | List Sinch Conversation API apps |
| `sinch:webhook:list` | List configured webhooks |
| `sinch:webhook:create` | Create a new webhook |
| `sinch:inspect` | Inspect current configuration |

Run commands via OpenEMR's CLI or directly:

```bash
# Via OpenEMR CLI (recommended)
php bin/console sinch:app:list

# Direct execution (for debugging)
php src/Module/Command/AppListCommand.php
```
