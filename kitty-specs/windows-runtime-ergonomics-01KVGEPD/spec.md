# Feature Specification: Windows Upgrade & FrankenPHP Runtime Ergonomics

**Mission:** `windows-runtime-ergonomics-01KVGEPD` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-19

## Summary

Two issues blocked the *patch* step of the alpha.226 CRUD demo on Windows — neither is a framework logic bug, both are constraint/ergonomics gaps that make a clean point-upgrade and `serve:franken` launch fail or self-sabotage:

- **D1 — downstream constraint too tight.** The demo app pinned `waaseyaa/framework` to an **exact** version (`"0.1.0-alpha.226"`), so `composer update` did nothing until the constraint was hand-edited. The framework's own skeleton already uses a caret; the exact pin was introduced downstream (most likely via `composer require waaseyaa/framework:<exact>`). The framework can still harden the on-ramp so consumers are nudged to a caret.
- **D4 — FrankenPHP shadows system PHP on Windows.** The skeleton README tells users to "put the frankenphp binary on PATH." The official FrankenPHP Windows release is a full PHP-for-Windows SDK that bundles its own `php.exe` (with OpenSSL/cURL **disabled** by default). Prepending the install directory to PATH puts that crippled `php.exe` ahead of the OpenSSL-capable system PHP, breaking Composer with an OpenSSL error. The prior `WAASEYAA_FRANKENPHP_BIN` knob was removed in alpha.225, leaving `serve:franken` dependent on PATH with no override.

This mission makes a downstream point-upgrade take cleanly and makes `serve:franken` launch FrankenPHP without poisoning the PHP that Composer and the CLI use, on Windows specifically.

**Constraint:** no deployed downstream apps depend on current guidance; prefer the correct clean design.

## Resolved Decisions (locked — no longer open)

- **D4:** Add a `bin/serve-franken` wrapper that resolves the binary as `${FRANKENPHP_BIN:-frankenphp}`, so an operator points at an absolute `frankenphp` install via `FRANKENPHP_BIN` **without** adding the SDK directory to PATH. `serve:franken` invokes the wrapper.
- **D4 docs:** Rewrite the skeleton README and the Windows operations playbook to instruct users to **NOT** add the FrankenPHP SDK directory to PATH (the official Windows release bundles an OpenSSL-less `php.exe` that shadows system PHP and breaks Composer); document `FRANKENPHP_BIN` instead. Remove the false "no per-machine paths to configure" claim for Windows.
- **D1:** Downstream constraint hygiene (use a caret); the framework change is documentation/helper only. No framework scaffold bug.

**This mission is HELD at P1** — decisions locked, implementation awaits an explicit go (not in the P0 trio).

## Actors

- **App operator on Windows** — upgrades a downstream app to a new alpha and serves it with FrankenPHP, using documented steps only.
- **Framework maintainer** — owns the skeleton README, `serve:franken` script, and operations playbooks.

## Evidence (the failures to eliminate)

