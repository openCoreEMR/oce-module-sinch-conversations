# OpenCoreEMR Module Development Guide for GitHub Copilot

This document describes the architectural patterns and conventions for **OpenCoreEMR modules**. These are **open source modules for OpenEMR** developed by OpenCoreEMR Inc., distinct from the OpenEMR community/foundation modules.

Use these patterns as context when providing code suggestions for **any module in the OpenCoreEMR organization**.

## Module Architecture Overview

OpenEMR modules follow a **Symfony-inspired MVC architecture** with:
- **Controllers** in `src/Controller/` handling business logic
- **Twig templates** in `templates/` for all HTML rendering
- **Services** in `src/Service/` for business operations
- **Minimal public entry points** in `public/` that bootstrap and dispatch

## File Structure Convention

```
oce-module-{name}/
├── public/
│   ├── index.php          # Main entry point (25-35 lines)
│   ├── {feature}.php      # Feature entry points (25-35 lines)
│   └── assets/            # Static assets (CSS, JS, images)
├── src/
│   ├── Bootstrap.php      # Module initialization and DI
│   ├── Controller/        # Request handlers
│   │   ├── {Feature}Controller.php
│   │   └── ...
│   ├── Service/           # Business logic
│   │   ├── {Feature}Service.php
│   │   └── ...
│   ├── Exception/         # Custom exception types
│   │   ├── {Module}ExceptionInterface.php
│   │   ├── {Module}Exception.php
│   │   └── {Specific}Exception.php
│   └── GlobalConfig.php   # Configuration wrapper
├── templates/
│   └── {feature}/
│       ├── {view}.html.twig
│       └── partials/
│           └── _{component}.html.twig
└── composer.json
```

## Public Entry Point Pattern

Public PHP files should be short! Just dispatch a controller and send a response. Follow this pattern:

```php
<?php
/**
 * [Description of endpoint]
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com/
 * @author    [Author Name] <email@example.com>
 * @copyright Copyright (c) [Year] OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\{ModuleName}\Bootstrap;
use OpenCoreEMR\Modules\{ModuleName}\GlobalsAccessor;
use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}ExceptionInterface;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\Response;

// Initialize logger
$logger = new SystemLogger();

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->get{Feature}Controller();

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? 'default';

// Dispatch to controller and send response
try {
    $response = $controller->dispatch($action);
    $response->send();
} catch ({Module}ExceptionInterface $e) {
    $logger->error("Module error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected error: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
```

## Controller Pattern

Controllers should:
- Be in `src/Controller/`
- Use **constructor dependency injection**
- Inject `Psr\Log\LoggerInterface` for logging (not `SystemLogger` directly)
- Use **Symfony Request objects** (never access $_GET, $_POST, $_SERVER directly)
- Return **Symfony Response objects** (never void)
- Have a `dispatch()` method that routes actions
- Throw **custom exceptions** (never die/exit)

```php
<?php

namespace OpenCoreEMR\Modules\{ModuleName}\Controller;

use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}AccessDeniedException;
use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}NotFoundException;
use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}ValidationException;
use OpenCoreEMR\Modules\{ModuleName}\GlobalConfig;
use OpenCoreEMR\Modules\{ModuleName}\Service\{Feature}Service;
use OpenEMR\Common\Csrf\CsrfUtils;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class {Feature}Controller
{
    public function __construct(
        private readonly GlobalConfig $config,
        private readonly {Feature}Service $service,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Dispatch action to appropriate method
     */
    public function dispatch(string $action): Response
    {
        $request = Request::createFromGlobals();

        return match ($action) {
            'create' => $this->handleCreate($request),
            'view' => $this->showView($request),
            'list' => $this->showList($request),
            default => $this->showList($request),
        };
    }

    private function showList(Request $request): Response
    {
        // Access query parameters
        $filter = $request->query->get('filter', '');

        // Business logic here

        $content = $this->twig->render('{feature}/list.html.twig', [
            'items' => $items,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        return new Response($content);
    }

    private function handleCreate(Request $request): Response
    {
        // Check HTTP method
        if (!$request->isMethod('POST')) {
            return new RedirectResponse($request->getPathInfo());
        }

        // Validate CSRF
        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            throw new {Module}AccessDeniedException("CSRF token verification failed");
        }

        // Access POST data with type casting
        $name = (string)$request->request->get('name', '');

        // Validate input
        if (empty($name)) {
            throw new {Module}ValidationException("Name is required");
        }

        // Process request
        try {
            $this->service->create(['name' => $name]);
            return new RedirectResponse($request->getPathInfo());
        } catch (\Exception $e) {
            $this->logger->error("Error creating item: " . $e->getMessage());
            throw new {Module}Exception("Error creating item: " . $e->getMessage());
        }
    }
}
```

