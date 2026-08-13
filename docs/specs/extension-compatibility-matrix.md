# Extension Compatibility Matrix (v1.0-v1.3)

<!-- Spec reviewed 2026-08-04 - #2191: removed legacy MCP-controller rows were retired; the current application agent-tool contribution and duplicate-name refusal contract is the live extension surface. -->

## Policy

- Runtime contract additions must be additive.
- Existing stable payload fields may not be removed or renamed without a major version transition.
- Legacy aliases remain supported until explicitly deprecated and replacement path is documented.

## Surface Matrix

| Surface | Introduced | Status | Compatibility Rule |
|---|---|---|---|
| Plugin extension interface (`KnowledgeToolingExtensionInterface`) | v1.2 | Stable | Method signatures additive-only |
| Plugin extension runner (`KnowledgeToolingExtensionRunner`) | v1.2 | Stable | Ordered deterministic execution required |
| Extension SDK scaffold (`scaffold:extension`) | v1.3 | Stable | Scaffold payload keys and template contract versioned |
| Kernel bootstrap seam (`extensions.plugin_directories`) | v1.3 | Stable | Empty/default config preserves prior behavior |
| Application agent-tool contribution (`ProvidesAgentToolsInterface`) | Current main | Stable additive | Tool names are unique; registration order cannot select a winner |

## Required Contract Tests

- Agent-tool contribution and duplicate-name refusal.
- Kernel extension runner bootstrap fallback behavior (configured + empty).
- Extension SDK scaffold deterministic payload and validation paths.
- Cross-repo harness execution with auditable artifact output.

## Package Layer Table

This is the canonical, package-level mirror of the seven-layer architecture enforced by `bin/check-package-layers`. The `LAYER_BY_SHORT` map in the script and the "Layer Architecture" row in `CLAUDE.md` mirror this table; when the three disagree, this matrix is authoritative. Mission #824 WP02 surfaces A–E ratify the contents below.

| Package | Layer | Notes |
|---|---|---|
| analytics | 0 | |
| cache | 0 | |
| database-legacy | 0 | Composer name `waaseyaa/database-legacy`; PHP namespace `Waaseyaa\Database` (ADR 007) |
| error-handler | 0 | |
| foundation | 0 | Hosts kernel exemption surface (see footnote 3) |
| geo | 0 | |
| http-client | 0 | |
| i18n | 0 | |
| ingestion | 0 | |
| mail | 0 | |
| mercure | 0 | |
| oauth-provider | 0 | |
| plugin | 0 | |
| queue | 0 | |
| scheduler | 0 | |
| state | 0 | |
| typed-data | 0 | |
| validation | 0 | |
| access | 1 | |
| audit | 1 | |
| auth | 1 | |
| config | 1 | |
| entity | 1 | |
| entity-storage | 1 | |
| field | 1 | |
| oidc | 1 | |
| testing | 1 | Ships entity fixtures; runtime `require waaseyaa/entity` is intentional |
| user | 1 | |
| engagement | 2 | |
| attachment | 2 | |
| groups | 2 | |
| media | 2 | |
| menu | 2 | |
| node | 2 | |
| note | 2 | |
| path | 2 | |
| relationship | 2 | |
| taxonomy | 2 | |
| billing | 3 | |
| github | 3 | |
| listing | 3 | Declarative, access-aware dynamic collections |
| messaging | 3 | |
| migration | 3 | |
| notification | 3 | |
| page-builder | 3 | Governed client-neutral layout documents; see `page-builder.md` |
| publishing | 3 | |
| search | 3 | |
| seo | 3 | |
| structured-import | 3 | |
| workflows | 3 | |
| api | 4 | |
| bimaaji | 4 | |
| routing | 4 | |
| wayfinding | 4 | |
| ai-agent | 5 | |
| ai-observability | 5 | |
| ai-pipeline | 5 | |
| ai-schema | 5 | |
| ai-tools | 5 | |
| ai-vector | 5 | |
| admin-surface | 6 | PHP host extension for the admin SPA (`packages/admin/`); see footnote 1 |
| cli | 6 | |
| debug | 6 | |
| deployer | 6 | |
| frankenphp | 6 | |
| genealogy | 6 | |
| graphql | 6 | |
| inertia | 6 | |
| mcp | 6 | |
| ssr | 6 | |
| telescope | 6 | |
| workspace | 6 | |

**Total: 72 PHP packages** across 7 layers (18 / 10 / 10 / 12 / 4 / 6 / 12).

### Footnotes

1. **`packages/admin/` (Nuxt SPA, no `composer.json`).** Excluded from the PHP layer graph. The admin frontend is a TypeScript/Vue project (Nuxt 3); its PHP host is `waaseyaa/admin-surface` (L6). Verified by mission #824 WP02 surface D: zero PHP files under the tree, no inbound `require waaseyaa/admin` from any first-party manifest.
2. **Metapackages (`cms`, `core`, `full`).** Aggregator manifests with `type: metapackage`. Skipped by `bin/check-package-layers` and not assigned a layer; they re-export combinations of the packages above.
3. **Kernel exemption surface.** Layer rules are enforced at two levels: (a) `composer.json` runtime `require` edges (`PL003`/`PL004`), and (b) PHP file-level `use` imports (`PL005`). Kernel-adjacent files are exempted via `KERNEL_EXEMPT_DIR_SUFFIXES` (implicit, `<pkg>/src/Kernel/`) and `KERNEL_EXEMPT_FILES` (explicit named-file allowlist). See `docs/specs/infrastructure.md` "Kernel exemption surface (named files)".

### Synchronization

When adding or reclassifying a first-party package, update **all three** sources together:

| Source | Role |
|---|---|
| `bin/check-package-layers` (`LAYER_BY_SHORT`) | Executable enforcement |
| This table | Package-level source of truth |
| `CLAUDE.md` "Layer Architecture" | Session-hot human-readable summary |

The three must agree. The gate ratifies the agreement on every CI run: `PL002` fires on any directory whose `composer.json` declares a `waaseyaa/*` name missing from `LAYER_BY_SHORT`.
