# Extension Author Onboarding Kit

<!-- Spec reviewed 2026-08-04 - #2191: extension tool verification uses the canonical application tool contribution lifecycle, MCP tools/list, and protected admin diagnostics; the removed tools/introspect contract is not advertised. -->

## Goal

Provide a fast, deterministic path for external authors to build and validate Waaseyaa extensions.

## 15-Minute Quickstart

1. Generate scaffold contract:
   - `php bin/waaseyaa scaffold:extension --id my_extension --label "My Extension" --package vendor/my-extension`
2. Create extension package using generated templates (`README.md`, `composer.json`, `src/<Class>.php`).
3. Wire extension discovery in app config:
   - `extensions.plugin_directories[] = <path-to-extension-src>`
4. Validate bootstrap integration:
   - `php bin/waaseyaa list --no-ansi`
5. Validate any contributed agent tools:
   - call MCP `tools/list` and verify their names, schemas, and annotations;
   - use the authenticated `GET /api/mcp/tools/{name}` admin endpoint when the
     richer registry read model is needed.

## Reference Example

See:

- `docs/examples/extension-author-kit/composer.json`
- `docs/examples/extension-author-kit/src/StoryGraphExtension.php`

## Common Touchpoints

- Workflow context: `alterWorkflowContext(array $context): array`
- Traversal context: `alterTraversalContext(array $context): array`
- Discovery context: `alterDiscoveryContext(array $context): array`
- MCP catalogue: application-contributed agent tools surface through the
  canonical AI tool registry and `tools/list`; capability requirements are on
  the protected admin read model, and MCP does not expose plugin-hook
  diagnostics.

## Verification Checklist

- Unit tests pass in extension package.
- Waaseyaa compatibility matrix checks pass.
- Cross-repo harness run is green:
  - `tools/integration/run-v1.3-cross-repo-harness.sh`
- MCP `tools/list` shows the expected contributed tools, when applicable.

## Troubleshooting

### Extension not discovered

- Verify `extensions.plugin_directories` points to the correct directory.
- Verify class has `#[WaaseyaaPlugin(...)]` attribute.
- Verify class autoload namespace matches `composer.json` PSR-4 map.

### Contributed MCP tool is missing

- Verify the application contributes the tool through
  `ProvidesAgentToolsInterface` and that its name is unique.
- Verify the target tier includes the tool and the credential scope permits it.

### Context mutations not visible

- Verify app bootstrap calls `applyWorkflowExtensionContext`, `applyTraversalExtensionContext`, and `applyDiscoveryExtensionContext` at the expected seams.
