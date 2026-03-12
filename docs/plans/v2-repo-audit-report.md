# Waaseyaa v2.0 Repo Audit Report

**Date:** 2026-03-11
**Scope:** Full monorepo audit for greenfield rewrite to PHP 8.4+

---

## 1. Executive Summary

| Metric | Value |
|--------|-------|
| Total PHP source files | ~3,892 (non-test) |
| Total test files | ~440 |
| Packages | 41 (29 feature + 3 meta + 1 SPA + 8 infrastructure) |
| Layers | 7 (Foundation → Interfaces) |
| PHP version | Currently `>=8.3`, target `^8.4` |
| External deps | Symfony 7.x, Doctrine DBAL, PHPUnit 10.5, PHPStan 1.10 |
| strict_types compliance | 100% |
| Deprecated markers | 0 |
| TODO/FIXME/HACK | 0 |

**Overall assessment:** Clean, well-structured monorepo with strong architectural discipline. The codebase is ready for a PHP 8.4 upgrade with focused refactoring of complexity hotspots.

---

## 2. Package Inventory

### Layer 0 — Foundation (10 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| foundation | 63 (14I, 5A, 44C) | 42 | **High** | HttpKernel (2262L), ConsoleKernel (413L), AbstractKernel (415L) — boot orchestrators |
| cache | ~20 | 10 | Low | Backend-agnostic (Memory, File, Redis) |
| plugin | ~20 | 7 | Low | Plugin discovery and extension points |
| typed-data | ~20 | 7 | Low | Strongly-typed data containers |
| database-legacy | ~20 | 7 | **Medium** | PDO query builder — name suggests legacy, evaluate merge/replace |
| testing | ~5 | 4 | Low | Test utilities |
| i18n | ~5 | 4 | Low | Internationalization |
| queue | ~20 | 15 | Low | Message queue abstraction |
| state | ~10 | 2 | **Medium** | State machine — 2 tests only |
| validation | ~20 | 7 | Low | Rule-based validation |

### Layer 1 — Core Data (6 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| entity | 26 (12I, 3A, 11C) | 14 | Low | Core entity type system |
| entity-storage | ~20 | 13 | Low | SQL + in-memory storage |
| field | ~15 | 10 | Low | Field definitions |
| config | 20 (5I, 15C) | 13 | Low | Runtime configuration |
| access | ~20 | 10 | Low | Access policies + gates |
| user | ~20 | 10 | Low | User entity + auth |

### Layer 2 — Content Types (7 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| node | ~10 | 4 | **Medium** | Primary CMS content — low test count |
| taxonomy | ~10 | 4 | **Medium** | Low test count |
| media | ~10 | 7 | Low | Media assets |
| menu | ~10 | 5 | Low | Navigation |
| path | ~10 | 5 | Low | URL aliasing |
| note | ~7 | 5 | Low | Internal notes |
| relationship | ~10 | 2 | **High** | 579L + 539L services, only 2 tests |

### Layer 3 — Services (2 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| workflows | ~15 | 12 | Low | Editorial workflows |
| search | ~5 | 3 | **Medium** | Minimal implementation |

### Layer 4 — API (2 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| api | 15 (1I, 14C) | 30 | **Medium** | OpenApiGenerator 190L method |
| routing | ~15 | 7 | Low | Route registration |

### Layer 5 — AI (4 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| ai-schema | ~8 | 6 | Low | JSON Schema + MCP tool generation |
| ai-agent | ~8 | 7 | **Medium** | Agent framework — needs expansion for v2 |
| ai-pipeline | ~12 | 10 | Low | Step-based pipeline executor |
| ai-vector | ~18 | 13 | **High** | Needs vector DB adapters, RAG, cost controls |

### Layer 6 — Interfaces (5 packages)

| Package | Classes | Tests | Risk | Notes |
|---------|---------|-------|------|-------|
| cli | 69 (1I, 1A, 67C) | 67 | **Medium** | IngestRunCommand 791L, many long methods |
| admin | N/A (Nuxt 3) | 55 (Vitest) | Low | Vue 3 + TypeScript SPA |
| mcp | ~15 | 5 | **High** | McpController 1650L — monolithic |
| ssr | ~10 | 13 | Low | Server-side rendering |
| telescope | ~8 | 7 | Low | Debug dashboard |

---

## 3. Complexity Hotspots

### Files requiring decomposition (>300 lines)

