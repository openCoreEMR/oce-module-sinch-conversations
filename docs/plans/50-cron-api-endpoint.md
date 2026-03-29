# Plan: Trigger Appointment Reminders via Background Service (#50)

## Context

OpenEMR's background service system is being modernized upstream
(openemr/openemr#11325). The plan introduces:

- `BackgroundServiceRegistry` for standardized module registration (openemr/openemr#11329)
- `BackgroundServiceRunner` for extracted orchestration (openemr/openemr#11327)
- REST API at `/apis/{site}/api/admin/background-services/{name}/run` with OAuth2 (openemr/openemr#11330)
- CLI tooling: `bin/background-services list/run/crontab` (openemr/openemr#11328)

None of these are merged yet. Our approach: register as a background
service now using the existing table, so we're aligned with upstream's
direction. When the upstream API lands, triggering comes for free.

## Approach

### Phase 1: Register as background service (now)

Register `AppointmentReminderService` in the `background_services` table
during module install. This integrates with:

- The existing Ajax piggybacking (runs when users are logged in)
- The existing CLI: `php library/ajax/execute_background_services.php default oce_sinch_reminders 0`
- Future: upstream REST API (openemr/openemr#11330) and CLI (openemr/openemr#11328)

### Phase 2: Migrate to BackgroundServiceRegistry (when available)

When openemr/openemr#11329 merges, replace raw SQL with:

```php
(new BackgroundServiceRegistry())->register(new BackgroundServiceDefinition(
    name: 'oce_sinch_reminders',
    title: 'Sinch Appointment Reminders',
    function: 'oce_sinch_run_appointment_reminders',
    requireOnce: '...path.../background_service_entry.php',
    executeInterval: 15,
    active: true,
));
```

### Phase 3: Retire module-specific endpoint (when upstream API lands)

When openemr/openemr#11330 merges, external callers (K8s CronJobs) use:

```bash
POST /apis/default/api/admin/background-services/oce_sinch_reminders/run
Authorization: Bearer <oauth2-token>
```

No module-specific `cron.php` endpoint needed.

## Implementation (Phase 1)

### New Files

#### `background_service_entry.php` (module root)

Thin entry point that the background service system `require_once`s,
then calls. Defines the function registered in the DB:

```php
function oce_sinch_run_appointment_reminders(): void
{
    // Bootstrap the module, get the service, call run()
}
```

#### `tests/Unit/BackgroundServiceEntryTest.php`

- Test that the function exists after requiring the file
- Test that it delegates to `AppointmentReminderService::run()`

### Modified Files

#### `src/Module/Bootstrap.php`

- Add `install()` method (or extend existing install hook) to INSERT
  into `background_services` table
- Add `uninstall()` / `disable()` to DELETE / set `active = 0`

Registration SQL (following existing module patterns until
openemr/openemr#11329 lands):

```sql
INSERT INTO `background_services`
    (`name`, `title`, `active`, `running`, `next_run`,
     `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES
    ('oce_sinch_reminders', 'Sinch Appointment Reminders', 1, 0, NOW(),
     15, 'oce_sinch_run_appointment_reminders',
     '/interface/modules/custom_modules/oce-module-sinch-conversations/background_service_entry.php',
     100)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`),
    `function` = VALUES(`function`),
    `require_once` = VALUES(`require_once`)
```

#### `src/Module/Service/AppointmentReminderService.php`

No changes needed. `run()` is already idempotent.

### Module Lifecycle Hooks

| Event | Action |
|-------|--------|
| Install | INSERT into `background_services` |
| Enable | UPDATE `active = 1` |
| Disable | UPDATE `active = 0` |
| Uninstall | DELETE from `background_services` |

## Triggering Options (all work with this approach)

### Ajax piggybacking (built-in, no config)

Works automatically when users are logged in. Unreliable for off-hours.

### Existing CLI (works today)

```bash
php /path/to/openemr/library/ajax/execute_background_services.php \
    default oce_sinch_reminders 0
```

Can be wired to system cron:

```cron
*/15 * * * *  php /path/to/library/ajax/execute_background_services.php default oce_sinch_reminders 0
```

### K8s CronJob (works today via CLI)

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: sinch-appointment-reminders
spec:
  schedule: "*/15 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: trigger
            image: openemr/openemr:latest
            command:
            - php
            - /var/www/localhost/htdocs/openemr/library/ajax/execute_background_services.php
            - default
            - oce_sinch_reminders
            - "0"
```

### Upstream REST API (future, openemr/openemr#11330)

```bash
curl -X POST https://emr.example.com/apis/default/api/admin/background-services/oce_sinch_reminders/run \
  -H "Authorization: Bearer $TOKEN"
```

## Out of Scope

- No module-specific `cron.php` endpoint (upstream REST API will cover this)
- No custom auth scheme (upstream uses OAuth2)
- No changes to `AppointmentReminderService`

## Upstream References

- openemr/openemr#11325 — parent: modernize background service system
- openemr/openemr#11326 — fix `OPENEMR__NO_BACKGROUND_TASKS` env var
- openemr/openemr#11327 — extract `BackgroundServiceRunner`
- openemr/openemr#11328 — CLI tooling
- openemr/openemr#11329 — `BackgroundServiceRegistry` (replaces raw SQL)
- openemr/openemr#11330 — REST API endpoints
