# waaseyaa/deployer

**Layer 6 — Interfaces**

Shared Deployer recipe for Waaseyaa applications.

Ships canonical `deploy.php` recipes that consumer apps `require` to get standardized atomic deploys, asset compilation, migration runs, and health-check probing. The recipe expects a Caddy + PHP-FPM + systemd target and integrates with `bin/waaseyaa db:init` for first-deploy database initialization.

`RuntimeState\FrameworkRuntimeTableCatalogue` is the versioned ownership source
for framework-created SQLite tables. `SqliteArtifactPreparer` installs reviewed
content into a candidate while preserving serving-host runtime state and
append-only evidence; `SqliteArtifactInstaller` provides verified atomic
activation and restore. Applications declare only their own artifact tables.
See `docs/specs/sqlite-artifact-installation.md` in the framework repository.

Key files: `recipe/`, `deploy.php`.