## Exception Handling Pattern

### Define Custom Exception Hierarchy

All modules should have their own exception types in `src/Exception/`:

```php
<?php
// src/Exception/{Module}ExceptionInterface.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

interface {Module}ExceptionInterface extends \Throwable
{
    /**
     * Get the HTTP status code for this exception
     */
    public function getStatusCode(): int;
}
```

```php
<?php
// src/Exception/{Module}Exception.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

abstract class {Module}Exception extends \RuntimeException implements {Module}ExceptionInterface
{
    abstract public function getStatusCode(): int;
}
```

```php
<?php
// src/Exception/{Module}NotFoundException.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

class {Module}NotFoundException extends {Module}Exception
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
```

### Common Exception Types to Implement

- `{Module}NotFoundException` (404) - Resource not found
- `{Module}UnauthorizedException` (401) - User not authenticated
- `{Module}AccessDeniedException` (403) - CSRF failed, insufficient permissions
- `{Module}ValidationException` (400) - Invalid input data
- `{Module}ConfigurationException` (500) - Configuration errors

### Exception Handling in Public Files

```php
try {
    $response = $controller->dispatch($action);
    $response->send();
} catch (ExceptionInterface $e) {
    $logger->error("Sinch Conversations error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected error in Sinch Conversations: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
```

## Request/Response Handling - CRITICAL RULES

### ✅ ALWAYS DO:
- Use `Request::createFromGlobals()` in controller dispatch method
- Access request data via `$request->request->get()` (POST), `$request->query->get()` (GET), `$request->files->get()` (uploads)
- Use `$request->isMethod('POST')` instead of checking `$_SERVER['REQUEST_METHOD']`
- **Use `$_SESSION` directly for session access** (Symfony sessions not available yet)
- Cast request values: `(string)$request->request->get('field', '')`
- Controllers return `Response`, `JsonResponse`, `RedirectResponse`, or `BinaryFileResponse`
- Use Symfony HTTP Foundation components
- Call `$response->send()` in public entry points
- Use `Response` constants: `Response::HTTP_OK`, `Response::HTTP_NOT_FOUND`, etc.
- Throw exceptions with proper types (never with status codes in constructor)

### ❌ NEVER DO:
- ~~`$GLOBALS['kernel']`~~ → Use `$globalsAccessor->get('kernel')`
- ~~`$_GET['field']`~~ → Use `$request->query->get('field')`
- ~~`$_POST['field']`~~ → Use `$request->request->get('field')`
- ~~`$_FILES['file']`~~ → Use `$request->files->get('file')`
- ~~`$_SERVER['REQUEST_METHOD']`~~ → Use `$request->isMethod('POST')`
- ~~`$request->getSession()`~~ → **Use native `$_SESSION` instead** (Symfony sessions not available)
- ~~`header('Location: ...')`~~ → Use `RedirectResponse`
- ~~`http_response_code(404)`~~ → Use `new Response($content, 404)` or exceptions
- ~~`echo json_encode($data)`~~ → Use `JsonResponse`
- ~~`readfile($path)`~~ → Use `BinaryFileResponse`
- ~~`die()` or `exit`~~ → Throw exceptions
- ~~Controllers returning `void`~~ → Return `Response` objects

### Example: Correct Request/Response Handling

```php
private function handleForm(Request $request): Response
{
    // Check HTTP method
    if (!$request->isMethod('POST')) {
        return new RedirectResponse($request->getPathInfo());
    }

    // Get POST data
    $name = (string)$request->request->get('name', '');
    $email = (string)$request->request->get('email', '');

    // Get uploaded file
    $uploadedFile = $request->files->get('document');
    if ($uploadedFile && $uploadedFile->isValid()) {
        $filePath = $uploadedFile->getPathname();
    }

    // Get query parameters
    $filter = $request->query->get('filter');

    // Session access
    $userId = $request->getSession()->get('authUserID');

    // JSON Response
    return new JsonResponse(['status' => 'success'], Response::HTTP_OK);

    // Redirect
    return new RedirectResponse($request->getPathInfo());

    // File Download
    $response = new BinaryFileResponse($filePath);
    $response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        'filename.pdf'
    );
    return $response;

    // HTML Response
    $content = $this->twig->render('template.html.twig', $data);
    return new Response($content);
}
```

