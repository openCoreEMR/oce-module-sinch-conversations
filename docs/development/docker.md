# Docker Development Environment

## Quick Start Commands

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

## Running Commands Inside Containers

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

## Development Workflow

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

## Troubleshooting Docker Issues

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

## Running Tests in Docker

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

## Database Operations

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
docker compose exec -T mysql mariadb-dump -uroot -proot openemr < module_backup.sql
```

## Environment Details

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

## Best Practices

1. Use `docker compose` (not `docker-compose`) - newer syntax
2. Use `docker compose exec` for running commands in containers
3. Use `mariadb` command (not `mysql`) for database shell access
4. Use `-T` flag with exec for piped input (e.g., database imports)
5. Use pre-commit or composer scripts for code quality checks (never manual syntax checks)
6. Use git commands (`git ls-files`, `git grep`) instead of `find` for file operations
7. Check logs first when debugging issues
8. Verify container health with `docker compose ps`
9. Remember that local file changes are instant
