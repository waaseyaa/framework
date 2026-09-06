# FW-SITE-BLUEPRINT-01 — slice 01D-2 execution plan

Status: implementation design, subject to exact-candidate adversarial review.
Authority: [FW-SITE-BLUEPRINT-01](../change-records/FW-SITE-BLUEPRINT-01.md),
ADR-023 D-4/D-5 and ADR-025 D-13. This composes the 01D-1 compiler; it does
not qualify or activate any other inventoried generator.

## Outcome and boundary

An approved application blueprint can enter the existing root-unit site
initializer through `site:init`, retaining approval evidence in the existing
generated metadata. Strict doctor verifies the resulting artifacts and
approval from the same authority. Pure compilation remains receipt-free.
Fixture seeding and governance emitters are separate work packages.

The engine admits exactly the existing `SiteArtifactRenderer` and
`Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler` for additive
managed root `site` plans. No seeded compiler or other additive compiler is
enabled. There is one initializer, metadata writer, journal, lock, collision
policy and containment policy. The compiler is never a recipe registered in
the legacy renderer factory. The root remains implicit in the v1 metadata;
it acquires no `units[]` row or persisted compiler FQCN.

## Request-scoped approval

`SiteInitializationService::evaluate()` and `apply()` accept an optional typed
`BlueprintDecisionReceipt` as a per-call input. The existing `initialize()`
entry accepts a compiled `ArtifactPlan` in addition to its legacy
`GeneratedSite`, and threads the same optional receipt through its existing
evaluation and apply paths. Neither `ArtifactPlan` nor `ArtifactApplyRequest`
changes shape. No mutable approval state is stored on the service.

A pure admission helper reparses the blueprint plan's own
`.waaseyaa/site.yaml` artifact, checks its manifest digest and generator
version against the declared plan, and requires a structurally valid Approved
receipt matching both blueprint and manifest digests. Typed receipts are
normalized through the existing receipt parser because the public constructor
does not enforce its parser's constraints. This check runs before lock creation
or journal recovery, and again inside the existing preparation under lock.
Missing, rejected, mismatched or invalid engine approval is
`GEN011_UNAUTHORIZED_SET_DELTA`, including for frozen blueprint plans. It must
leave the project unchanged. The CLI normalizes malformed receipt documents
to `SITE050_DECISION_RECEIPT_INVALID` before entering the engine.

The engine also inspects a supplied legacy root manifest for blueprint
content. A blueprint cannot use the already-eligible legacy generator FQCN
to bypass approval. A blueprint compiler without a blueprint also refuses.
This does not impose new receipt requirements on blueprint-free root plans.

The CLI reads and parses the decision file once per invocation; the resulting
immutable value is used throughout preview and controlled publication. A
different valid approval B supplied on a later apply after preview with A is
fresh authorization of the same exact manifest. The closed request does not
promise that approval bytes match an earlier invocation. Metadata and change
receipts record B. Binding preview to a particular approval across invocations
would require a separately reviewed versioned request contract.

The decision receipt's content identity is SHA-256 of its existing
`canonicalJson()` bytes, without an added newline. Expose this derivation once
as `BlueprintDecisionReceipt::digest()`; do not add a field to the serialized
receipt schema. Blueprint terminal change receipts use that value as
`decision_receipt_id`. An explicit conflicting caller-supplied decision ID is
refused, never silently attributed. An invalid/unparseable receipt has no
trusted identity. Recovery/residue receipts do not inherit the new request's
decision ID: without a versioned journal binding, the interrupted operation's
approval cannot be inferred. The terminal new apply still records its own
validated decision. Existing non-blueprint receipt context remains compatible.

## Applied evidence and root transitions

Define the pure typed `BlueprintAppliedEvidence` in `site-contract` with one
closed mapping: `{generator_feature, decision_receipt}`. The feature equals
`ApplicationBlueprint::GENERATOR_FEATURE`; the nested receipt is the existing
canonical closed approval receipt mapping. Reject unknown fields, wrong
feature, rejection and malformed receipt. Its `matches(SiteManifest)` checks
the two embedded receipt digests. The engine and doctor share this parser;
the value owns no filesystem IO or authentication mechanism.