## Bootstrap Pattern

The `Bootstrap.php` class should provide factory methods for controllers:

```php
<?php

namespace OpenCoreEMR\Modules\{ModuleName};

use OpenCoreEMR\Modules\{ModuleName}\Controller\{Feature}Controller;
use OpenCoreEMR\Modules\{ModuleName}\Service\{Feature}Service;
use OpenEMR\Core\Kernel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-{name}";

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
     * Get {Feature}Controller instance
     */
    public function get{Feature}Controller(): {Feature}Controller
    {
        return new {Feature}Controller(
            $this->globalsConfig,
            new {Feature}Service($this->globalsConfig),
            $this->twig
        );
    }
}
```

## Twig Template Pattern

Templates should use OpenEMR's translation and sanitization filters:

```twig
{# templates/{feature}/view.html.twig #}

{% extends "base.html.twig" %}

{% block content %}
<div class="container">
    <h1>{{ 'Page Title'|xlt }}</h1>

    {% if error_message %}
        <div class="alert alert-danger">
            {{ error_message|text }}
        </div>
    {% endif %}

    <form method="post" action="{{ action_url|attr }}">
        <input type="hidden" name="csrf_token" value="{{ csrf_token|attr }}">

        <div class="form-group">
            <label>{{ 'Field Label'|xlt }}</label>
            <input type="text" name="field_name" class="form-control">
        </div>

        <button type="submit" class="btn btn-primary">
            {{ 'Submit'|xlt }}
        </button>
    </form>
</div>
{% endblock %}
```

### Twig Filter Reference
- `xlt` - Translate text
- `text` - Sanitize text for HTML output
- `attr` - Sanitize for HTML attributes
- `xlj` - Translate and JSON-encode for JavaScript

### Twig Global Variables (Available in All Templates)
- `{{ webroot }}` - OpenEMR web root path (e.g., "/openemr")
- `{{ assets_dir }}` - Static assets directory
- `{{ srcdir }}` - Source directory
- `{{ rootdir }}` - Root directory
- `{{ assetVersion }}` - Asset cache version
- `{{ session }}` - Session data

### Static Assets - CRITICAL RULES

**❌ NEVER use CDN links:**
- ~~`https://cdn.jsdelivr.net/npm/bootstrap@5.x/...`~~
- ~~`https://cdnjs.cloudflare.com/...`~~
- ~~`https://unpkg.com/...`~~
- ~~`https://maxcdn.bootstrapcdn.com/...`~~

**✅ ALWAYS use OpenEMR's built-in assets:**
```twig
{# Bootstrap CSS #}
<link rel="stylesheet" href="{{ webroot }}/public/assets/bootstrap/dist/css/bootstrap.min.css">

{# Bootstrap JS #}
<script src="{{ webroot }}/public/assets/bootstrap/dist/js/bootstrap.bundle.min.js"></script>

{# jQuery (if needed) #}
<script src="{{ webroot }}/public/assets/jquery/dist/jquery.min.js"></script>
```

**Why no CDNs?**
- Security: No external dependencies
- Privacy: No tracking or data leakage
- Reliability: Works offline and in air-gapped environments
- Consistency: Matches OpenEMR's Bootstrap version

### Twig Templates - Important Notes

**For Dialog/Iframe Templates:**
- Do NOT use `openemr_header_setup()` function (not available in module Twig environment)
- Parent window provides jQuery and OpenEMR assets
- Use minimal inline styles for dialog content
- Example:
```twig
<!DOCTYPE html>
<html>
<head>
    <title>{{ 'Dialog Title'|xlt }}</title>
    <style>
        body { padding: 15px; }
        .form-group { margin-bottom: 1rem; }
    </style>
</head>
<body>
    {# Dialog content #}
</body>
</html>
```

**For Tab/Main Content Templates:**
- Set `X-Frame-Options: SAMEORIGIN` header to allow loading in OpenEMR tabs
- Include necessary assets via links (Bootstrap, etc.)
- Templates render in iframe context with OpenEMR's tab system

## Code Quality Standards

All code must pass these checks:

