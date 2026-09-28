# FW-AIV-DIST-01: opt-in AI vector distribution and truthful backend selection

- Issue: `waaseyaa/framework#3140`, parent `#3137`, program `#3118`
- Findings: AIV-DIST-001 and AIV-BACKEND-001
- Base: `7a3bce52ecedbcf16084fe86924dde92d48b69e2`
- Branch: `codex/ai-vector-optin-3140`
- Authority: source, focused tests, installed-consumer proof, PostgreSQL
  qualification, independent review, and exact-head hosted CI. No release,
  tag, publication, or deployment is part of this record.

## Decisions

- Installation is optional. Framework, CLI, and full no longer require
  ai-vector at runtime.
- Activation is explicit. Only `ai.vector_enabled === true` activates the
  provider and semantic CLI commands.
- The implemented backend is named `database`. It uses `DatabaseInterface`,
  stores JSON vectors, and ranks in PHP. No pgvector implementation is claimed.
- Unsupported backend selectors fail with `[AIV-BACKEND-001]`.
- `FakeEmbeddingProvider` is development-only.

## Verification contract

- absent: ai-vector is outside the production dependency graph; semantic
  search returns 501 and no semantic command provider is active;
- installed but disabled: no bindings, listeners, secret registration, or
  semantic commands;
- installed and enabled: storage and warmer bind, lifecycle listeners register,
  and semantic commands are present;
- distribution: root, CLI, and full production manifests do not require the
  package; the fake provider is absent from production autoload;
- backend: `database` is accepted and `pgvector` is refused clearly;
- database: the production embeddings migration plus store, search, and delete
  run against a real PostgreSQL service. This does not qualify the S1 schema
  coordinator, whose contract remains SQLite-only;
- exact head: required hooks, focused tests, independent risk review, and the
  complete hosted CI profile must pass before direct fast-forward to main.

The audit remains in progress because #3141 through #3143 and the private
security sequence remain open. This slice resolves only AIV-DIST-001 and
AIV-BACKEND-001.