Only the existing engine composes the optional top-level
`application_blueprint` metadata member. It is absent for blueprint-free
output, preserving the old canonical v1 bytes. The existing transaction
installs composed metadata last and rolls evidence back with all other files.
Metadata reading accepts exactly the old v1 shape or the closed extended v1
shape. Prior effective root identity is inferred from validated applied
evidence and the prior manifest, never from an authored status flag.

Allowed transitions are a fresh approved blueprint root, approved
manifest-to-blueprint additive growth, and approved blueprint replay/additive
evolution. The approved manifest-to-blueprint transition exempts only the
compiler FQCN difference: the recorded generator version and disposition must
remain unchanged. A generator-version change still refuses
`GEN010_UNIT_PATH_CONFLICT` in preview and apply, without publishing artifacts
or approval evidence; approval is not generator-version migration authority.
Existing path-drop and registration rules still apply.
Blueprint-to-plain is always GEN011, even if a fixture's paths happen to match,
because it would erase approval provenance. A matching alternative approved
receipt changes only evidence as appropriate; exact replay is `no_changes`.
Non-root updates preserve validated root evidence.

## Doctor and lifecycle

Doctor reads the canonical approval from generated metadata, validates exact
bindings, recompiles via the blueprint compiler and verifies the complete root
artifact and registration projection using the existing ownership authority.
Missing, malformed, rejected, mismatched or success-shaped evidence cannot
produce a successful strict report. Schema artifact drift retains the existing
SITE010 family and the explicit dependency-lock rebind path; a changed blueprint
manifest requires a new matching approval. Neither schema drift nor invalid
applied evidence is repaired by strict verification. A plain manifest with
unexpected blueprint evidence also fails strict verification.

Extend `BlueprintLifecycleResolver::resolve()` with optional typed applied
evidence, preserving existing two-argument callers. Matching durable evidence
projects Applied; otherwise a current matching request receipt projects
Approved/Rejected; otherwise valid prior applied evidence projects Superseded;
otherwise Proposed. A request-scoped rejection does not retroactively erase a
matching applied generation. Invalid evidence is rejected by the parser and
must not be passed to the resolver as proof.

## Process contract

Keep the existing process entry point:
`site:init --json --answers <manifest> --decision-receipt <file> [--dry-run] [--yes]`.
Negotiate the chosen compiler's features before execution. Blueprint dry-run
requires approval; pure `compile()` remains the approval-free proposal surface.
This slice adds no alternate process-level compiler protocol.

Commit fixtures for planned, applied, no_changes, GEN011 missing/rejected/
mismatched approval, and SITE050 malformed receipt. Machine JSON must preserve
literal string bytes such as Symfony-style angle-bracket text. Preserve the
existing JSON success envelope and explicit error codes; do not embed a
human-output normalization layer. `site:doctor --strict --format=json` remains
the verification process boundary. Approval collection/authentication remains
the caller's responsibility under ADR-023; this implementation must not imply
that a local unsigned receipt authenticates its actor.

## Test-first implementation sequence

1. Receipt identity and closed applied-evidence value/parser; lifecycle tests.
2. Engine tests for pre-lock refusal and declared-generator bypass; add the
   exact per-call gate, then root metadata and transition tests/implementation.
3. CLI decision input and error fixtures; real compiler publication, replay,
   registration and strict-doctor behavior in temporary consumer projects.
4. Architecture proof for all six D-13 obligations, frozen compiler inventory,
   no second authority and exact old blueprint-free metadata fixture bytes.
5. Adversarial review of receipt attribution, read/apply races, recovery,
   metadata forgery and compiler pairing; repair with regression evidence.
6. Integrate accepted WP-A and current main, then qualify the exact final
   candidate through default `bin/qualify-candidate --jobs=1` and required
   hosted checks. Use the coordinated single local heavy lane. Codex reviews
   before governed squash merge; no release or deployment follows implicitly.

## D-13 acceptance evidence required at review

1. Engine-owned eligibility checked against declared generator in every entry.
2. The closed additive list adds exactly the blueprint root compiler.
3. Valid matching Approved receipt is required before any new mutation.
4. Managed root `site`, implicit provenance and existing transaction retained.
5. GEN011 for unapproved/ineligible plans in both dry-run and apply.
6. Architecture inventory proves legacy renderer remains eligible and every
   other inventoried compiler remains frozen.

The candidate's review receipt must identify actual tests and code supporting
each item; this design text is not itself acceptance evidence.