1. **D1.** [my-app/composer.json:9](file:///C:/Users/jones/Codex/my-app/composer.json) declares `"waaseyaa/framework": "0.1.0-alpha.226"` (exact pin). Composer treats a bare exact semver as equality, so `composer update` resolves only to that version — the literal cause of "composer update did nothing."
2. **D1 — framework ships the correct shape.** [skeleton/composer.json:11](skeleton/composer.json) carries a caret (`^0.1.0-alpha.199`) at every tag .221–.226; [tools/sync-skeleton-requirements.php:62-63](tools/sync-skeleton-requirements.php) emits a caret at release time; `skeleton/bin/post-create-setup.php` never rewrites the constraint. So no framework scaffold produces an exact pin — D1 is downstream constraint hygiene, with an optional framework doc/helper hardening.
3. **D1 — manual exact-to-exact history.** my-app git history shows each bump was a hand edit (`…alpha.221` → `…alpha.223` → `…alpha.226`), all exact, never a caret — which is why every point upgrade silently no-ops until edited.
4. **D4 — bundled php.exe.** `C:\Users\jones\.frankenphp` ships `frankenphp.exe` **and** `php.exe`/`php-cgi.exe`/`php.ini`/`ext/` (a full SDK). The bundled `php.exe` reports `openssl=false`, `curl=false`, no `https` wrapper; system PHP at `C:\tools\php85\php.exe` has them enabled.
5. **D4 — guidance forces the shadow.** [skeleton/README.md:80-83](skeleton/README.md) says to put frankenphp on PATH ("no per-machine paths to configure"); [skeleton/composer.json:41-44](skeleton/composer.json) defines `serve:franken` as a bare `frankenphp php-server …` that depends on PATH. On Windows the only way to satisfy that is to add the install **directory** to PATH, which also shadows system `php.exe`. The `WAASEYAA_FRANKENPHP_BIN`/`WAASEYAA_FRANKENPHP_INI` knobs were removed in alpha.225 ([CHANGELOG.md:32](CHANGELOG.md)), leaving no override. [docs/specs/operations-playbooks.md:81](docs/specs/operations-playbooks.md) echoes the same on-PATH assumption.

## User Scenarios & Testing

1. **Clean point-upgrade.** An operator on a downstream app with a caret constraint runs the documented upgrade command and Composer moves `waaseyaa/framework` to the next available alpha — it does not silently no-op.
2. **Serve without shadowing PHP.** An operator on Windows launches `serve:franken` and FrankenPHP serves the app, while `composer` and `waaseyaa` in the same shell still resolve the OpenSSL-capable system PHP (no OpenSSL error reaching Packagist).
3. **Documented binary location.** An operator points `serve:franken` at a FrankenPHP install via a documented override (env var / wrapper) without adding the SDK directory to PATH.

### Edge cases

- A user who already added the FrankenPHP dir to PATH must have a documented way to recover (precedence guidance / explicit override).
- `serve:franken` via the bundled FrankenPHP `php.exe` (extensions off) must still work for serving even though that php is not used for Composer.
- The fix must not change `public/index.php` (front-controller convergence audit unaffected).

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | `serve:franken` MUST locate the FrankenPHP binary via an explicit, documented override (e.g. a `FRANKENPHP_BIN` env var resolved as `${FRANKENPHP_BIN:-frankenphp}`, likely via a `bin/serve-franken` wrapper) so a Windows user need not add the SDK directory to PATH. | Proposed |
| FR-002 | The skeleton README and operations playbook MUST be corrected for Windows: warn that the official Windows FrankenPHP release bundles a full `php.exe` with OpenSSL disabled, instruct NOT to add the install directory to PATH, and document the override from FR-001. The false "no per-machine paths to configure" claim MUST be removed for Windows. | Proposed |
| FR-003 | Upgrade documentation MUST instruct downstream consumers to use a **caret** constraint (`^0.1.0-alpha.NNN`) and warn that `composer require waaseyaa/framework:<exact>` writes an exact pin that blocks point upgrades. | Proposed |
| FR-004 | (Optional framework hardening) A documented/automated path SHOULD let a consumer convert an exact pin to a caret and take the upgrade (e.g. a `waaseyaa` upgrade helper or a documented `composer require waaseyaa/framework:^…` + `composer update waaseyaa/*`). | Proposed |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | The fix MUST NOT modify `public/index.php` or the front-controller stub; the four-copy convergence audit MUST stay green. | Proposed |
| NFR-002 | Re-introducing a FrankenPHP binary override MUST NOT re-introduce an arbitrary-binary-execution risk beyond what the operator already controls (the env var points at a local binary the operator installed). | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | D1 is downstream constraint hygiene — the framework itself already ships a caret; the framework-side change is documentation/helper only, NOT a scaffold bug fix. | Accepted |
| C-002 | No BC shims (no deployed downstream apps). | Accepted |

## Success Criteria

- SC-001: On Windows with the SDK-bundled FrankenPHP and OpenSSL-capable system PHP, following the documented setup leaves `php -r 'var_dump(extension_loaded("openssl"))'` true and Composer reaches Packagist over https with no OpenSSL error, AND `serve:franken` still launches via the documented override without the install dir on PATH.
- SC-002: README/operations playbook no longer contain the unqualified "put the install dir on PATH / no per-machine paths" claim for Windows.
- SC-003: A downstream app with a caret constraint upgrades to the next alpha via the documented command (does not no-op); the exact-pin case is documented as the failure mode.
- SC-004: `composer validate` on the skeleton stays green; `FrontControllerRuntimeDispatchTest` stays green (public/index.php untouched).

## Key Entities

- `skeleton/composer.json` (`serve:franken` script), `skeleton/README.md`, `docs/specs/operations-playbooks.md`, `CHANGELOG.md`.
- A possible new `bin/serve-franken.sh` / `.ps1` wrapper (mirrors `bin/dev.sh`).
- `tools/sync-skeleton-requirements.php` (already caret-correct — reference only).

## Assumptions

- A-001: The recommended D4 remedy is an env-var override (`FRANKENPHP_BIN`) plus corrected docs, restoring a scoped version of the knob removed in alpha.225 — confirm with the runtime owner before choosing wrapper vs inline.
- A-002: D1 requires no framework source change to be *correct* today; the framework change is documentation (FR-003) plus an optional helper (FR-004).
- A-003: The exact verbatim OpenSSL error string was not captured; the mechanism (bundled php.exe `openssl=false`, missing https wrapper) is confirmed.

## Scope

**In:** `serve:franken` binary-resolution override; corrected Windows guidance in skeleton README + operations playbook; downstream caret-upgrade documentation; optional upgrade helper.

**Out:** changing `public/index.php`; changing the release-time skeleton caret (already correct); the Windows `chmod`-aborts-post-create on-ramp bug (#1628, sibling but distinct); non-Windows FrankenPHP behaviour.
