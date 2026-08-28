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

`bin/promote-exact-source-artifact <exact-sha> <output-dir> <target>` imports
the artifact only from a completed, successful `ci.yml` run whose head is the
exact requested SHA. It requires exactly one unexpired artifact with the
canonical name, verifies its bytes, and adds a handoff manifest binding the
producer run and artifact identities, portable source identity, and promotion
target.

`bin/materialize-exact-source-artifact <artifact-dir> <exact-sha>
<destination>` verifies before extracting, refuses an existing destination,
and materializes exactly one `framework/` root. Release Readiness independently
materializes the run-scoped promoted artifact for candidate build and browser
acceptance. Candidate commands execute from those extracted bytes; the checkout
exists only to host pinned actions, verification code, and the expected Git
objects.

Promotion remains evidence transport. It grants no deployment, tagging,
publication, split, or release authority.
