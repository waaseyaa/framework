# waaseyaa/ai-development

**Meta-package — `require-dev` only.**

The local AI-development plane: the packages a developer needs so a
standards-based coding agent can introspect a Waaseyaa application on their own
machine. It owns no code, no resources, and no service provider.

```bash
composer require --dev waaseyaa/ai-development
```

## What it pulls

| Package | Why |
|---|---|
| `waaseyaa/ai-agent` | The three read-only Bimaaji introspection tools the default profile admits (`packages/ai-agent/src/Tool/Bimaaji/`), and the never-persisted `LocalOperatorPrincipal` that acts on the local plane. |
| `waaseyaa/testing` | Typed framework-contract fixtures. Its whole meaningful surface is already `@api` consumer surface, and it carries no HTTP surface and no production runtime cost. |

## What it deliberately does not pull

`waaseyaa/mcp` is **not** a dependency. That package registers `/mcp/write`
unconditionally — its own route provider says so
(`packages/mcp/src/McpRouteProvider.php`) — so requiring it to obtain a
transport would add an HTTP route to every application that installed a
*development* tool. The local plane's transport is a stdio server reached
through transport-neutral contracts, neither of which is an HTTP surface.

## The boundary, and the gate that holds it

ADR-022 (`docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md`)
decides this package's shape. Three properties are load-bearing:

- It never appears in the `require` block of `waaseyaa/framework`,
  `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. Consumers opt in by name.
- The skeleton installs it under `require-dev` only, and
  `composer install --no-dev` removes it and everything it pulls.
- `waaseyaa/ai-agent` — the package homing `LocalOperatorPrincipal` — stays
  outside the production require closure of all four of those manifests.

None of that survives on review attention. `bin/check-composer-policy` rule
**CP009** computes those four production closures on every run and fails if the
development plane enters one, and
`tests/Architecture/DevelopmentPlaneClosureGateTest.php` seeds real dependency
edges into a disposable fixture on every run to prove CP009 can still go red.
