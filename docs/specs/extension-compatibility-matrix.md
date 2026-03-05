# Extension Compatibility Matrix (v1.0-v1.3)

## Policy

- Runtime contract additions must be additive.
- Existing stable payload fields may not be removed or renamed without a major version transition.
- Legacy aliases remain supported until explicitly deprecated and replacement path is documented.

## Surface Matrix

| Surface | Introduced | Status | Compatibility Rule |
|---|---|---|---|
| MCP `tools/call` stable meta (`contract_version`, `contract_stability`, `tool`, `tool_invoked`) | v1.0 | Stable | Must remain backward compatible |
| MCP alias normalization (`search_teachings` -> `search_entities`) | v1.0 | Stable | Alias retained; canonical tool contract unchanged |
| MCP read-path caching (transparent) | v1.1 | Stable | Must not change tool payload shape |
| Plugin extension interface (`KnowledgeToolingExtensionInterface`) | v1.2 | Stable | Method signatures additive-only |
| Plugin extension runner (`KnowledgeToolingExtensionRunner`) | v1.2 | Stable | Ordered deterministic execution required |
| Extension SDK scaffold (`scaffold:extension`) | v1.3 | Stable | Scaffold payload keys and template contract versioned |
| Kernel bootstrap seam (`extensions.plugin_directories`) | v1.3 | Stable | Empty/default config preserves prior behavior |
| MCP extension diagnostics in `tools/introspect` | v1.3 | Stable additive | Additive introspection-only; `tools/call` unchanged |

## Required Contract Tests

- MCP stable meta compatibility under extension registration.
- Kernel extension runner bootstrap fallback behavior (configured + empty).
- Extension SDK scaffold deterministic payload and validation paths.
- Cross-repo harness execution with auditable artifact output.
