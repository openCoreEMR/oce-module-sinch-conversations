# Integration Tests

A separate suite from `tests/Unit`. Boots OpenEMR's real autoloader and runs against the live MariaDB inside the dev container. Catches the "wrong core function called" / "fixture diverges from production schema" class of bug that pure-PHP unit tests cannot — the class of bug that produced #137 and #143.

**Fixture strategy (important):** patient and appointment rows are inserted directly into `patient_data` and `openemr_postcalendar_events` via `QueryUtils`, *not* through `PatientService::insert()` or any other public OpenEMR service. The fixture managers shape the rows to match what the real OpenEMR form handlers write (including `pc_recurrtype` / `pc_recurrspec` for recurring events, which `AppointmentService::insert()` itself does not populate), so core's procedural appointments library — `fetchAllEvents` / `fetchAppointments` — expands and returns them the same way it would in production. The "real OpenEMR" part of this suite is the *read* side of the seam (the procedural fetch + the actual reminder pipeline + the actual Bootstrap container), not the write side.

## Layout

```
tests/Integration/
├── bootstrap.php                       # Loads /var/www/.../openemr/interface/globals.php
├── IntegrationTestCase.php             # Base TestCase: helpers + tearDown cleanup
├── AppointmentReminderCronTest.php     # Scenarios for oce_sinch_run_appointment_reminders()
├── Fakes/
│   ├── RecordingMessageService.php     # Records sendToPatient() calls instead of dispatching
│   └── RecordingBootstrap.php          # Bootstrap subclass that returns the recorder
└── Fixtures/
    ├── PatientFixtureManager.php       # Inserts/cleans patient_data rows
    └── AppointmentFixtureManager.php   # Inserts/cleans openemr_postcalendar_events rows
```

## Running locally

Two prerequisites:

1. `task dev:start` — bring up the stack.
2. `task module:install-enable` — register and enable the module so `oce_sinch_*` tables exist (one-time).

Then:

```sh
task test:integration
```

That runs `phpunit -c phpunit.integration.xml` *inside the openemr container* — the host has no PHP/DB connectivity to the running install.

## Fixture conventions

Every fixture row is tagged with the prefix `oce-sinch-test-fixture` (in `patient_data.pubpid` and `openemr_postcalendar_events.pc_title`). `tearDown` deletes by prefix on OpenEMR-owned tables and by `patient_id IN (fixture pids)` on module-owned tables. Result: tests can run repeatedly against the dev DB without polluting it; a parallel run of upstream's own tests (whose prefix is `test-fixture`) cannot remove our fixtures or vice versa.

If a test crashes mid-run, leftover rows survive — but the next run's `setUp` re-runs the same scrub before inserting fresh fixtures, so the impact is limited to one run's worth of warning noise.

## Adding a scenario

1. Subclass `IntegrationTestCase`.
2. Use `$this->patients->insert([...])` and `$this->appointments->insertOneShot(...)` / `insertDailyRecurring(...)`.
3. Call `$this->runReminders($now)` and assert against `$this->recorder()->getSends()` and the returned counters.

Don't reach for raw SQL or `sqlInsert` for OpenEMR-owned tables — extend the relevant fixture manager instead so cleanup keeps working.

## Why no `drid`, no transactional wrapping

`drid` (drop + reinstall) is a sledgehammer that breaks any concurrent dev session and adds minutes per run. Transactional wrapping looks tempting but breaks against OpenEMR's MyISAM tables and any code path that issues an implicit commit (DDL, certain `SET` statements). Prefix-tagged cleanup is the upstream OpenEMR convention (`tools/openemr/.../tests/Tests/Fixtures/BaseFixtureManager.php`) — we follow it for the same reasons they do.

## CI

The `integration-tests` job in `.github/workflows/integration-tests.yml` brings up the dev stack on every PR and runs the suite on a single PHP version (the unit-test matrix continues to cover all supported versions).
