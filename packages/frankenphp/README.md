# waaseyaa/frankenphp

Optional **FrankenPHP dev-runtime** for Waaseyaa — the Laravel Octane model. It
adds two console commands and nothing else; the framework core stays
runtime-agnostic (no FrankenPHP coupling), and the runtime-agnostic
`waaseyaa serve` (plain `php -S`) remains the zero-dependency fallback in core.

This package is **not** part of `waaseyaa/core`/`cms`/`full`. It ships in the
whole-monorepo `waaseyaa/framework` meta, so the skeleton serves with
`composer run dev` out of the box; apps on the curated metapackages opt in with
`composer require waaseyaa/frankenphp`.

## Commands

### `frankenphp:install`

Downloads the correct FrankenPHP binary for the current OS/arch from the official
[php/frankenphp](https://github.com/php/frankenphp) releases into the project's
managed path (`vendor/bin/`), idempotently. After this the binary's location is
never the operator's problem.

- Per-OS/arch asset selection (Linux `x86_64`/`aarch64`, macOS `arm64`/`x86_64`,
  Windows `x86_64`), pinned to a known-good version (default `v1.12.4`; override
  with `--frankenphp-version` or `FRANKENPHP_VERSION`).
- Verifies the download's sha256 against the GitHub release digest, fail-closed: a
  checksum mismatch always refuses to install, and so does an **unavailable**
  digest (GitHub API unreachable/rate-limited, malformed response, or an asset
  with no published digest) — pass `--allow-unverified` to accept that risk
  explicitly and install anyway (the result is reported as unverified).
- Windows: the release is a full PHP-for-Windows SDK zip — the **whole** archive
  is extracted into `vendor/bin/frankenphp-dist/` so `frankenphp.exe` finds its
  sibling DLLs. POSIX: a bare binary with the executable bit set.
- Extraction uses ext-zip when present, else `tar` (Windows 10+ / macOS / Linux).
  Pure PHP, no shell.

### `dev`

Serves the app with FrankenPHP in the foreground:

```
frankenphp php-server --root public --listen 127.0.0.1:8080
```

Resolution order: `FRANKENPHP_BIN` → the managed install → known per-OS locations
→ `frankenphp` on `PATH` → otherwise it prints the one fix (`frankenphp:install`)
and, when interactive, offers to run it. It execs the binary **by absolute path**
with no shell (`proc_open` array form, inherited stdio so output is live and
Ctrl-C stops it), and **never modifies `PATH`** — so FrankenPHP's bundled
OpenSSL-disabled `php.exe` can never shadow system PHP and break Composer.

Override the listen address with `WAASEYAA_DEV_LISTEN` (e.g. `0.0.0.0:9000`).

The skeleton wires `composer run dev` → `@php vendor/bin/waaseyaa dev`, so the
command runs under Composer's own (system) PHP, identically in Git Bash,
PowerShell, cmd, and POSIX shells.

## Why a package

This permanently closes the "where is FrankenPHP / PATH shadowing / the shell
can't find PHP" class of dev-server failures by removing both roots — shell
fragility (the command is a discoverable PHP CLI command, not a shell script) and
binary-location guessing (`frankenphp:install` fetches it to a known path).
