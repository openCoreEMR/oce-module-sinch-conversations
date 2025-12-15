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

### Common Commands

```bash
# Start development environment
task dev:start

# Run all code quality checks
task check

# Clean up module tables
task module:cleanup
```

### Module Configuration Notes

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
