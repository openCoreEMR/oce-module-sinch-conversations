# OpenEMR Module Development Guide for AI Agents

This document describes the architectural patterns and conventions for OpenEMR modules developed by OpenCoreEMR. Follow these patterns when working on **any** OpenEMR module in this organization.

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
 * @link      http://www.open-emr.org
 * @author    [Author Name] <email@example.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\{ModuleName}\Bootstrap;
use OpenCoreEMR\Modules\{ModuleName}\GlobalsAccessor;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->get{Feature}Controller();

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? 'default';

// Dispatch to controller and send response
$response = $controller->dispatch($action);
$response->send();
```

## Controller Pattern

Controllers should:
- Be in `src/Controller/`
- Use **constructor dependency injection**
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
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class {Feature}Controller
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly {Feature}Service $service,
        private readonly Environment $twig
    ) {
        $this->logger = new SystemLogger();
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
        } catch (\Throwable $e) {
            $this->logger->error("Error creating item: " . $e->getMessage());
            throw new {Module}Exception("Error creating item: " . $e->getMessage());
        }
    }
}
```

## Exception Handling Pattern

### Error Handling Best Practice: Always Catch `\Throwable`

**CRITICAL: Always catch `\Throwable` instead of `\Exception`**

- `\Throwable` is the base interface for both `\Exception` and `\Error`
- Catching `\Exception` will miss fatal errors like `\TypeError`, `\ParseError`, etc.
- Always use `catch (\Throwable $e)` for comprehensive error handling

