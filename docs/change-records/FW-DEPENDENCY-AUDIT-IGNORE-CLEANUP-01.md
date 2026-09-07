# FW-DEPENDENCY-AUDIT-IGNORE-CLEANUP-01 — remove stale composer-dependency-analyser ignores

Forge mirror: waaseyaa/framework#2969 (cleanup ledger CL-17,
`docs/audits/cleanup-backlog.md`). Read-only audit precursor:
`waaseyaa/framework#2961` verification-cost audit session.

Status: candidate prepared, **uncommitted**. Session
`48b2ab6c-f59c-409a-9b9d-9750198d9cd8`. Worktree
`/home/fsd42/dev/waaseyaa-worktrees/fw-cleanup-cl16-cl17`, branch
`chore/cl17-dependency-audit-stale-ignores`, base HEAD
`b7d83958c184d30180b0df55010a49c9dce9cc34`.

## Problem

`bin/audit-composer-deps` runs `shipmonk/composer-dependency-analyser`
(installed `1.8.4`) against `composer-dependency-analyser.php` as a warn-only
CI job (always exits 0). Alongside its live findings it reports a
`Some ignored issues never occurred:` section — the tool's own signal that a
configured suppression no longer matches any condition it was written to
silence. Eleven entries were in that state. A stale suppression hides
nothing (nothing to hide), but it degrades the config as documentation: a
future reader cannot tell a live guard from dead weight.

## Method

Per-ignore obsolescence was established from **AST-usage evidence** (grep
proxies for the same signal `shipmonk/composer-dependency-analyser` computes:
`use`-statement usage split by the tool's own `isDev` path partition — every
`packages/<pkg>/src` is `isDev:false`, every `packages/<pkg>/tests`,
`packages/<pkg>/testing`, root `tests/` and root `tools/` are `isDev:true`,
per `composer-dependency-analyser.php:26-51`), not by trusting the "never
applied" line alone. Each of the 11 was individually reconciled against the
**current, unedited** baseline run before any edit was made.

## Per-ignore justification

