# OpenEMR Module Development Guide

This document describes the architectural patterns and conventions for OpenEMR modules developed by OpenCoreEMR. Follow these patterns when working on **any** OpenEMR module in this organization.

## Documentation Index

### Development Patterns

| Document | Description |
|----------|-------------|
| [Architecture](docs/development/architecture.md) | Module structure, file conventions, entry points, Bootstrap pattern |
| [Controllers](docs/development/controllers.md) | Controller pattern, Request/Response handling rules |
| [Exceptions](docs/development/exceptions.md) | Exception hierarchy, error handling best practices |
| [Templates](docs/development/templates.md) | Twig templates, filters, dialog/iframe patterns |
| [Database](docs/development/database.md) | QueryUtils usage, why never use sql.inc.php directly |
| [OpenEMR Integration](docs/development/openemr-integration.md) | Tabs/iframes, redirects, dependencies, version constraints |
| [Code Quality](docs/development/code-quality.md) | Standards, security checklist, pre-commit checks |

### Development Environment

| Document | Description |
|----------|-------------|
| [Docker](docs/development/docker.md) | Docker setup, commands, troubleshooting, database operations |
| [Tooling](docs/development/tooling.md) | Taskfile vs Composer scripts, common tasks |

### Browser Automation (for AI agents)

| Document | Description |
|----------|-------------|
| [OpenEMR Login](docs/browser-automation/openemr-login.md) | Login process, credentials, form elements |
| [OpenEMR Navigation](docs/browser-automation/openemr-navigation.md) | Menu system, config navigation, common issues |

### Sinch Integration

| Document | Description |
|----------|-------------|
| [API Reference](docs/sinch/api-reference.md) | Sinch API documentation links and usage guidance |
| [Provisioning Guide](docs/sinch/provisioning-guide.md) | Full app provisioning: DISPATCH mode, Consent Management, webhooks, verification |
| [Setup Guide](docs/sinch/setup-guide.md) | Sinch dashboard setup, 10DLC campaign registration, module configuration |
| [Troubleshooting](docs/sinch/troubleshooting.md) | Debugging undelivered SMS, common errors, dashboard navigation |

### Messaging

| Document | Description |
|----------|-------------|
| [Appointment Reminders](docs/messaging/appointment-reminders.md) | Phase 1 scope, message templates, variables, admin config |
| [Opt-Out Handling](docs/messaging/opt-out.md) | Opt-out keywords, flows (via text and chart), compliance notes |
| [Testing Scenarios](docs/messaging/testing.md) | End-to-end testing scenarios for messaging functionality |

### Architecture Decision Records

| Document | Description |
|----------|-------------|
| [ADR-0001: Dispatch Mode + Consent Management](docs/adr/0001-dispatch-mode-consent-management.md) | Opt-out architecture: DISPATCH mode, Sinch Consent Management, consent API polling, multi-tenant |

### Regulatory

| Document | Description |
|----------|-------------|
| [Regulatory Considerations](docs/regulatory.md) | TCPA, HIPAA, FCC rules, healthcare exemptions, consent model |

## Quick Reference

### Key Patterns

**Controllers:**
- Use `Request::createFromGlobals()` - never `$_GET`, `$_POST`, `$_SERVER`
- Return `Response` objects - never `void`, `die()`, or `exit`
- Throw custom exceptions with `getStatusCode()` method

**Database:**
- Always use `QueryUtils::fetchRecords()` and `QueryUtils::sqlStatementThrowException()`
- Never use `sqlStatement()`, `sqlQuery()`, or other direct SQL functions

**Templates:**
- Use Twig filters: `xlt`, `text`, `attr`, `xlj`
- Dialog templates should NOT use `openemr_header_setup()`

**Error Handling:**
- Always catch `\Throwable`, not `\Exception`
- Never expose `$e->getMessage()` to users (flash messages, JSON responses) — use traceable error IDs
- Never swallow exceptions (catch-log-continue) — surface failures to callers

**Logging:**
- Use PSR-3 context arrays — never string concatenation or interpolation in log messages
- Pass exceptions as `'exception' => $e` in context
- See [Exceptions](docs/development/exceptions.md) for full patterns