**Example:**
```php
try {
    $this->service->doSomething();
} catch (\Throwable $e) {  // ✅ Catches both exceptions and errors
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

**Never do:**
```php
try {
    $this->service->doSomething();
} catch (\Exception $e) {  // ❌ Misses TypeError, ParseError, etc.
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

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
    $response = $controller->dispatch($action, $_REQUEST);
    $response->send();
} catch ({Module}ExceptionInterface $e) {
    error_log("Error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    error_log("Unexpected error: " . $e->getMessage());

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

## Database Access Pattern

### CRITICAL: Always Use QueryUtils

**NEVER use direct SQL functions from `sql.inc.php`**

All database operations must go through the `QueryUtils` class from OpenEMR's common library.

### ✅ ALWAYS DO:

```php
use OpenEMR\Common\Database\QueryUtils;

// Execute query and get all results
$records = QueryUtils::fetchRecords($sql, $binds);

// Execute query and get single row
$record = QueryUtils::querySingleRow($sql, $binds);

// Execute INSERT/UPDATE/DELETE (throws exception on error)
QueryUtils::sqlStatementThrowException($sql, $binds);

// Execute query without throwing (returns statement handle)
$result = QueryUtils::sqlStatement($sql, $binds);
```

### ❌ NEVER DO:

```php
// ❌ Direct SQL functions from sql.inc.php
$result = sqlStatement($sql, $binds);
$row = sqlFetchArray($result);
$result = sqlQuery($sql, $binds);
sqlInsert($sql);
sqlBind($sql, $binds);

// These should NEVER appear in module code!
```

### QueryUtils Methods Reference

| Method | Purpose | Returns | Throws |
|--------|---------|---------|--------|
| `fetchRecords($sql, $binds)` | Get all rows as array | `array<int, array<string, mixed>>` | On error |
| `querySingleRow($sql, $binds)` | Get single row | `array<string, mixed>` | On error |
| `sqlStatementThrowException($sql, $binds)` | Execute statement (INSERT/UPDATE/DELETE) | Statement handle | On error |
| `sqlStatement($sql, $binds)` | Execute without throwing | Statement handle | No |

### Examples

**Fetching multiple records:**
```php
$sql = "SELECT * FROM oce_sinch_messages WHERE direction = ? ORDER BY created_at DESC LIMIT ?";
$messages = QueryUtils::fetchRecords($sql, ['inbound', 50]);

foreach ($messages as $message) {
    echo $message['message_id'];
}
```

**Fetching a single record:**
```php
$sql = "SELECT * FROM oce_sinch_conversations WHERE conversation_id = ?";
$conversation = QueryUtils::querySingleRow($sql, [$conversationId]);

if ($conversation) {
    echo $conversation['status'];
}
```

**Executing INSERT/UPDATE/DELETE:**
```php
$sql = "UPDATE oce_sinch_messages SET status = ?, updated_at = NOW() WHERE id = ?";
QueryUtils::sqlStatementThrowException($sql, ['delivered', $messageId]);
```

### Why QueryUtils?

1. **Consistency** - Single interface for all database operations
2. **Error Handling** - Proper exception throwing with context
3. **Security** - Prepared statements with parameter binding
4. **Maintainability** - Easier to test and refactor
5. **Type Safety** - Better static analysis support

If you use direct SQL functions, they will fail the Composer Require Checker because they shouldn't be in the whitelist.

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

## Docker Development Environment

### Quick Start Commands

When the user asks you to work with Docker:

```bash
# Start the environment
docker compose up -d --wait

# View logs in real-time
docker compose logs -f openemr

# Check container status
docker compose ps

# Get the assigned port for OpenEMR
docker compose port openemr 80

# Stop environment (keeps data)
docker compose down

# Stop and remove all data (fresh start)
docker compose down -v
```

### Running Commands Inside Containers

**Use `docker compose exec` for running commands in already-running containers:**
- Fast execution (no container startup)
- No entrypoint conflicts
- Commands run in existing container environment

**Execute commands in OpenEMR container:**
```bash
# Access bash shell
docker compose exec openemr bash

# Run PHP commands
docker compose exec openemr php -v
docker compose exec openemr php -l /path/to/file.php

# Run command directly without shell
docker compose exec openemr sh -c "cd /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations && ls -la"
```

**Access MariaDB database:**
```bash
# MariaDB CLI
docker compose exec mysql mariadb -uroot -proot openemr

# Execute SQL queries
docker compose exec mysql mariadb -uroot -proot -e "SHOW TABLES LIKE 'oce_sinch%'" openemr

# Dump database
docker compose exec mysql mariadb-dump -uroot -proot openemr > backup.sql

# Import database (use -T to disable pseudo-TTY)
docker compose exec -T mysql mariadb -uroot -proot openemr < backup.sql
```

### Development Workflow in Docker

**Key Information:**
- Module is mounted at: `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations`
- OpenEMR root: `/var/www/localhost/htdocs/openemr`
- All local file changes are immediately reflected (bind mount)
- No rebuild needed for code changes
- OPCACHE is disabled for instant PHP updates

**Testing Changes:**
1. Edit files locally in your editor
2. Refresh browser - changes appear immediately
3. No need to restart containers

**Viewing Logs:**
```bash
# All OpenEMR logs
docker compose logs -f openemr

# Filter for errors only
docker compose logs -f openemr | grep -i error

# View Apache error log
docker compose exec openemr tail -f /var/log/apache2/error.log

# View MySQL logs
docker compose logs -f mysql
```

### Troubleshooting Docker Issues

**When asked to debug Docker issues:**

1. **Check container status:**
   ```bash
   docker compose ps
   ```

2. **View recent logs:**
   ```bash
   docker compose logs --tail=100 openemr
   ```

3. **Check if OpenEMR installed successfully:**
   ```bash
   docker compose exec openemr ls -la /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php
   ```
   - If this file exists, installation is complete
   - If missing, installer may still be running

4. **Verify database connection:**
   ```bash
   docker compose exec mysql mariadb -uroot -proot -e "SHOW DATABASES"
   ```

5. **Check module files are mounted:**
   ```bash
   docker compose exec openemr ls -la /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations
   ```

**Common Issues:**

- **Container won't start:** Check logs with `docker compose logs openemr`
- **Port conflicts:** Use `docker compose port openemr 80` to find assigned port
- **Database errors:** Verify MySQL is healthy with `docker compose ps mysql`
- **Changes not showing:** Restart Apache with `docker compose restart openemr`
- **Fresh start needed:** `docker compose down -v && docker compose up -d --wait`

### Running Tests in Docker

```bash
# Access container shell
docker compose exec openemr bash

# Navigate to module directory
cd /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations

# Run all pre-commit checks (includes syntax, PHPCS, PHPStan, Rector, etc.)
pre-commit run -a

# Run individual composer scripts
composer phpcs    # Code style check
composer phpstan  # Static analysis
composer check    # Run all checks
```

### Database Operations in Docker

**View module tables:**
```bash
docker compose exec mysql mariadb -uroot -proot -e "SHOW TABLES LIKE 'oce_sinch%'" openemr
```

**Query data:**
```bash
docker compose exec mysql mariadb -uroot -proot -e "SELECT * FROM oce_sinch_conversations LIMIT 10" openemr
```

**Run SQL from file:**
```bash
# From local file (use -T to disable pseudo-TTY)
docker compose exec -T mysql mariadb -uroot -proot openemr < table.sql
```

**Export/Import:**
```bash
# Export module tables only
docker compose exec mysql mariadb-dump -uroot -proot openemr oce_sinch_conversations oce_sinch_messages > module_backup.sql

# Import
docker compose exec -T mysql mariadb -uroot -proot openemr < module_backup.sql
```

### When User Asks About Docker

**Recognize these requests:**
- "The Docker container won't start"
- "How do I view logs?"
- "Can you run this in Docker?"
- "Database isn't working"
- "How do I access the container?"
- "I need to restart OpenEMR"

**Always:**
1. Use `docker compose` (not `docker-compose`) - newer syntax
2. Use `docker compose exec` for running commands in containers
3. Use `mariadb` command (not `mysql`) for database shell access
4. Use `-T` flag with exec for piped input (e.g., database imports)
5. Use pre-commit or composer scripts for code quality checks (never manual syntax checks)
6. Use git commands (`git ls-files`, `git grep`) instead of `find` for file operations
7. Check logs first when debugging issues
8. Verify container health with `docker compose ps`
9. Remember that local file changes are instant

### Docker Environment Details

**Services:**
- `openemr` - OpenEMR application server (Alpine Linux, PHP 8.2, Apache)
- `mysql` - MariaDB 11.4 database
- `phpmyadmin` - Web-based MySQL admin interface

**Volumes:**
- `databasevolume` - Persistent MySQL data
- `logvolume` - Apache/PHP logs
- Bind mounts for code (live updates)

**Credentials:**
- OpenEMR: admin / pass
- MySQL: root / root
- MySQL app user: openemr / openemr

**Ports:**
- OpenEMR: Random port (use `docker compose port openemr 80`)
- MySQL: Random port (use `docker compose port mysql 3306`)
- phpMyAdmin: Random port (use `docker compose port phpmyadmin 80`)

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
- [ ] All pre-commit checks passing
- [ ] PHPDoc comments with proper type hints
- [ ] Symfony HTTP Foundation components used throughout
