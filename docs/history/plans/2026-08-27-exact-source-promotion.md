# Exact source artifact promotion plan

Anchor: Framework #2404

Parent commit: `6c3845b92f6d2716d03ffa0c19f3f3f07681b242`

## Outcome

Release Readiness consumes the immutable exact-head source artifact produced by
a successful `ci.yml` run for its validated candidate SHA. Candidate build and
browser acceptance execute from the materialized archive, not from a second
source reconstruction or the checkout used to host workflow actions.

This is evidence promotion only. It does not tag, release, split, deploy,
create an environment, or gain publication authority.

## Decisions

1. Add one repository-owned promotion command. It resolves a completed,
   successful `ci.yml` run at the exact SHA through the Actions API, requires
   one unexpired artifact with the canonical `framework-source-<sha>` name,
   downloads it, invokes the existing exact-source verifier, and records a
   deterministic handoff manifest.
2. Record source SHA, source tree, archive SHA-256 and byte count, producing
   workflow run id/URL, source artifact id/name, and promotion target. Do not
   treat GitHub artifact identity as durable authority; the archive digest and
   Git object remain the portable evidence.
3. Add one repository-owned materializer. It verifies before extraction,
   refuses an existing destination, and produces exactly one `framework/`
   source root.
4. Release Readiness gains only `actions: read`. A dedicated import job
   promotes the CI artifact into its own run-scoped artifact. Both downstream
   jobs download that same handoff and independently verify/materialize it.
5. Keep an exact-SHA checkout in each job only to supply pinned workflow actions,
   Git objects, and verifier code. Every candidate command uses the extracted
   source root explicitly.
6. Permit `scripts/build-release-candidate.sh` to receive an exact candidate SHA
   when run from an exported tree with no `.git`; preserve the existing Git
   derivation as the default local behavior.

## TDD sequence

1. Add failing script tests for a successful green-CI handoff, missing/wrong
   run evidence, expired/ambiguous artifacts, tampered bytes, deterministic
   provenance, safe extraction, and destination refusal.
2. Add failing workflow-shape tests proving `actions: read`, exact-SHA import,
   run-scoped handoff, verification in both consumers, and execution from the
   materialized root.
3. Implement the two commands and the exported-tree candidate identity seam.
4. Wire Release Readiness and update the enduring exact-source contract.
5. Run focused Architecture tests, all three suites, and full preflight on the
   exact committed candidate.

## Refusals

- no latest-success fallback across SHAs;
- no artifact-name-only lookup across workflow runs;
- no expired, duplicate, missing, malformed, or tampered handoff;
- no extraction over an existing destination;
- no build or browser command from the convenience checkout;
- no release, tag, split, deployment, or production mutation.
