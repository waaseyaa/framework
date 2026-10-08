# ADR 007: Keep `database-legacy` Composer name with `Waaseyaa\Database` namespace

## Status

Accepted — documentation decision (no package rename in the alpha → stable window).

## Context

The directory and Composer package are named `database-legacy`, but the PHP namespace is `Waaseyaa\Database` (not `Waaseyaa\DatabaseLegacy`). This confuses new contributors and reads like “deprecated” even though the package is the **supported** DBAL-backed persistence seam for the monorepo.

Renaming the Composer package to `waaseyaa/database` would:

- Break every `composer.json` constraint, split-repo mirrors, and consumer apps.
- Collide conceptually with any future higher-level “database service” naming.
- Require a coordinated major release with no incremental value for runtime behavior.

## Decision

**Do not rename** the Composer package or directory in the current roadmap. Treat the `-legacy` suffix as historical (Drupal-era extraction lineage), not a deprecation signal.

Canonical references:

- Composer: `waaseyaa/database-legacy`
- Namespace: `Waaseyaa\Database\` (see `AGENTS.md` gotcha and `packages/database-legacy/composer.json` autoload).

Public prose (READMEs, onboarding) should say **“Waaseyaa Database (package `waaseyaa/database-legacy`)"** once per doc where it matters, then use `Waaseyaa\Database` in code samples.

## Consequences

- Agents and humans must read `composer.json` autoload for the true namespace.
- A future **major** could still introduce `waaseyaa/database` as a metapackage alias or split layers — out of scope until a driving requirement exists.

## Links

- [`docs/specs/infrastructure.md`](../specs/infrastructure.md) — package table
- [`AGENTS.md`](../../AGENTS.md) — Architecture gotchas (`database-legacy` namespace)
