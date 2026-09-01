# ADR-023 — governed application blueprints extend `waaseyaa.site` v1 in place

- **Status:** Proposed. This ADR is **Accepted on merge** of the pull request
  that introduces it.
- **Date:** 2026-09-01
- **Change record:** `FW-SITE-BLUEPRINT-01`
- **Anchor issue:** #2784 (parent program: #2783)
- **Implementation owners:** #2785–#2789
- **Related:** #2343 (fresh-site golden path), #2442 (`site:init` profiles),
  #2664 (project initialization and generated-state verification)

## Context

The Layer 0 `waaseyaa/site-contract` package already owns the strict
`waaseyaa.site` manifest, typed values, deterministic YAML parsing, canonical
JSON identity, schema-version disposition, and generated-site metadata. The
higher-layer `site:init` lifecycle validates the complete manifest, renders to
a temporary directory, checks collisions, and publishes through one durable
transaction.

That authority cannot currently describe a complete governed application.
`content_types` declares only an id and canonical route, and a recipe selection
declares only its id, version, capability, and artifact digest. There is no
canonical place to express fields, relationships, permissions, policies,
workflows, fixtures, or generated behavioural checks as one reviewable plan.

Three details of the shipped implementation constrain the decision:

1. `SiteManifestParser` rejects unknown root keys and constructs the canonical
   JSON from one normalized typed document. An older parser therefore already
   fails closed when it encounters a new top-level section; it does not silently
   preserve or discard data it does not understand.
2. `SiteManifestVersionPolicy` treats any integer other than the current schema
   version as requiring migration or as an unsupported future version. Raising
   `version` for an optional extension would force every existing v1 site
   through migration despite no change to its bytes or meaning.
3. `.waaseyaa/generated.json` is installed last and binds the generated
   generation to the manifest digest and managed artifact digests. The golden
   path freezes the managed artifact set for initialized sites, so adding a
   second generated approval or ownership file would strand existing sites.

An AI-assisted product such as Waaseyaa Studio needs this contract, but it is
only a consumer. Product convenience cannot create a second schema, parser,
compiler, approval ledger, or ownership manifest.

## Decision

### D-1 — extend v1 in place

`waaseyaa.site` v1 gains one optional, closed top-level section named
`application_blueprint`. The typed `SiteManifest`, schema, parser,
canonicalizer, version policy, recipe renderers, initializer transaction,
generated ownership metadata, and verification command remain the sole
authorities.

A v1 manifest without the section has exactly its previous normalized shape.
Parsing and rendering it must remain byte-identical, and its existing SHA-256
manifest digest must not change merely because the installed Framework knows
about blueprints.

The section is not an opaque extension bag. Its keys and nested values are
closed and versioned, and unknown input fails with stable codes and JSON Pointer
paths rooted at `/application_blueprint`.

### D-2 — capability negotiation is derived and fail-closed

Presence of `application_blueprint` derives the required generator capability
`site.application_blueprint.v1`. The token is not repeated as an authored field
inside the section; doing so would create two declarations that could drift.

The parsed `SiteManifest` exposes its derived required generator capabilities.
Before dry-run rendering or publication, `site:init` compares them with the
exact capabilities advertised by the installed parser/generator cohort. A
missing capability is a stable refusal before any artifact is rendered or
written.

An older v1 parser remains safe because its existing closed root shape rejects
the unknown section. It must never accept a blueprint-bearing manifest and
silently omit the section. Schema support and generator support are distinct:
being able to parse the section does not permit a renderer that lacks the
derived capability to apply it.

### D-3 — the manifest carries the proposal, not approval authority

The authored `application_blueprint` section contains the current proposal:
its contract version and the closed model-independent payload. It does not
contain an authoritative `approved` or `applied` boolean/state field.

The canonical blueprint identity is SHA-256 over the canonical JSON encoding
of a document containing the fixed schema id
`waaseyaa.application_blueprint`, its contract version, and its payload. The
section also participates normally in the existing full site-manifest digest.

Changing any blueprint value therefore changes both identities. Changing
context outside the section, such as application identity or the reviewed
Framework lock, changes the full manifest digest even if the blueprint digest
does not.

### D-4 — decisions bind an actor to both exact identities

Approval and rejection are explicit operations over a decision receipt, not
edits to `site.yaml`. A receipt contains at least:

- its receipt schema/version and decision (`approved` or `rejected`);
- the canonical blueprint digest;
- the complete proposed site-manifest digest;
- an attributable actor identifier;
- the decision time; and
- the decision mechanism/authority understood by the invoking application.

Only a valid approval receipt whose two digests equal the current parsed
manifest may enter apply. A proposal may validate and dry-run without a
receipt. A rejection can never be treated as approval, and approval of one
manifest context cannot authorize another.

`site-contract` owns the receipt value, canonical shape, and exact-match
validation without depending on a model provider, forge, database, HTTP
session, or product. Higher layers own how an actor authenticates and how a
decision is collected. They may strengthen the mechanism with signatures or a
durable approval service, but they may not weaken the exact-digest check.

### D-5 — lifecycle is resolved from evidence, never trusted from YAML

