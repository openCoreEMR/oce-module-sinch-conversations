# OpenEMR Integration Patterns

## Working with OpenEMR Tabs/Iframes

Modules load in OpenEMR's tab system which uses iframes. Key considerations:

### Redirects Must Use Full Script Path

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
        '/interface/modules/custom_modules/oce-module-sinch-conversations/public/index.php'
    );
    $uri = $queryString ? $scriptName . '?' . $queryString : $scriptName;

    return new RedirectResponse($uri);
}
```

### Why This Matters

- `$request->getPathInfo()` returns `/` (root path) in OpenEMR context
- Redirecting to `/` causes OpenEMR to redirect to login.php with `frame-ancestors 'none'`
- This blocks the iframe with: "Firefox will not allow Firefox to display the page if another site has embedded it"
- Solution: Always use `$request->server->get('SCRIPT_NAME')` for redirects

## Researching OpenEMR Code and Dependencies

**CRITICAL: Always check OpenEMR's actual requirements in `tools/openemr/vendor/openemr/openemr/composer.json`**

When you need to understand OpenEMR's code, dependencies, or version constraints:

### Always Do:
- **Check `tools/openemr/vendor/openemr/openemr/composer.json`** for OpenEMR's exact dependency versions
- **Look in `tools/openemr/vendor/openemr/openemr/src/`** for OpenEMR core classes
- **Match OpenEMR's Symfony version constraints** - They use exact versions (e.g., `6.4.15`), not ranges
- **Use `^6.4` constraints** for Symfony packages to stay compatible with OpenEMR 6.4.x

### Never Do:
- Search online for OpenEMR version requirements -> Check `tools/openemr/vendor/openemr/openemr/composer.json`
- Guess at version constraints -> Verify against OpenEMR's actual versions
- Use `^6.0 || ^7.0` for Symfony -> Use `^6.4` to match OpenEMR's 6.4.x versions
- Assume OpenEMR uses latest versions -> They pin specific versions

### Example: Checking OpenEMR's Symfony Versions

```bash
# Check what Symfony versions OpenEMR uses
cat tools/openemr/vendor/openemr/openemr/composer.json | grep symfony

# Result shows exact versions:
# "symfony/console": "6.4.15",
# "symfony/event-dispatcher": "6.4.13",
# "symfony/http-foundation": "6.4.16",
```

### Why This Matters

OpenEMR uses **exact Symfony 6.4.x versions**, not version ranges. Your module must be compatible:
- Use `^6.4` - Compatible with OpenEMR's 6.4.x versions
- Don't use `^6.0 || ^7.0` - Would allow Symfony 7.x which OpenEMR doesn't support
- Don't use `^7.0` - Not compatible with OpenEMR

## Dependencies

Always include these in `composer.json` with version constraints that match OpenEMR:

```json
{
  "require": {
    "php": ">=8.2",
    "symfony/console": "^6.4",
    "symfony/event-dispatcher": "^6.4",
    "symfony/http-foundation": "^6.4",
    "twig/twig": "^3.0"
  }
}
```

**Note:** Version constraints must match OpenEMR's installed versions. Always verify in `tools/openemr/vendor/openemr/openemr/composer.json`.

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
