# waaseyaa/cli

**Layer 6 — Interfaces**

Command-line interface for Waaseyaa applications.

Provides Symfony Console commands for entity management (`entity:create`), configuration export/import (`config:export`, `config:import`), schema checking, health diagnostics, transactional provider-neutral site initialization (`site:init`), and the `optimize:manifest` command that runs `PackageManifestCompiler`. Entry point: `bin/waaseyaa`.

Framework Foundation owns `ConsoleKernel`; this package supplies command adapters, site diagnostics and initialization.

## Invocation

Run from the project root (the directory containing `composer.json`):

```
./vendor/bin/waaseyaa <command>
```

The bin resolves project root from `getcwd()` — matching Laravel's `artisan` and Symfony's `bin/console` convention. Running from any other directory exits with a clear error. See ADR-005 for rationale.

## Management verification

An optional `.waaseyaa/management.json` companion describes actual operation
bindings without granting access or executing them. The boot-free `site:doctor`
refuses management conformance when no live inventory is supplied. Product
verification composes `new SiteDoctorService($inventory)->inspect($projectRoot)`.

Use `Site\Management\ManagementInputDiscovery::digest($projectRoot)` for every
inventory and executed result's `sourceDigest`. It hashes the complete regular
input tree, including Go, SQL, root tests, extensionless scripts, hidden files,
lockfiles, installed dependencies and permission bits. Only Git metadata is
excluded. Keep the tree stable and store receipts/logs outside it. Symlinks,
nonregular or unreadable inputs refuse verification; use a regular packaged
install rather than Composer path symlinks. The architecture scanner's narrower
`source_sha256` remains unchanged; `management_input_sha256` binds management
checks independently. Cross-host mode equality is not promised.

The optional `ToolRegistryManagementInventory` projects an effective
`waaseyaa/ai-tools` registry and product-owned policies without dispatching tools.
Install `waaseyaa/ai-tools` explicitly when using that adapter. Symfony Finder is
a direct CLI runtime dependency for complete input enumeration. API/CLI products
supply their own inventories. A declared check or synthetic fixture is not an
executed product receipt. See `docs/specs/agent-management.md` in Framework.

These symbols require the qualified release cohort containing this change;
alpha.302 does not provide them. Product upgrades remain separately owned.
