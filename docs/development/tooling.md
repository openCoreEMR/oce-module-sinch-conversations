# Development Tooling: Taskfile vs Composer Scripts

## Separation of Concerns

This module uses **both Taskfile and Composer scripts**, each with distinct responsibilities:

**Taskfile is for:**
- Docker orchestration (start, stop, logs, exec)
- Database operations (import, export, queries via Docker)
- Module management (cleanup, table inspection)
- Complex workflows with prompts (destructive operations)
- Infrastructure commands that don't run in all environments

**Composer scripts are for:**
- Code quality checks (phpcs, phpstan, rector)
- PHP operations that work in any environment
- CI/CD pipeline tasks
- Commands that don't require Docker context

**Why This Separation?**
- Composer scripts work **everywhere** (local, Docker, CI, production)
- Taskfile provides **convenience wrappers** and **orchestration**
- Clear separation prevents tool bloat in composer.json
- Taskfile can call Composer scripts (but not vice versa)

## Common Tasks Reference

**Docker Environment:**
```bash
task dev:start          # Start Docker with health check
task dev:stop           # Stop (keeps data)
task dev:restart        # Restart services
task dev:reset          # Complete wipe (prompts)
task dev:logs           # Follow logs
task dev:logs:errors    # Error logs only
task dev:port           # Get OpenEMR URL
task dev:shell          # Container bash
task dev:status         # Health check
```

**Module Management:**
```bash
task module:cleanup     # Drop all tables (prompts)
task module:tables      # List module tables
task module:data        # Show data counts
task module:install     # Installation instructions
```

**Database:**
```bash
task db:shell           # MariaDB shell
task db:export          # Export to backup.sql
task db:import          # Import from backup.sql
task db:query -- "SQL"  # Run ad-hoc query
```

**Code Quality (calls Composer scripts):**
```bash
task check              # All checks (calls: pre-commit run -a)
task check:phpcs        # Code style (calls: composer phpcs)
task check:phpstan      # Static analysis (calls: composer phpstan)
task check:fix          # Auto-fix (calls: composer phpcbf)
```

**Quick Workflows:**
```bash
task setup              # Complete setup
task workflow:reinstall # Clean reinstall
task workflow:reset     # Full reset
```

## Best Practices

1. **Prefer Taskfile over raw commands** - It's more user-friendly and self-documenting
2. **Show available tasks** - Use `task --list` to see all tasks organized by category
3. **Mention safety features** - Destructive tasks prompt for confirmation
4. **Combine with explanations** - Explain what the task does, not just the command
5. **Know when to use Composer instead** - For code quality checks that work in CI, suggest `composer phpcs` or `task check` interchangeably

**Good Example:**
```
To clean up the database tables for a fresh reinstall:
task module:cleanup

This will drop all module tables and prompt for confirmation.
After cleanup, reinstall via OpenEMR's module manager.
```

**Bad Example:**
```
Run: docker compose exec -T mysql mariadb -uroot -proot openemr < cleanup.sql
```