```bash
pre-commit run -a
```

This runs:
- ✅ PHP Syntax Check
- ✅ PHP_CodeSniffer (PHPCS)
- ✅ PHPStan Static Analysis
- ✅ Rector
- ✅ Composer Require Checker

### Common Quality Issues to Avoid

**Line Length:**
- Maximum 120 characters per line
- Split long constructors across multiple lines

**Type Hints:**
- Add PHPDoc for array parameters: `@param array<string, mixed> $params`
- Use proper return types on all methods

**Unused Code:**
- Never suppress warnings with `@SuppressWarnings`
- If a parameter is unused, either use it or remove it
- Remove commented-out code

## Dependencies

Always include these in `composer.json`:

```json
{
  "require": {
    "php": ">=8.2",
    "symfony/event-dispatcher": "^6.0 || ^7.0",
    "symfony/http-foundation": "^6.0 || ^7.0",
    "twig/twig": "^3.0"
  }
}
```

## Composer Require Checker Configuration

Update `.composer-require-checker.json` to whitelist OpenEMR symbols:

```json
{
  "symbol-whitelist": [
    "OpenEMR\\Common\\Csrf\\CsrfUtils",
    "OpenEMR\\Common\\Database\\QueryUtils",
    "OpenEMR\\Common\\Logging\\SystemLogger",
    "OpenEMR\\Core\\Kernel",
    "RuntimeException",
    "session_start",
    "session_status",
    "PHP_SESSION_NONE",
    "sqlStatement",
    "sqlQuery",
    "xlt",
    "text",
    "attr"
  ],
  "php-core-extensions": [
    "Core",
    "standard",
    "curl",
    "json",
    "session",
    "SPL"
  ]
}
```

## Security Checklist

- ✅ Always validate CSRF tokens on POST requests
- ✅ Check user authentication before sensitive operations
- ✅ Use `realpath()` and path validation to prevent directory traversal
- ✅ Sanitize all user input in templates (`text`, `attr` filters)
- ✅ Log security events (failed auth, path traversal attempts)
- ✅ Never expose detailed error messages to users
- ✅ Use `SystemLogger` for all logging (never `error_log()` or `var_dump()`)

## Logging

**Always use dependency injection with `Psr\Log\LoggerInterface`:**

```php
use Psr\Log\LoggerInterface;

class MyController
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function someMethod(): void
    {
        $this->logger->error("Error message");
        $this->logger->warning("Warning message");
        $this->logger->info("Info message");
        $this->logger->debug("Debug message");
    }
}
```

**In Bootstrap/Factory classes, inject `SystemLogger`:**

```php
use OpenEMR\Common\Logging\SystemLogger;

class Bootstrap
{
    private readonly LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    public function getMyController(): MyController
    {
        return new MyController($this->logger);
    }
}
```

**In standalone scripts/public entry points:**

```php
use OpenEMR\Common\Logging\SystemLogger;

$logger = new SystemLogger();
$logger->error("Error occurred");
```

**Never use:**
- ❌ `error_log()` - Use `LoggerInterface` via dependency injection
- ❌ Instantiate `SystemLogger` in controllers/services - Inject `LoggerInterface` instead
- ❌ `var_dump()` or `print_r()` - Remove before committing
- ❌ `echo` for debugging - Use proper logging

**Why dependency injection?**
- ✅ Testable - Easy to mock logger in unit tests
- ✅ Flexible - Can swap implementations without changing code
- ✅ PSR-3 compliant - Works with any PSR-3 logger
- ✅ Best practice - Follows SOLID principles

## OpenEMR Integration Patterns

### Working with OpenEMR Tabs/Iframes

Modules load in OpenEMR's tab system which uses iframes. Key considerations:

**Redirects Must Use Full Script Path:**
```php
// CRITICAL: Use SCRIPT_NAME, not getPathInfo()
private function redirect(Request $request): RedirectResponse
{
    $queryParams = $request->query->all();
    unset($queryParams['action']); // Critical: prevents loop

    $queryString = http_build_query($queryParams);
    // Use actual script name - getPathInfo() may return '/' which causes redirect to login
    $scriptName = $request->server->get(
        'SCRIPT_NAME',
        '/interface/modules/custom_modules/oce-module-{name}/public/index.php'
    );
    $uri = $queryString ? $scriptName . '?' . $queryString : $scriptName;

    return new RedirectResponse($uri);
}
```