| File | Lines | Package | Priority | Recommended Action |
|------|-------|---------|----------|-------------------|
| `foundation/src/Kernel/HttpKernel.php` | 2,262 | foundation | **P0** | Extract CORS, error handling, boot phases into separate classes |
| `mcp/src/McpController.php` | 1,650 | mcp | **P0** | Split per JSON-RPC method into handler classes |
| `cli/src/Ingestion/IngestRunCommand.php` | 791 | cli | **P1** | Extract validation, processing, reporting steps |
| `relationship/src/RelationshipDiscoveryService.php` | 579 | relationship | **P1** | Decompose into scanner + analyzer + resolver |
| `relationship/src/RelationshipTraversalService.php` | 539 | relationship | **P1** | Extract traversal strategies |
| `api/src/OpenApi/OpenApiGenerator.php` | 417 | api | **P1** | Extract 190L method into schema strategies |
| `cli/src/Ingestion/SemanticRefreshTriggerPlanner.php` | 436 | cli | **P2** | Extract planning logic |
| `foundation/src/Kernel/AbstractKernel.php` | 415 | foundation | **P2** | Extract manifest discovery |
| `foundation/src/Kernel/ConsoleKernel.php` | 413 | foundation | **P2** | Extract command registration |

### Functions >50 lines (88 total across 9 packages)

| Package | Count | Worst Offenders |
|---------|-------|----------------|
| cli | 31 | MigrateDefaultsCommand, FixtureScaffoldCommand, IngestRunCommand |
| foundation | 14 | ConsoleKernel (147L), HttpKernel (107L) |
| api | 10 | OpenApiGenerator (190L, 103L) |
| mcp | 8 | McpController (70L, 78L, 71L) |
| relationship | 5 | RelationshipDiscoveryService (98L, 82L) |
| ai-vector | 4 | SearchController (94L, 105L) |

---

## 4. Test Coverage Gaps

### Critical gaps (packages with <5 tests)

| Package | Tests | Layer | Action |
|---------|-------|-------|--------|
| relationship | 2 | L2 | **Write comprehensive tests before refactor** |
| state | 2 | L0 | Add state machine transition tests |
| node | 4 | L2 | Expand CRUD + access tests |
| taxonomy | 4 | L2 | Expand CRUD + access tests |
| search | 3 | L3 | Add indexing + query tests |
| mcp | 5 | L6 | Add protocol compliance tests |
| menu | 5 | L2 | Expand menu tree tests |
| path | 5 | L2 | Expand path resolution tests |

### Missing quality tooling

| Tool | Status | Action |
|------|--------|--------|
| PHPStan | Installed, not in CI | Add to CI pipeline |
| PHP-CS-Fixer | Not installed | Add with PSR-12 + PHP 8.4 rules |
| Code coverage reporting | Not configured | Add `--coverage-clover` to CI |
| Mutation testing (Infection) | Not installed | Optional — add for core packages |
| Security scanner | Not installed | Add `composer audit` to CI |

---

## 5. Dependency Inventory

### External PHP dependencies

| Dependency | Version | Usage | v2 Action |
|-----------|---------|-------|-----------|
| symfony/event-dispatcher | ^7.0 | Domain events | Keep |
| symfony/console | ^7.0 | CLI framework | Keep |
| symfony/routing | ^7.0 | HTTP routing | Keep |
| symfony/validator | ^7.0 | Validation | Keep |
| symfony/uid | ^7.0 | UUID generation | Keep |
| symfony/yaml | ^7.0 | Config parsing | Keep |
| symfony/messenger | ^7.0 | Queue/async | Keep |
| doctrine/dbal | ^4.0 | Database abstraction | Evaluate: replace with lighter PDO wrapper? |
| phpunit/phpunit | ^10.5 | Testing | Upgrade to ^11 for PHP 8.4 |
| phpstan/phpstan | ^1.10 | Static analysis | Keep, add to CI |

### Frontend dependencies (admin SPA)

| Dependency | Version | Usage |
|-----------|---------|-------|
| nuxt | ^3.x | Framework |
| vue | ^3.x | UI library |
| vitest | ^1.x | Unit testing |
| playwright | ^1.x | E2E testing |
| typescript | ^5.x | Type safety |

---

## 6. Interface Contract Inventory

### Core contracts (88 interfaces total)

**Entity System:**
- `EntityTypeInterface` — entity type definition
- `EntityTypeManagerInterface` — type registration/retrieval
- `EntityStorageInterface` — pluggable storage

**Access Control:**
- `AccessPolicyInterface` — entity-level access
- `FieldAccessPolicyInterface` — field-level access (intersection type)
- `AccountInterface` — user abstraction

