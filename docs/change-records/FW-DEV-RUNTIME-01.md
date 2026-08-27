# FW-DEV-RUNTIME-01 — first-party development runtime bootstrap

- Parent: `a9fab5067f50524bd0cc7d70149208e8231d82c1`
- Contracts: `docs/specs/operations-playbooks.md`, `docs/specs/s1-support-lifecycle.md`
- Forge mirror: Framework #2523
- Authority: repository development tooling, a content-addressed user cache,
  runtime identity evidence, and first-party consumer adoption only

## Finding

The support-contract checker already identifies PHP, Composer, Node, npm,
SQLite, OS, and architecture and correctly rejects unsupported ambient tools.
On the audited WSL2 host it refuses Composer 2.8 and Node 25. The repository,
however, provides no single repair path: contributors still remember binary
locations or edit `PATH` by hand.

The older synthetic-pack finding no longer reproduces and is not part of this
candidate. PHPUnit memory and hosted FrankenPHP execution are already owned by
#2495 and #2494.

## Decisions

1. System PHP, required extensions, SQLite, OS, and architecture remain
   prerequisites. The bootstrap never invokes `sudo` or changes system state.
2. Node, Composer, and FrankenPHP are exact, SHA-256-pinned artifacts installed
   into a manifest-addressed user cache. No global `PATH` or shell profile is
   changed.
3. Downloads are verified before extraction or execution. Publication uses an
   atomic directory rename while a per-manifest advisory lock is held.
4. `bin/dev-runtime exec -- <command>` prepends only the verified cache `bin`
   directory for its child; the parent environment is unchanged.
5. System capture and manifest validation are shared with
   `bin/check-support-contract`. Local doctor evidence records WSL identity;
   hosted evidence remains the authority for the S1 Framework test point.
6. Framework ships the manifest, bootstrap, doctor, wrapper, pure library, and
   architecture tests. Sheg and Anokii adoption remain separate review
   candidates.
7. A clean consumer cannot install the root tooling through a split Composer
   package. Consumers therefore carry only the Framework-owned consumer
   launcher/library plus a source record that pins the canonical runtime files
   at one exact Framework commit and SHA-256. They do not copy managed-tool
   versions, URLs, or checksums.
8. The consumer launcher verifies its own two mirrored files, materializes the
   canonical Framework runtime source into a second content-addressed user
   cache, and delegates only after every source byte matches. The canonical
   command records and executes in the consumer checkout; the cached Framework
   source remains the tooling authority.

## Sequence

1. Retain red tests for manifest validation, prerequisite refusal, archive
   confinement, cache corruption, child-path selection, and the shared system
   capture boundary.
2. Implement the pure runtime manifest/identity boundary.
3. Implement locked, atomic bootstrap and shell-free child execution.
4. Bind the support-contract checker and governed preflight to the shared
   manifest without downloading tools in ordinary CI.
5. Verify focused tests, split suites, full preflight, and real clean-cache plus
   idempotent-bootstrap acceptance on WSL2.
6. Add the exact-commit consumer source launcher, prove malformed records,
   altered mirrors, corrupt cached source, and argument placement fail closed,
   then adopt the byte-identical launcher in Sheg and Anokii.

## Boundaries

No production runtime change, global installation, package repository change,
synthetic-pack change, ruleset mutation, release, split, or deployment is
authorized. Consumer edits are separate candidates under the same change
record and may only adopt the source-pinned development entrypoint.