**Why This Matters:**
- `$request->getPathInfo()` returns `/` (root path) in OpenEMR context
- Redirecting to `/` causes OpenEMR to redirect to login.php with `frame-ancestors 'none'`
- This blocks the iframe with: "Firefox will not allow Firefox to display the page if another site has embedded it"
- Solution: Always use `$request->server->get('SCRIPT_NAME')` for redirects

## Summary - Quick Checklist

Before considering work complete:

- [ ] Public entry points are 25-35 lines max
- [ ] Controllers use `Request::createFromGlobals()`
- [ ] No direct access to $_GET, $_POST, $_FILES, $_SERVER, $_SESSION
- [ ] Controllers return Response objects (never void)
- [ ] No `header()`, `http_response_code()`, `die()`, or `exit` calls
- [ ] Custom exception hierarchy with interface and getStatusCode()
- [ ] Twig templates for all HTML (no inline HTML in PHP)
- [ ] CSRF validation on all POST requests
- [ ] Redirects remove `action` parameter to prevent loops
- [ ] Responses for tabs/iframes set `X-Frame-Options: SAMEORIGIN`
- [ ] Dialog templates don't use `openemr_header_setup()`
- [ ] No CDN links - use `{{ webroot }}/public/assets/...` for all static assets
- [ ] All pre-commit checks passing
- [ ] PHPDoc comments with proper type hints
- [ ] Symfony HTTP Foundation components used throughout

---

## Sinch API Documentation

When suggesting code for Sinch API integrations:
1. **Check `.local/llms.txt` first** - Local cached copy of Sinch API documentation
2. **Fetch from https://developers.sinch.com/llms.txt** if local copy unavailable

The llms.txt file contains complete API documentation for Sinch Conversations, webhooks, message formats, and authentication.

**Additional Sinch APIs (not in llms.txt):**

**Provisioning & Management APIs:**
- **Subproject API**: For managing subprojects within a Sinch project
  - Docs: https://developers.sinch.com/docs/subproject/api-reference/subproject.md
  - Use for: Multi-tenant setups, organizational hierarchy, resource isolation
  - Operations: Create, list, get, update, delete subprojects

- **Access Keys API**: For managing API keys and access control
  - Docs: https://developers.sinch.com/docs/accesskeys/api-reference.md
  - Use for: Creating/revoking API keys, managing permissions
  - Operations: Create keys, list keys, revoke keys, manage scopes

- **Projects API**: For managing Sinch projects
  - Docs: https://developers.sinch.com/docs/account/projects.md
  - Use for: Project configuration, settings management
  - Operations: Get project details, update settings

**When implementing provisioning features:**
1. Check llms.txt for Conversations API details
2. Consult web docs (markdown format) for Access Keys, Subprojects, and Projects APIs
3. Use `AppConfigurationClient` pattern for new provisioning methods
4. Add corresponding CLI commands for automation

## Development Tooling: Taskfile vs Composer Scripts

This module uses **both Taskfile and Composer scripts** with distinct responsibilities:

**Taskfile:** Infrastructure operations (Docker, database, module management)
**Composer scripts:** Code quality checks (phpcs, phpstan, rector)

**Why Both?**
- Composer scripts work in **any environment** (local, Docker, CI)
- Taskfile provides **convenience wrappers** and **Docker orchestration**
- Clear separation prevents bloated composer.json
- Taskfile can call Composer scripts for code quality

## Development Taskfile

This module uses **Taskfile** (taskfile.dev) for development automation. When suggesting development commands, **prefer Taskfile for infrastructure operations** and **Composer scripts for code quality**.

### When to Suggest Taskfile vs Composer

**Always suggest Taskfile for:**
- Docker operations: `task dev:start`, `task dev:logs`, `task dev:port`
- Module cleanup: `task module:cleanup`, `task module:tables`
- Database ops: `task db:shell`, `task db:export`

**Suggest either Taskfile OR Composer for code quality:**
- Code checks: `task check` OR `composer phpcs && composer phpstan`
- Auto-fix: `task check:fix` OR `composer phpcbf`
- In CI context: prefer `composer <script>` (no Docker dependency)
- In local dev: prefer `task check` (convenience wrapper)

**Examples:**
- Start Docker → `task dev:start` (not `docker compose up`)
- Clean tables → `task module:cleanup` (not raw SQL)
- Run checks → `task check` OR `composer phpcs` (both valid)
- View logs → `task dev:logs` (not `docker compose logs`)

