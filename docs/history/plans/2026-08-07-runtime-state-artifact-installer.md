# Runtime-state-safe SQLite artifact installer plan

Issue: #2288

## Work packages

1. Add red contract tests for catalogue ownership, schema incompatibility,
   runtime preservation, append-only evidence, identity merging, dangling
   account references, and unknown-table refusal.
2. Implement the versioned framework runtime-table catalogue and immutable
   report DTOs in `waaseyaa/deployer` without cross-layer imports.
3. Implement candidate preparation with PDO SQLite, transactional row movement,
   schema cloning for serving-only lazy tables, and sanitized evidence.
4. Add atomic database activation and restore with a test-only failure seam.
5. Replace Sheguiandah's framework-table allowlist and opaque database copy with
   the framework contract. Add rollback of every activated data tree.
6. Prove the result on disposable copies of the production-shaped serving and
   Stage-1 databases, then run both repositories' complete gates.

No release, staging operation, or serving-database mutation is in scope.
