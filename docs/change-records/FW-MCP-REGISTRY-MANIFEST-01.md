# FW-MCP-REGISTRY-MANIFEST-01 — expose official Registry server.json

- Parent: `origin/main` at lease (`c20ad47d0845a7ed4dafcb56fb3a6df7daca7a11`)
- Contract: `docs/specs/mcp-endpoint.md`, `docs/specs/cli-kernel.md`
- Forge mirror: Framework #2638
- Authority: CLI operator surface over the existing Layer-4 manifest emitter

## Finding

`McpRegistryManifest` is built, configured, and container-bound, with no
route and no command. The spec deferred the adapter to #2207, which closed
after landing `ProvidesConsoleCommandsInterface`. Publication to the official
Registry stays out of scope.

## Decision

1. Confirm #2207's contract is the adapter: a Layer-6 provider contributes
   commands without Layer 4 importing CLI types.
2. Add `mcp:registry-manifest` in `waaseyaa/cli`, gated on `waaseyaa/mcp`
   (`RequiresOptionalPackagesInterface`, suggest + require-dev). This is a
   different gate from `mcp:serve` (Layer-5 AI plane). The `cli mcp` PL007
   baseline edge matches `cli oidc` / `cli ai-agent`: a hard require would
   install MCP for every CLI consumer.
3. Resolve the existing `McpRegistryManifest` binding. Write JSON to stdout.
   Configuration refusals go to stderr and exit non-zero.
4. Do not add an HTTP route. Do not publish to the Registry.

## Sequence

1. Fixture command and optional-package provider tests.
2. Implement the command and provider; refresh `composer.lock` discovery metadata.
3. Update the two specs so they stop saying the emitter is unreachable.

## Boundaries

No Registry publication, namespace authentication, preview-schema
revalidation, `/mcp` transport changes, or `mcp:serve` stdio session changes.
