# Docker Development Environment

This directory contains Docker configuration for testing the Sinch Conversations module.

**Key Feature:** Uses a simplified custom entrypoint (`entrypoint-simple.sh`) that bypasses the standard OpenEMR entrypoint's slow permission-setting operations, making startup much faster on macOS.

## Quick Start

```bash
# 1. Install module dependencies
composer install

# 2. Build OpenEMR dependencies (OPTIONAL - speeds up first start)
#    If you skip this, the container will build them automatically (slower)
cd vendor/openemr/openemr
composer install --no-dev
npm install --legacy-peer-deps
npm run build
cd ../../..

# 3. Start the environment
docker compose up -d --wait

# 4. Get the assigned port
docker compose port openemr 80      # OpenEMR HTTP (e.g., 0.0.0.0:32768)

# 5. Open browser to http://localhost:PORT and login
```

**Pro Tip:** Pre-building OpenEMR's dependencies (step 2) saves ~5-10 minutes on first container start. The Docker entrypoint detects existing builds and skips rebuilding.

## Default Credentials

| Service | Username | Password |
|---------|----------|----------|
| OpenEMR | admin | pass |
| MySQL | root | root |

## Enable the Module

1. Log into OpenEMR as admin
2. Go to **Administration > Modules > Manage Modules**
3. Click **Register** next to "oce-module-sinch-conversations"
4. Click **Install** and then **Enable**

## Development Workflow

**Local changes are immediately reflected** - no rebuild needed:
- Edit any PHP file → refresh browser to see changes
- Edit Twig templates → refresh browser
- Run `composer install` in module → dependencies available immediately
- Modify CSS/JS in `public/assets/` → refresh browser

The container has `OPCACHE_OFF=1` so PHP code changes are instant.

## Useful Commands

```bash
# View logs
docker compose logs -f openemr

# Execute commands in OpenEMR container
docker compose exec openemr bash
docker compose exec openemr php -v

# Access MariaDB database
docker compose exec mysql mariadb -uroot -proot openemr

# Stop environment
docker compose down

# Stop and remove all data (fresh start)
docker compose down -v

# Restart OpenEMR container (rarely needed)
docker compose restart openemr

# Rebuild OpenEMR dependencies after updating OpenEMR version
cd vendor/openemr/openemr && composer install --no-dev && npm install --legacy-peer-deps && npm run build && cd ../../..
```

**Note:** We use `docker compose exec` to run commands in already-running containers:
- Fast execution (no container startup overhead)
- No entrypoint conflicts
- Commands run in existing container environment
- Use `mariadb` command for database access (not `mysql`)

## File Locations

- **Module mount**: `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations`
- **OpenEMR logs**: Check `docker compose logs openemr`
- **Writable data**: `/var/www/localhost/htdocs/openemr/sites` (volume)

## Notes

- **Custom entrypoint**: We use `docker/entrypoint-simple.sh` instead of the standard `openemr.sh`
  - Runs InstallerAuto.php to configure OpenEMR (once)
  - Starts Apache immediately
  - Skips slow `chown` operations that take 5-10 minutes on macOS bind mounts
  - Much faster startup (~30 seconds instead of 10 minutes)
- **Pre-built OpenEMR**: Mount pre-built OpenEMR from `vendor/openemr/openemr`
  - Run `composer install --no-dev` and `npm install && npm run build` locally first
  - Container uses pre-built artifacts, no need to rebuild inside Docker
- **Persistent data**: Patient data, configurations, and uploads live in `sitesvolume` Docker volume
  - Survives container restarts
  - Use `docker compose down -v` to completely reset
- **Live reload**: Code changes are immediately reflected since bind mounts update in real-time
