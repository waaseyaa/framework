# Exact source artifact

Framework CI produces one deterministic source archive for the exact commit it
checks. The archive is evidence, not a deployment or publication authority.

## Producer contract

`bin/build-exact-source-artifact <output-dir> [<exact-sha>]` refuses a checkout
whose `HEAD` differs from the requested SHA. It writes a Git-generated tar and
a schema-v1 manifest containing the commit, tree, SHA-256, and byte count. It
will not overwrite an existing artifact pair.

CI uploads the pair as `framework-source-<exact SHA>`. The artifact name,
manifest identity, archive digest, and regenerated Git archive all bind the
same commit. Pull requests may produce an artifact for their synthetic merge
commit; only canonical-main or an explicitly gated release-cut commit is
eligible for later promotion.

## Consumer contract

`bin/verify-exact-source-artifact <artifact-dir> <exact-sha>` fails closed for
a missing file, malformed manifest, wrong commit or tree, byte-count mismatch,
digest mismatch, or archive bytes that do not reproduce `git archive` for the
expected commit.

This slice establishes the reusable producer and verifier. Release Readiness
does not consume the artifact yet, and no deployment or publication path may
claim immutable promotion until that cross-workflow handoff is implemented and
tested separately.

