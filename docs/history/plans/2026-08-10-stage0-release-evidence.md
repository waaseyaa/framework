# Stage 0 framework release evidence implementation plan

Issue: #2336
Work package: S0-FW-01
Starting SHA: `85b5e1b5b80f9f8dc347c1e3286cd42d989bf47f`

1. Add architecture regressions that require all external workflow actions to
   be SHA-pinned, validate deterministic complete SBOM/provenance output, and
   require release attachment wiring. Retain the initial failing result.
2. Implement `bin/generate-release-evidence` as an offline, deterministic
   lock/manifests/split-record compiler with fail-closed validation.
3. Classify every external workflow-action occurrence and pin all 12 selectors
   used by the repository to reviewed immutable release commits with readable
   version comments.
4. Extend the split and CI workflows to retain per-package split records,
   assemble evidence, upload a pull-request dry run, and attach the complete
   evidence set to the gated GitHub Release.
5. Update version-provenance documentation and `[Unreleased]`, then run the
   focused regression, Architecture and Unit suites, repository verification,
   and drift checks required by the touched surfaces.

Non-goals: tag creation, release publication, deployment, signing with an
operator identity, consumer artifact/deployed-identity attestation, lifecycle
support policy, or upgrade/rollback behavior.
