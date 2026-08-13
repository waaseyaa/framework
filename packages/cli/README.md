# waaseyaa/cli

**Layer 6 — Interfaces**

Command-line interface for Waaseyaa applications.

Provides Symfony Console commands for entity management (`entity:create`), configuration export/import (`config:export`, `config:import`), schema checking, health diagnostics, transactional provider-neutral site initialization (`site:init`), and the `optimize:manifest` command that runs `PackageManifestCompiler`. Entry point: `bin/waaseyaa`.

Key classes: `ConsoleKernel`, health and schema check commands.

## Invocation

Run from the project root (the directory containing `composer.json`):

```
./vendor/bin/waaseyaa <command>
```

The bin resolves project root from `getcwd()` — matching Laravel's `artisan` and Symfony's `bin/console` convention. Running from any other directory exits with a clear error. See ADR-005 for rationale.