| # | Ignore removed | Original ErrorType | Why it is obsolete now |
|---|---|---|---|
| 1 | `waaseyaa/analytics` | `UNUSED_DEPENDENCY` | Package has zero cross-package usage and zero *internal* `use`-import usage in its own `isDev:false` `src/` (its own `src/` files only *declare* `namespace Waaseyaa\Analytics;`, never `use` a sibling class). Real usage exists only in `packages/analytics/tests/UmamiClientTest.php` (`isDev:true`). Non-zero usage confined to `isDev:true` paths reclassifies the finding as `PROD_DEPENDENCY_ONLY_IN_DEV` (now in the live "Found 6 prod dependencies used only in dev paths" list), a **different ErrorType** the `UNUSED_DEPENDENCY` ignore block cannot and does not suppress. |
| 2 | `waaseyaa/deployer` | `UNUSED_DEPENDENCY` | Identical mechanism to #1: `packages/deployer/src/*.php` contains zero internal `use Waaseyaa\Deployer\…` statements; real usage lives only in `packages/deployer/tests/**` (`isDev:true`, e.g. `SqliteArtifactPreparerTest.php`). Now `PROD_DEPENDENCY_ONLY_IN_DEV`. |
| 3 | `waaseyaa/engagement` | `UNUSED_DEPENDENCY` | Declared under root `require-dev` (not `require`), so `UNUSED_DEPENDENCY`/`PROD_DEPENDENCY_ONLY_IN_DEV` cannot apply to it by definition — those error types are about `require` packages. Its only apparent "production" hit, a `{@see \Waaseyaa\Engagement\EngagementAccessPolicy}` mention in `packages/relationship/src/RelationshipEndpointVisibilityPolicy.php:48`, is a PHPDoc comment, not code the AST-based analyser counts. Real code usage is confined to its own `tests/` and root `tests/` (`isDev:true`) — the fully compliant state for a `require-dev` package. No finding of any kind fires for it now. |
| 4 | `waaseyaa/github` | `UNUSED_DEPENDENCY` | Identical mechanism to #1/#2: `packages/github/src/*.php` has zero internal `use Waaseyaa\GitHub\…`; real usage is confined to `packages/github/tests/**` (`isDev:true`, e.g. `GitHubClientTest.php`). Now `PROD_DEPENDENCY_ONLY_IN_DEV`. |
| 5 | `symfony/dependency-injection` | `SHADOW_DEPENDENCY` | Zero references to `Symfony\Component\DependencyInjection\*` anywhere in any scanned path (`isDev:true` or `isDev:false`), and the package is declared in neither root `require` nor `require-dev`. `SHADOW_DEPENDENCY` requires real usage of an undeclared-but-transitively-available package to fire; with zero usage there is nothing left to suppress. |
| 6 | `symfony/dotenv` | `PROD_DEPENDENCY_ONLY_IN_DEV` | Now has a genuine `isDev:false` usage outside its own scope (1 external hit outside any package's own `src/`), so it is correctly and fully used as a `require` dependency. No finding fires. |
| 7 | `waaseyaa/groups` | `PROD_DEPENDENCY_ONLY_IN_DEV` | 8 external `isDev:false` hits (cross-package production usage), fully compliant `require` dependency. No finding fires. |
| 8 | `waaseyaa/messaging` | `PROD_DEPENDENCY_ONLY_IN_DEV` | Moved to root `require-dev`, so this error type — which is specifically about a `require` package used only in dev paths — is structurally inapplicable. **Not clean**: `packages/messaging/src/MessagingServiceProvider.php` contains a genuine internal `use Waaseyaa\Messaging\EventSubscriber\ThreadParticipantBootstrapSubscriber;` in an `isDev:false`-scanned path while the package is `require-dev`, so it now correctly appears in a **different, live, unrelated** finding — "Found 8 dev dependencies in production code" (`DEV_DEPENDENCY_IN_PROD`). That finding is untouched by this change (see Residual, below) and is explicitly out of scope per #2969. |
| 9 | `waaseyaa/node` | `PROD_DEPENDENCY_ONLY_IN_DEV` | Three same-package imports under `packages/node/src` provide genuine `isDev:false` usage: `NodeServiceProvider` imports `NodeRevisionDefaultListener`, and that listener imports `Node` and `NodeType`. As with `waaseyaa/state`, the analyser counts those production-path class uses even though importer and imported class belong to the same package. There is no external AST usage; the apparent cross-package reference in `packages/access/src/Read/AuthorizationInputReader.php` is PHPDoc and is not counted. No finding fires. |
| 10 | `waaseyaa/state` | `PROD_DEPENDENCY_ONLY_IN_DEV` | `packages/state/src/ProjectionDeprecationDiagnostic.php:7` contains a genuine internal `use Waaseyaa\State\Exception\EntityProjectionWriteForbidden;` — a same-package sibling import inside the `isDev:false`-scanned `packages/state/src`. The analyser has no concept of "same package as the importer"; any `use` of a class the lockfile attributes to a given composer package, found in an `isDev:false` path, counts as production usage of that package regardless of who wrote the importing file. That single internal import is sufficient production-path evidence, so the package is fully compliant. No finding fires. (Contrast with #1/#2/#4, whose own `src/` genuinely contains **zero** such internal `use` statements — confirmed by direct grep, not inferred.) |
| 11 | `Waaseyaa\Entity\Storage\SqlEntityStorage` | unknown class | Zero class definitions and zero references anywhere in the repository (`find`/`grep`, both empty). `ignoreUnknownClasses` suppresses a finding that requires an actual unresolvable reference to exist; with none, there is nothing to fire. Its sibling entry `Waaseyaa\Database\PdoDatabase` **is** still referenced (1 file) and is retained unchanged. |

## Residual, deliberately untouched

- `waaseyaa/messaging` under `DEV_DEPENDENCY_IN_PROD` (live, real, in scope of
  a *different* future issue per #2969's explicit "not in scope" list).
- `waaseyaa/telescope` under `UNUSED_DEPENDENCY` (live; #2969 requires
  confirming against the metapackage graph before acting — untouched here).
- The other 6 `PROD_DEPENDENCY_ONLY_IN_DEV` findings (`ext-pdo_sqlite`,
  `ext-sqlite3`, `waaseyaa/ai-schema`, `waaseyaa/analytics`,
  `waaseyaa/deployer`, `waaseyaa/github` — the last three are the packages
  whose *ignore* was removed above; the *finding itself* is a live,
  unsuppressed result and is out of scope for this change).
- All 36 unknown-class findings, the 1 unknown-function finding, all 8
  shadow-dependency findings, and the remaining 7 dev-dependency-in-prod
  findings — none reference a removed ignore and none changed.

## Exact commands and timings

Environment: PHP 8.5.8 (cli), Composer 2.10.2, `shipmonk/composer-dependency-analyser`
`1.8.4`, Linux `DESKTOP-V3430N0` `6.18.33.2-microsoft-standard-WSL2`.

```
$ /usr/bin/time -v bash bin/audit-composer-deps      # BASELINE, config unedited (HEAD b7d83958c)
exit=0
User time (seconds): 1.44   System time (seconds): 0.27
Elapsed (wall clock): 0:01.95   Maximum resident set size: 66512 kB
Minor page faults: 11484   Voluntary context switches: 206
(scanned 5256 files in 1.825 s)

$ /usr/bin/time -v bash bin/audit-composer-deps      # AFTER, 11 ignores removed
exit=0
User time (seconds): 1.29   System time (seconds): 0.14
Elapsed (wall clock): 0:01.60   Maximum resident set size: 66272 kB
Minor page faults: 11536   Voluntary context switches: 109
(scanned 5256 files in 1.522 s)
```

Both runs scanned the identical 5256 files. The elapsed/CPU/RSS deltas
from this single before/after sample do not establish a performance change;
there is no code path in the config edit that touches scan scope or file
count. The portable evidence retained
in this record is the complete normalized diff below, together with both scan
timings and unchanged category totals. The original raw outputs were written
to session-scoped `/tmp` scratch and are no longer present; the Claude session
transcript `48b2ab6c-f59c-409a-9b9d-9750198d9cd8` retains the
full baseline output and full before/after diff, but is not part of this
candidate.

## Normalized finding comparison (not just counts)

ANSI-stripped, byte-level `diff -u` of the full tool output, baseline vs
after. The entire diff is reproduced here in full — nothing elided:

```diff
@@ -196,21 +196,7 @@

   • waaseyaa/telescope

-
-Some ignored issues never occurred:
- • Error 'unused-dependency' was ignored for package 'waaseyaa/analytics', but it was never applied.
- • Error 'unused-dependency' was ignored for package 'waaseyaa/deployer', but it was never applied.
- • Error 'unused-dependency' was ignored for package 'waaseyaa/engagement', but it was never applied.
- • Error 'unused-dependency' was ignored for package 'waaseyaa/github', but it was never applied.
- • Error 'shadow-dependency' was ignored for package 'symfony/dependency-injection', but it was never applied.
- • Error 'prod-dependency-only-in-dev' was ignored for package 'symfony/dotenv', but it was never applied.
- • Error 'prod-dependency-only-in-dev' was ignored for package 'waaseyaa/groups', but it was never applied.
- • Error 'prod-dependency-only-in-dev' was ignored for package 'waaseyaa/messaging', but it was never applied.
- • Error 'prod-dependency-only-in-dev' was ignored for package 'waaseyaa/node', but it was never applied.
- • Error 'prod-dependency-only-in-dev' was ignored for package 'waaseyaa/state', but it was never applied.
- • Unknown class 'Waaseyaa\Entity\Storage\SqlEntityStorage' was ignored, but it was never applied.
-
-(scanned 5256 files in 1.825 s)
+(scanned 5256 files in 1.522 s)


 Composer-dependency audit reported findings (exit=1). Review above; this gate is warn-only.
```

Every line above line 196 of both outputs — the complete live finding set —
is byte-for-byte identical, verified by `diff -u` over the full ANSI-stripped
output, not a re-derived summary.

## Unchanged live totals

| Category | Baseline | After |
|---|---|---|
| Unknown classes | 36 | 36 |
| Unknown functions | 1 | 1 |
| Shadow dependencies | 8 | 8 |
| Dev dependencies in production code | 8 | 8 |
| Prod dependencies used only in dev paths | 6 | 6 |
| Unused dependencies | 1 | 1 |
| **"never occurred" ignore lines** | **11** | **0** |

Exit status: `0` (warn-only wrapper) at both revisions; the analyser's own
process exit was `1` (findings present) at both revisions — unchanged.

## Scope discipline

Changed: `composer-dependency-analyser.php` only (11 array-entry removals
across 4 `ignoreErrorsOnPackages`/`ignoreUnknownClasses` calls; the
`UNUSED_DEPENDENCY` block, now empty, is removed in full rather than left as
a no-op `ignoreErrorsOnPackages([], …)` call).

Not changed, not attempted: no dependency add/remove/require-move in any
`composer.json`; no code; no CL-15 (`getValues()`) work; no other open PR
(#2966, #2964, #2970) touched; the job stays warn-only, outside
`composer verify`, per #2969.

## Verification performed this session

`bash bin/audit-composer-deps` run twice (baseline, after) as authorized —
no test suite, no dependency install, no analyzer re-run beyond those two.
Syntax/diff/changelog validation (not the analyzer, not a suite) is the only
remaining check before handoff, per the authorizing instruction.
