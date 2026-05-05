# tools/openemr — OpenEMR source for tooling and dev runtime

This directory exists so the OpenEMR source tree is available **outside** the module's runtime composer tree.

It serves two purposes:

1. **PHPStan type resolution.** `phpstan.neon` loads `tools/openemr/vendor/autoload.php` via `bootstrapFiles`, so PHPStan can resolve `OpenEMR\…` symbols.
2. **Local Docker bind mount.** `compose.yml` bind-mounts `./tools/openemr/vendor/openemr/openemr` to `/var/www/localhost/htdocs/openemr` inside the dev container. `task openemr:prebuild` runs `composer install --no-dev` and `npm install && npm run build` here so the dev container starts quickly.

## Why not put `openemr/openemr` in the root `composer.json`?

If `openemr/openemr` is in the root `require-dev`, then `vendor/openemr/openemr/` exists after `composer install` and our `vendor/autoload.php` registers a PSR-4 mapping for `OpenEMR\\` → our vendor's `src/`. At runtime, `OpenEMR\…` class lookups can resolve to **our** vendor copy instead of OpenEMR core's canonical path. Several OpenEMR classes do `require_once(__DIR__ . '/../../../library/lists.inc.php');`; `__DIR__` then points into our vendor tree, the file is loaded under a different absolute path than OpenEMR core already loaded it, and `require_once` (which dedups by string path, not content) re-runs the procedural function definitions. Result: `Cannot redeclare …` fatal on any page that exercises the affected classes.

See [issue #118](https://github.com/openCoreEMR/oce-module-sinch-conversations/issues/118) for the full incident.

## Usage

The Taskfile drives everything:

```bash
task tools:install    # install OpenEMR source here
task openemr:prebuild # composer install --no-dev + npm install + npm run build inside it
task setup            # the full first-time bring-up (calls both of the above)
```

`tools/openemr/vendor/` and `tools/openemr/composer.lock` are gitignored.