The current lifecycle state is a projection over the proposal, a decision
receipt, and generated evidence:

- **proposed** — the section exists and no valid decision for both current
  digests is present;
- **approved** — a matching approval receipt is present, but the current
  manifest has not yet been durably published;
- **applied** — `.waaseyaa/generated.json` records the matching blueprint and
  manifest digests, generator capability, and resulting managed generation;
- **rejected** — the current exact digest has a rejection receipt and no later
  valid approval; and
- **superseded** — retained decision or applied evidence names a different
  blueprint or manifest digest.

The existing `.waaseyaa/generated.json` gains the immutable-by-generator
blueprint decision/application evidence. No second generated file or parallel
transaction log is introduced. The initializer installs this metadata last in
the existing transaction. Strict verification derives the state again and
rejects mismatched, missing, or success-shaped evidence.

Editing `site.yaml` can create or change a proposal; it cannot manufacture a
receipt or applied generation. Editing an authored state-like field is
impossible because no such authority-bearing field exists.

### D-6 — model and product independence

The blueprint contract contains domain intent only. Provider prompts,
transcripts, token counts, confidence scores, repair attempts, and model names
remain outside it. AI output is untrusted proposal input and receives no
special validation or mutation path.

Humans, deterministic tools, and AI-assisted products all submit the same
contract to the same parser, semantic validator, approval matcher, initializer,
and verifier. Studio may explain and edit the contract and present Framework
findings, but it may not translate it into a product-private plan or compile it
itself.

### D-7 — separation requires demonstrated evidence

A companion blueprint contract may be proposed only when at least one real
boundary is demonstrated:

- a non-site consumer cannot depend on the site manifest without an invalid
  package or lifecycle dependency;
- one blueprint intentionally governs multiple independent site manifests;
- confidentiality, retention, signature, or authorization needs conflict with
  the repository-owned site-manifest lifecycle;
- an independent compatibility cadence cannot be represented through the v1
  contract version and generator capability negotiation; or
- the actual package graph demonstrates a circular dependency or ownership
  conflict.

Branding, model-provider convenience, speculative reuse, document size, or an
easier consumer implementation are not sufficient.

## Compatibility and migration

- Existing v1 manifests remain valid and byte/digest stable.
- A blueprint-bearing manifest remains schema version 1 but requires the
  derived `site.application_blueprint.v1` generator capability.
- Older strict parsers refuse the unknown section; newer parsers refuse a
  generator cohort that cannot materialize it.
- Adding the section to an initialized application is a reviewed manifest
  change. It does not authorize migration of existing entities or data.
- Blueprint evidence extends `.waaseyaa/generated.json`; it does not add a
  generated path and therefore does not trigger the frozen-artifact-set
  incompatibility.
- Any future blueprint contract version requires an explicit capability token
  and migration decision. It must not be inferred from model output.

## Consequences

- #2785 can define the closed typed payload and semantic validator without
  inventing lifecycle authority.
- #2786 must converge field-aware schema introspection on the same canonical
  field/type authority consumed by the validator.
- #2787 extends the existing initializer transaction and generated metadata;
  it cannot add a parallel compiler or transaction log.
- #2788 compiles roles, permissions, policies, and workflows through existing
  enforcement services and default-deny semantics.
- #2789 proves the complete path in a packaged fresh application, including
  old-v1 stability, exact-digest approval, refusal, idempotent replay, and
  provider-independent verification.
- Studio implementation remains blocked on those Framework contracts rather
  than carrying provisional production semantics.

## Rejected alternatives

### A separate `waaseyaa.blueprint` document

Rejected because it duplicates parsing, versioning, lifecycle, digest,
transaction, ownership, and verification authorities without a demonstrated
consumer boundary.

### Put `state: approved` in `site.yaml`

Rejected because the proposer can edit the same document. A self-declared state
does not prove who approved which bytes and creates a success-shaped bypass.

### Raise the site manifest to v2

Rejected because the extension is optional and old v1 documents retain their
exact semantics. A version bump would impose migration on every initialized
site while still needing capability negotiation for the generator.

### Treat recipe selection or model metadata as the contract

Rejected because recipe selections have no typed domain parameters and model
metadata is provider-specific, non-deterministic, and not mutation authority.

## Acceptance evidence for implementation

- Old v1 fixture rendering and digest golden tests remain byte-identical.
- An old parser and a capability-missing generator both refuse a
  blueprint-bearing manifest before writes.
- Blueprint canonicalization is byte-stable across Windows and Linux.
- Editing any proposal byte invalidates an earlier approval receipt.
- Editing context outside the proposal invalidates the receipt through the full
  manifest digest.
- Rejected and superseded proposals cannot apply.
- Applied evidence is installed last, matches the managed generation, and is
  re-derived by strict verification.
- The same valid blueprint and receipt apply idempotently without a model
  provider.

## Non-goals

This ADR does not define the full blueprint field vocabulary, implement schema
or materialization code, select an AI provider, create a Studio repository,
deploy a service, migrate existing application data, generate arbitrary code,
or authorize merge/release/deployment of later work packages.