**AI Platform:**
- `EmbeddingProviderInterface` — single embedding
- `EmbeddingInterface` — batch + dimension info
- `EmbeddingStorageInterface` — vector store CRUD
- `AgentInterface` — agent execution contract
- `PipelineStepInterface` — pipeline step plugin

**Ingestion:**
- `Envelope` — immutable ingestion value object
- `EnvelopeValidator` — validation + construction
- `PayloadValidator` — schema validation

**MCP:**
- `ToolRegistryInterface` — tool discovery
- `ToolExecutorInterface` — tool execution
- `McpAuthInterface` — bearer token auth

---

## 7. PHP 8.4 Migration Checklist

| Item | Status | Action |
|------|--------|--------|
| `composer.json` php constraint | `>=8.3` | Change to `^8.4` |
| CI matrix | PHP 8.4 | Already using 8.4 in lint job |
| Property hooks | Not used | Adopt for getter/setter patterns |
| `new` in initializers | Not used | Adopt for default values |
| Asymmetric visibility | Not used | Adopt for DTOs and value objects |
| `#[\Override]` attribute | Not used | Add to all interface implementations |
| `array_find()`, `array_any()`, `array_all()` | Not used | Replace manual loops |
| Deprecated features removed in 8.4 | None found | Clean |

---

## 8. Prioritized Cleanup Backlog

### P0 — Critical (before any new features)

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 1 | Decompose HttpKernel.php (2262L) into HttpKernel + CorsHandler + ErrorHandler + BootPhase classes | foundation | 8 | High |
| 2 | Split McpController.php (1650L) into per-method handler classes | mcp | 8 | High |
| 3 | Bump PHP to ^8.4 in all composer.json files | all | 2 | Low |
| 4 | Add PHPStan to CI pipeline | all | 2 | Low |
| 5 | Add PHP-CS-Fixer with PHP 8.4 rules | all | 3 | Low |

### P1 — High (week 1-2)

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 6 | Decompose IngestRunCommand.php (791L) | cli | 5 | Medium |
| 7 | Decompose RelationshipDiscoveryService (579L) + TraversalService (539L) | relationship | 5 | Medium |
| 8 | Extract OpenApiGenerator 190L method into schema strategies | api | 3 | Medium |
| 9 | Write tests for relationship package (currently 2) | relationship | 5 | Medium |
| 10 | Write tests for state package (currently 2) | state | 3 | Medium |
| 11 | Add `#[\Override]` attribute to all interface implementations | all | 3 | Low |
| 12 | Adopt property hooks for getter/setter patterns | all | 5 | Medium |

### P2 — Medium (week 2-4)

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 13 | Expand node/taxonomy/menu/path test coverage | content types | 5 | Low |
| 14 | Adopt asymmetric visibility for value objects | all | 3 | Low |
| 15 | Replace database-legacy with modern PDO wrapper | database-legacy | 8 | High |
| 16 | Upgrade PHPUnit to ^11 | all | 3 | Medium |
| 17 | Extract ConsoleKernel + AbstractKernel boot phases | foundation | 5 | Medium |
| 18 | Add code coverage reporting to CI | all | 2 | Low |

### P3 — AI Platform (week 4+)

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 19 | Implement Qdrant vector DB adapter | ai-vector | 5 | Medium |
| 20 | Implement Milvus vector DB adapter | ai-vector | 5 | Medium |
| 21 | Implement pgvector adapter | ai-vector | 5 | Medium |
| 22 | Implement OpenAI embedding adapter | ai-vector | 3 | Low |
| 23 | Implement Anthropic LLM adapter | ai-agent | 5 | Medium |
| 24 | Implement RAG orchestration endpoint | ai-pipeline | 8 | High |
| 25 | Add cost-control middleware (token budgets, caching) | ai-pipeline | 5 | Medium |
| 26 | Implement chunking strategies (fixed, semantic, recursive) | ai-pipeline | 5 | Medium |
| 27 | Add observability (traces, metrics) for AI flows | ai-pipeline | 5 | Medium |

### P4 — Release Readiness (week 8+)

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 28 | Write VERSIONING.md | root | 2 | Low |
| 29 | Create release checklist | root | 2 | Low |
| 30 | Create create-project skeleton | skeleton | 5 | Medium |
| 31 | Prepare Packagist publishing | all | 3 | Medium |
| 32 | Write architecture overview doc | docs | 3 | Low |
| 33 | Write Drupal 12 / Laravel 13 migration guide | docs | 5 | Medium |

---

**Total estimated story points:** ~158 SP
**Critical path:** P0 (23 SP) → P1 (29 SP) → P2 (26 SP) → P3 (46 SP) → P4 (20 SP)