### Common Commands

```bash
# Start development environment
task dev:start

# Run all code quality checks
task check

# Clean up module tables
task module:cleanup
```

### OpenEMR source lives under `tools/openemr/`, not `vendor/`

OpenEMR is intentionally NOT a runtime dependency of this module. Its source tree is
installed at `tools/openemr/vendor/openemr/openemr/` via a sub-composer
(`tools/openemr/composer.json`) and made available to:

- **PHPStan** — `phpstan.neon` loads `tools/openemr/vendor/autoload.php` via `bootstrapFiles`
- **Local Docker dev** — `compose.yml` bind-mounts `tools/openemr/vendor/openemr/openemr` to `/var/www/.../openemr`

Do **not** add `openemr/openemr` to the root `composer.json`. If you do, the module's
`vendor/autoload.php` will register a competing PSR-4 mapping for `OpenEMR\\` → our
vendor's `src/`, classes will resolve to our copy, and `__DIR__`-relative `require_once`
inside those classes will re-load procedural files under a different path → fatal
`Cannot redeclare …` on patient demographics and elsewhere. See
[issue #118](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/118)
and [`tools/openemr/README.md`](tools/openemr/README.md).

### Configuration Modes

The module supports two configuration modes:

1. **Database Globals** (default): Configure via Admin > Config > OpenCoreEMR Sinch Conversations
2. **Environment Variables**: Set `OCE_SINCH_CONVERSATIONS_ENV_CONFIG=1` and provide credentials via env vars

For local development, use the env var approach:
```bash
cp compose.override.yml.example compose.override.yml
# Edit .env with your Sinch credentials
docker compose up -d --wait
```

See [Docker docs](docs/development/docker.md#environment-based-configuration) for details.

### Testing Tips

**CRITICAL:** When the module's enabled status changes, you must **log out and log back in** for the menu to update. The module menu item won't appear/disappear until after re-login.

**Never refresh pages in OpenEMR** - close tabs and re-navigate through menus instead.

See [Navigation docs](docs/browser-automation/openemr-navigation.md#critical-testing-behaviors) for browser automation guidelines.

### CLI Commands

This module includes CLI commands for debugging and automation:

| Command | Description |
|---------|-------------|
| `sinch:app:list` | List Sinch Conversation API apps |
| `sinch:webhook:list` | List configured webhooks |
| `sinch:webhook:create` | Create a new webhook |
| `sinch:inspect` | Inspect current configuration |

### Sinch API Documentation

For AI agents working with Sinch APIs:

1. **Check local copy first**: `.local/llms.txt` (cached copy of Sinch API docs)
2. **Fetch latest if needed**: https://developers.sinch.com/llms.txt

See [docs/sinch/api-reference.md](docs/sinch/api-reference.md) for additional APIs not covered in llms.txt.

## Module info.txt (REQUIRED)

**Every module MUST have an `info.txt` file.** OpenEMR reads this file to display the module name in the admin UI.

Format: Single line with the display name (e.g., `OpenCoreEMR Sinch Conversations Module`). If missing, OpenEMR falls back to the directory name.

## Versioning with Release Please

Module versions are managed automatically by Release Please. **Never edit version numbers manually.**

- `.release-please-manifest.json` - Source of truth for version
- `version.php` - Updated automatically via `extra-files` in release-please-config.json
- Merge PRs with conventional commit titles; Release Please handles the rest

## CRITICAL: Handling Errors and Warnings

**NEVER ignore errors or warnings from any check.** Make every effort to fix them properly.

**Forbidden shortcuts (require explicit user approval):**
- Adding entries to `symbol-whitelist` in `.composer-require-checker.json`
- Adding entries to a PHPStan baseline file
- Using `@phpstan-ignore-*` annotations
- Using `// phpcs:ignore` comments
- Suppressing warnings with `@SuppressWarnings`

If suppression seems genuinely necessary, **ask the user first** and explain why it cannot be fixed properly.

**The right approach:**
1. Understand what the error is telling you
2. Fix the root cause (add missing types, fix logic, add dependencies)
3. If stuck, ask the user for guidance
4. Only suppress with explicit user approval and a comment explaining why
