# Release evidence contract

## Purpose

Every tagged framework release must retain enough first-party evidence to bind
the monorepo source revision to its dependency inventory, split-package
revisions, build inputs, and published GitHub release. Release evidence is a
gate in the release state machine: an incomplete evidence set prevents the
GitHub Release from being created.

This contract does not cut a release, sign on behalf of an operator, attest a
consumer deployment, or replace a consumer's own build manifest.

## Deterministic evidence set

`bin/generate-release-evidence` writes exactly these UTF-8 JSON/text files:

- `waaseyaa-framework.cdx.json`: CycloneDX 1.6 SBOM containing the root
  framework component, every Composer production/development lock component,
  and every admin npm lock component. Components are keyed and sorted by purl.
  Composer source/dist references and checksums and npm integrity values are
  retained when the corresponding lock supplies them; absent upstream
  identities remain named properties without a value rather than invalid
  `null` values or invented claims.
- `waaseyaa-framework-provenance.json`: source repository and SHA, tag,
  workflow/builder identity, Composer and Node tool identities, lock hashes,
  the complete package-manifest set, and the exact split SHA for every
  publishable `packages/*/composer.json` package.
- `SHA256SUMS`: hashes of both JSON evidence files, sorted by filename.

JSON object keys and component/package arrays are emitted in a stable order.
The generator accepts explicit builder/tool identities; it must not use wall
clock time, temporary paths, environment ordering, or the current branch as
evidence content. Repeating it with identical inputs must produce identical
bytes.

## Split provenance input

The tagged split matrix writes one JSON record per package after the split SHA
is computed and the remote tag push succeeds. Each record binds:

- monorepo source repository and full source SHA;
- local package prefix and Composer package name;
- split repository and full split SHA;
- release tag and workflow run identity.

The generator derives the required package set from tracked
`packages/*/composer.json` files. It refuses missing, duplicate, unexpected,
malformed, or source/tag-mismatched split records. The project skeleton is not
a subtree split and is outside this set.

## Workflow input integrity and retention

Every external `uses:` step under `.github/workflows/` must select a full
40-character commit SHA and carry a human-readable version comment. Local
actions may use a repository-relative path.

The release workflow assembles evidence only after split, monorepo-integrity,
tag-parity, require-parity, and Packagist verification succeed. The GitHub
Release job downloads the completed evidence artifact and attaches all three
files. A missing evidence artifact or file fails closed before release
publication.

The manual GitHub Release recovery workflow is subject to the same gate. An
operator must supply the successful split workflow run ID that retained the
evidence. The recovery job downloads that exact run's artifact, verifies its
checksums, and confirms that repository, source SHA, tag, and workflow run ID
match the requested release before attaching the complete set. It must never
regenerate or publish around missing retained evidence.

Pull-request CI exercises the generator with complete deterministic fixtures
and uploads the dry-run evidence. This proves format and retention wiring; it
does not claim that a release or package split occurred.

## Verification

Architecture tests must reject:

- any mutable external action reference;
- an SBOM that omits a locked Composer or npm component;
- provenance that omits a tracked split package or required identity;
- nondeterministic output for identical inputs;
- a release job that can publish without the evidence assembly dependency and
  attached files.
