# Codified Context Infrastructure — Design

**Date:** 2026-03-02
**Status:** Approved
**Source:** [Codified Context: Infrastructure for AI Agents in a Complex Codebase](https://arxiv.org/abs/2602.20478)

## Summary

Apply the paper's three-tier codified context architecture to Waaseyaa. The paper demonstrates that structured project knowledge (constitution + specialist agents + subsystem specs) substantially improves AI-generated code consistency across sessions in a 108K-line codebase. Waaseyaa at 74K lines is a natural fit.

## Current State

| Tier | Paper's architecture | Waaseyaa today |
|------|---------------------|----------------|
| 1. Hot memory (constitution) | ~660-line CLAUDE.md with conventions, orchestration protocols, trigger tables | 68-line CLAUDE.md — conventions + gotchas, no orchestration |
| 2. Specialized agents | 19 domain-expert agent specs (~9,300 lines) | Generic superpowers skills, no project-specific agents |
| 3. Cold memory (knowledge base) | 34 specs (~16,250 lines) via MCP retrieval | 18 plan docs (~19,785 lines) — session artifacts, not reusable specs |

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Approach | Full three-tier architecture | Existing plan docs provide mining material; upfront investment compounds |
| Agent mechanism | Claude Code skills | Skills = markdown specs loaded into sessions, exactly the paper's model |
| Retrieval | Local MCP server | Claude Code natively supports MCP; separate from remote packages/mcp/ |
| Knowledge sourcing | Mine existing 18 plan docs + CLAUDE.md gotchas | Knowledge exists, needs restructuring, not creation |
| Maintenance | Git-based drift detection + session discipline | Paper's #1 failure mode is stale specs |

---

## Tier 1: Constitution (Expanded CLAUDE.md)

Expand from 68 to ~180 lines. Add three sections; keep existing content.

### 1.1 Orchestration Trigger Table

Maps file patterns to specialist skills and cold memory specs:

```markdown
## Orchestration

| File pattern | Specialist skill | Cold memory spec |
|---|---|---|
| packages/entity/*, packages/entity-storage/*, packages/field/* | waaseyaa:entity-system | docs/specs/entity-system.md |
| packages/access/*, packages/user/src/Middleware/* | waaseyaa:access-control | docs/specs/access-control.md |
| packages/api/*, packages/routing/* | waaseyaa:api-layer | docs/specs/api-layer.md |
| packages/admin/* | waaseyaa:admin-spa | docs/specs/admin-spa.md |
| packages/ai-*/* | waaseyaa:ai-integration | docs/specs/ai-integration.md |
| packages/foundation/*, packages/cache/*, packages/database-legacy/* | waaseyaa:infrastructure | docs/specs/infrastructure.md |
| packages/mcp/* | waaseyaa:mcp-endpoint | docs/specs/mcp-endpoint.md |
| public/index.php, packages/*/src/Middleware/* | waaseyaa:middleware-pipeline | docs/specs/middleware-pipeline.md |
```

### 1.2 Layer Architecture Reference

```markdown
## Layer Architecture

Layer 0 (Foundation): foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, state, validation
Layer 1 (Core Data): entity, entity-storage, access, user, config, field
Layer 2 (Content Types): node, taxonomy, media, path, menu
Layer 3 (Services): workflows
Layer 4 (API): api, routing
Layer 5 (AI): ai-schema, ai-agent, ai-pipeline, ai-vector
Layer 6 (Interfaces): cli, admin, mcp, ssr, telescope

Rule: packages can only import from their own layer or lower. Upward communication via DomainEvents.
```

### 1.3 Operation Checklists

Short recipes for common tasks:

- **Adding an entity type** — steps: define EntityType, register in EntityTypeManager, create storage schema, add access policy, add API routes
- **Adding an access policy** — steps: implement AccessPolicyInterface (+ FieldAccessPolicyInterface if needed), register via PolicyAttribute, test with anonymous classes
- **Adding an API endpoint** — steps: add route in RouteBuilder, implement controller, wire access via route options, add to SchemaPresenter
- **Adding middleware** — steps: implement HttpMiddlewareInterface, add AsMiddleware attribute, register in pipeline compiler

### 1.4 Drift Warning

Add to gotchas:
> When refactoring a subsystem, update the relevant `docs/specs/` file. Stale specs cause agents to generate code conflicting with recent changes. Use `waaseyaa_search_specs` MCP tool to find affected specs.

---

## Tier 2: Domain Specialist Skills

7 skills, each ~200-400 lines. Installed as Claude Code skills. Content is >50% domain knowledge (following the paper's finding).

### Skill Structure Template

```
name: waaseyaa:<domain>
description: <when to trigger>
---
# <Domain> Specialist

## Scope
Which packages and files this covers.

## Key Interfaces
The contracts an agent must understand when working in this domain.

## Architecture
How data flows through this subsystem (with code patterns).

## Common Mistakes
Domain-specific gotchas (superset of what's in CLAUDE.md).

## Testing Patterns
How to write tests for this domain (in-memory strategies, fixtures).

## Related Specs
Which docs/specs/ files to retrieve for deep context.
```

### Skill Inventory

| Skill | Packages | Key knowledge encoded |
|---|---|---|
| `waaseyaa:entity-system` | entity, entity-storage, field, config | Constructor patterns (array $values + hardcoded type), _data JSON blob split/merge, enforceIsNew(), SqlEntityStorage reflection, entity keys mapping, UnitOfWork, EntityEvent public properties |
| `waaseyaa:access-control` | access, user (middleware) | Asymmetric semantics (isAllowed vs !isForbidden), FieldAccessPolicyInterface, intersection types for policies, open-by-default field access, policy evaluation OR logic, Forbidden short-circuit, AccountInterface vs concrete User |
| `waaseyaa:api-layer` | api, routing | JSON:API controller CRUD, ResourceSerializer paired nullables, SchemaPresenter x-access-restricted, QueryParser→QueryApplier→EntityQuery pipeline, post-fetch access filtering, LIKE wildcard escaping |
| `waaseyaa:admin-spa` | admin | Nuxt 3 composables (useEntity, useSchema, useLanguage, useRealtime), SSE integration with entity events, schema-driven form generation, i18n in en.json, TypeScript patterns |
| `waaseyaa:ai-integration` | ai-schema, ai-agent, ai-pipeline, ai-vector | EntityJsonSchemaGenerator (draft 2020-12), AgentExecutor with audit logging, Pipeline step orchestration by weight, tool safety, SchemaRegistry |
| `waaseyaa:infrastructure` | foundation, cache, database-legacy, plugin, queue | ServiceProvider register/boot lifecycle, DomainEvent three-channel dispatch (sync/async/broadcast), query builder (NOT raw PDO), cache atomic writes, migration SchemaBuilder, attribute discovery |
| `waaseyaa:middleware-pipeline` | foundation (middleware types), routing, public/index.php | Session→Authorization→Route chain, HttpPipeline compilation, middleware discovery via AsMiddleware attribute, route options (_public, _permission, _role, _gate), php://input single-read |

---

## Tier 3: Subsystem Specs + MCP Retrieval

### 3.1 Subsystem Specifications

10 specs in `docs/specs/`, mined from existing plan docs. Written for AI consumption: explicit file paths, code patterns, parameter names.

| Spec file | Mined from | Content scope |
|---|---|---|
| `entity-system.md` | architecture-v2-design, cms-design | Entity types, storage drivers, _data blob, entity keys, constructor patterns, query pipeline |
| `access-control.md` | authorization-wiring-design, field-level-access-design, field-access-wiring-design | Access result semantics, policy interfaces, Gate, AccessChecker, route options |
| `field-access.md` | field-level-access-design, field-access-wiring-design | FieldAccessPolicyInterface, open-by-default, view/edit denial, x-access-restricted, paired nullables |
| `api-layer.md` | admin-spa-completion, architecture-v2-design | JSON:API controller, ResourceSerializer, QueryParser, SchemaPresenter, query operators |
| `middleware-pipeline.md` | authorization-wiring-design, laravel-integration-design | SessionMiddleware, AuthorizationMiddleware, pipeline compilation, middleware discovery |
| `package-discovery.md` | laravel-integration-design | ServiceProvider lifecycle, composer.json manifest, three compilers, attribute discovery |
| `infrastructure.md` | architecture-v2-design | DomainEvent channels, cache backends, database query builder, migration system |
| `mcp-endpoint.md` | webmcp-design | McpEndpoint, auth interface, tool registry, JSON-RPC dispatch |
| `admin-spa.md` | admin-spa-completion | Composables, schema-driven forms, SSE real-time, i18n |
| `ai-integration.md` | architecture-v2-design | Schema generation, agent execution, pipeline orchestration |

Each spec: ~200-500 lines.

### 3.2 MCP Retrieval Server

Lightweight local MCP server for Claude Code sessions. Separate from `packages/mcp/` (which is a remote MCP server for AI agents hitting Waaseyaa's API).

**Location:** `tools/spec-retrieval/` (standalone, not a Waaseyaa package)

**Tools:**

| Tool | Parameters | Returns |
|---|---|---|
| `waaseyaa_list_specs` | none | Array of {name, description, file} for all specs |
| `waaseyaa_get_spec` | `name: string` | Full markdown content of the named spec |
| `waaseyaa_search_specs` | `query: string` | Matching sections across all specs (keyword substring) |

**Implementation:** Node.js script using `@modelcontextprotocol/sdk`. Reads markdown files from `docs/specs/`. Keyword substring matching (the paper found this sufficient). ~200 lines.

**Configuration:** Added to `.claude/settings.json` as a local MCP server:
```json
{
  "mcpServers": {
    "waaseyaa-specs": {
      "command": "node",
      "args": ["tools/spec-retrieval/server.js"],
      "cwd": "."
    }
  }
}
```

---

## Maintenance & Drift Detection

### Drift Detection Script

**Location:** `tools/drift-detector.sh`

Maps recent git commits (since last check) to subsystem-to-file mappings. Outputs which specs may need updating based on which files changed.

```
$ tools/drift-detector.sh
Files changed in last 5 commits:
  packages/api/src/Controller/SchemaController.php → docs/specs/api-layer.md
  packages/access/src/EntityAccessHandler.php → docs/specs/access-control.md, docs/specs/field-access.md

⚠ 2 specs may need review.
```

### Session Discipline

After any session that changes a subsystem's behavior:
1. Update `CLAUDE.md` if gotchas changed
2. Update relevant `docs/specs/` file
3. The `revise-claude-md` skill already handles step 1; extend pattern to specs

### Estimated Maintenance Cost

- Per-session overhead: ~5 min when specs are affected
- Biweekly review pass: ~30 min
- Weekly total: 1-2 hours

---

## Repeatable Skill

After building this infrastructure, write a `codified-context` skill that applies this paper's architecture to any project:

1. Audit existing codified context (CLAUDE.md, memory files, docs)
2. Analyze codebase structure and identify domain groupings
3. Generate constitution expansion (trigger table, layer reference, checklists)
4. Create domain specialist skill templates
5. Mine existing docs for subsystem spec content
6. Scaffold MCP retrieval server

---

## Deliverables

| Deliverable | Count | Lines (est.) |
|---|---|---|
| Expanded CLAUDE.md | 1 file | ~180 lines |
| Domain specialist skills | 7 skills | ~2,100 lines |
| Subsystem specs | 10 docs | ~3,500 lines |
| MCP retrieval server | 1 tool | ~200 lines |
| Drift detection script | 1 script | ~100 lines |
| Codified-context skill | 1 skill | ~200 lines |
| **Total** | **21 artifacts** | **~6,280 lines** |

Knowledge-to-code ratio: ~8% from codified context alone. Combined with existing plan docs: ~35%.