### Common Tasks

```bash
# Docker
task dev:start          # Start environment
task dev:logs           # View logs
task dev:port           # Get URL
task dev:shell          # Container shell

# Module
task module:cleanup     # Drop tables
task module:tables      # List tables
task module:data        # Show counts

# Database
task db:shell           # MariaDB CLI
task db:query -- "SQL"  # Run query

# Code Quality
task check              # All checks
task check:fix          # Auto-fix

# Workflows
task setup              # Complete setup
task workflow:reinstall # Clean reinstall
```

### Why Taskfile + Composer (Not Just One)

**Taskfile advantages:**
- ✅ Self-documenting (`task --list`)
- ✅ Safety prompts on destructive operations
- ✅ Docker orchestration without raw commands
- ✅ Complex bash logic and workflows
- ✅ Variables and reusability

**Composer scripts advantages:**
- ✅ Work in any environment (no Docker needed)
- ✅ Familiar to all PHP developers
- ✅ CI/CD friendly (no additional tools)
- ✅ Standard for PHP projects
- ✅ No dependencies outside PHP ecosystem

## Docker Development Environment

This module includes a Docker Compose setup for local development. Raw Docker commands are available but Taskfile is preferred:

### Common Docker Commands

```bash
# Start environment
docker compose up -d --wait

# View logs
docker compose logs -f openemr

# Check status
docker compose ps

# Get assigned port
docker compose port openemr 80

# Execute commands in running containers
docker compose exec openemr bash
docker compose exec openemr php -v

# Access MariaDB
docker compose exec mysql mariadb -uroot -proot openemr

# Stop (keep data)
docker compose down

# Stop and remove data
docker compose down -v
```

**Use `docker compose exec` for commands:**
- Runs in already-running containers
- Fast execution (no startup overhead)
- No entrypoint conflicts

### Environment Details

**Services:**
- `openemr` - PHP 8.2, Apache, Alpine Linux
- `mysql` - MariaDB 11.4
- `phpmyadmin` - Database admin UI

**Credentials:**
- OpenEMR: admin / pass
- MySQL: root / root

**Key Paths:**
- Module: `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations`
- OpenEMR: `/var/www/localhost/htdocs/openemr`

**Development Notes:**
- Local file changes are instant (bind mounts)
- No rebuild needed for code changes
- OPCACHE disabled for development

### Troubleshooting

When suggesting fixes for Docker issues:
- Check logs first: `docker compose logs openemr`
- Verify health: `docker compose ps`
- Fresh start: `docker compose down -v && docker compose up -d --wait`
- Find ports: `docker compose port openemr 80`
- Run commands: `docker compose exec openemr bash`
- MariaDB access: `docker compose exec mysql mariadb -uroot -proot openemr`

---

## Copilot-Specific Tips

When suggesting code completions:
1. **Follow the MVC pattern** - Suggest controller methods that return Response objects
2. **Use Symfony Request** - Always use `Request::createFromGlobals()`, never $_GET/$_POST directly
3. **Use type hints** - Include PHPDoc and native PHP 8.2+ type hints
4. **Match existing patterns** - Look at similar code in the project for consistency
5. **Security first** - Always include CSRF validation for POST handlers
6. **Keep it minimal** - Suggest concise, focused implementations
7. **Use DI** - Suggest constructor injection for dependencies
8. **Exception handling** - Use custom module exceptions, not die/exit
9. **Template rendering** - Suggest Twig templates, not inline HTML
10. **Type casting** - Cast request values: `(string)$request->request->get('field', '')`
11. **Prefer Taskfile** - Suggest `task <name>` over raw Docker/composer commands when appropriate
12. **Docker awareness** - When suggesting Docker commands, use `docker compose exec`
13. **Database access** - Use QueryUtils for all database operations, never direct SQL functions
14. **MariaDB from Docker** - Use `mariadb` command (not `mysql`)
15. **Use git commands** - Use `git ls-files`, `git grep` instead of `find`, `grep` for file operations
16. **Use pre-commit/composer** - Never suggest manual syntax checks; use `pre-commit run -a` or `composer check`
17. **Show task list** - When user asks what they can do, suggest `task --list`
18. **No CDN assets** - Never suggest CDN links; use `{{ webroot }}/public/assets/...` for all static assets
