# Feature Specification: Author-Path Remediation

**Mission:** `author-path-remediation-01KVBP4S` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-17

## Summary

The agent-readable **read** side (HTML, Markdown content negotiation, public MCP) works. But
authoring ONE piece of content into a stock app on Windows took ~8 minutes of fumbling — none of
it the trio, all of it the **author path** and **inverted access defaults**. This mission removes
that friction so a cold agent on Windows can create one content item and serve it three ways
(HTML, Markdown via `Accept: text/markdown`, MCP `entity.read`) using only documented CLI
commands — no one-off PHP, no base64 `eval`, no manifest recompile.

**Constraint:** no deployed downstream apps exist → NO backward-compat shims or deprecation
layers; prefer the correct clean design.

## Actors

- **Author / cold agent** — creates a content type + a content item via documented CLI, on
  Windows PowerShell/cmd or POSIX, then serves it three ways.
- **Anonymous reader / AI agent** — reads the published item over HTTP (HTML, Markdown) and MCP.

## Evidence (the failures to eliminate)

1. `entity:create` only accepts inline `--values=<JSON>`; PowerShell mangled it across eight
   quoting attempts (single/double/backtick/`--%`/`cmd /c`/`.bat`), forcing a base64
   `php -r "eval(...)"` fallback.
2. The stock app had no story content type → a whole `App\Entity\Story` + config + `schema:sync`
   had to be hand-built.
3. Markdown negotiation first exposed only registered node fields and **dropped the body**.
4. MCP `entity.read` was **access-denied** until a hand-written `StoryAccessPolicy`.
5. That policy wasn't discovered until `composer dump-autoload -o` + `optimize:manifest`.

## User Scenarios & Testing

1. **Cross-platform field input.** An author sets field values without inline JSON — repeatable
   `--field name=value`, `--field-file name=@path`, `--values-file path.json`, or stdin — and it
   works identically in PowerShell, cmd, and POSIX with zero quoting gymnastics.
2. **One-command content type.** `waaseyaa make:content-type story --fields="title:string,body:text,source_url:string"`
   generates the entity class, registration, schema, and leaves the type immediately usable —
   no constructor spelunking, no hand-written access policy.
3. **Public-read by default.** A published content entity is anonymously readable via HTML,
   Markdown negotiation, and MCP `entity.read` with NO hand-written policy, and each surface
   exposes the entity's actual stored fields (body included) — not a hardcoded subset.
4. **Dev auto-discovery.** In dev, a newly generated app entity type + policy are picked up with
   no `composer dump-autoload -o` and no `optimize:manifest`. Production keeps the compiled
   manifest.
5. **Acceptance (release gate).** A cold agent on Windows: `make:content-type` → `schema:sync`
   → `entity:create` (file/stdin field input) → serve HTML + `Accept: text/markdown` + MCP
   `entity.read`, using only documented CLI commands.

### Edge cases

- Unpublished content → NOT anonymously readable on any surface (boundary preserved).
- Internal/credential/restricted fields → never exposed on any surface, even though stored fields
  are now shown by default.
- `--field` vs `--values-file` vs stdin precedence is deterministic and documented.
- A content item with no path alias is still reachable at its canonical system path.

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | `entity:create` MUST accept field values via repeatable `--field name=value`, `--field-file name=@path`, `--values-file path.json`, and stdin — without inline JSON. | Proposed |
| FR-002 | All field-input paths MUST work in PowerShell, cmd, and POSIX with no quoting gymnastics; the Windows `.bat`/bin entry MUST pass args through faithfully. | Proposed |
| FR-003 | `make:content-type <name> --fields="f:type,..."` MUST generate the entity class, its registration, and schema-ready field definitions (with required target metadata), leaving the type immediately usable. | Proposed |
| FR-004 | A published content entity MUST be anonymously readable via HTML, Markdown negotiation, and MCP `entity.read` with NO hand-written access policy. | Proposed |
| FR-005 | HTML, Markdown, and MCP `entity.read` MUST expose the entity's actual stored fields (body included), not a registered/hardcoded subset. | Proposed |
| FR-006 | A published content item MUST be reachable over HTTP at a canonical system path (`/{entityType}/{id}`) without a hand-created path alias. | Proposed |
| FR-007 | In dev mode, newly added app entity types and access policies MUST be discovered with no `composer dump-autoload -o` and no `optimize:manifest`; production keeps the compiled manifest. | Proposed |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | The trio's security boundary MUST hold: anonymous read stays scoped to PUBLISHED entities and NON-restricted fields only; nothing writable or restricted leaks on any surface. Proven by tests for each surface. | Proposed |
| NFR-002 | The default public-read grant MUST be additive — it grants published `view`, never overrides a `Forbidden` from a more specific policy. | Proposed |
| NFR-003 | MCP `entity.read` MUST field-filter (drop internal/credential/restricted) identically to the JSON:API serializer while still exposing stored content fields. | Proposed |
| NFR-004 | Dev-mode manifest refresh MUST NOT change production behavior (compiled manifest unchanged in prod). | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | No BC shims / deprecation layers (no deployed downstream apps). | Accepted |
| C-002 | The access boundary (FR-004, NFR-001/002/003) and dev/prod split (FR-007/NFR-004) MUST be provably correct — no guessing. | Accepted |
| C-003 | Tests respect the Linux-first split-suite contract; the acceptance gate runs on Windows. | Accepted |

## Success Criteria

- SC-001: `entity:create` populated from a file and from stdin, verified by test + live on Windows.
- SC-002: `make:content-type story …` yields an immediately-usable type (no manual class/policy).
- SC-003: A published item is anonymously readable via HTML, `Accept: text/markdown`, and MCP
  `entity.read` with body present and no hand-written policy — verified live.
- SC-004: Unpublished item + internal/restricted fields are NOT exposed on any surface —
  verified by security tests.
- SC-005: The full author→serve flow needs no `dump-autoload -o` / `optimize:manifest` in dev.
- SC-006: The cold-Windows acceptance flow passes end-to-end using only documented CLI.

## Assumptions

- A-001: "Published" = a content entity whose published signal is truthy (status field /
  workflow state published), per `EditorialVisibilityResolver`/`WorkflowVisibility`.
- A-002: `make:content-type` targets app usage (generates into `App\…`); the acceptance runs in
  the skeleton-based stock app.
- A-003: Canonical `/{entityType}/{id}` resolution is published-gated and read-only.

## Scope

**In:** the five evidence fixes + canonical system-path serving + the acceptance gate.
**Out:** write/mutation surfaces over HTTP/MCP; admin UI; non-content entity types' public read.
