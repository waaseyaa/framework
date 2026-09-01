# FW-SITE-BLUEPRINT-01 — governed application blueprints through the canonical site contract

Status: design candidate

Anchor mirror: waaseyaa/framework#2783

Decision mirror: waaseyaa/framework#2784

Parent candidate: `52d26455e7ec69825123df7f9102076f5a7eb4b7`

## Intent

Allow a human, deterministic tool, or AI-assisted product to propose one
closed, reviewable application blueprint and have Framework validate, approve,
materialize, own, and verify it through the existing `waaseyaa.site`/`site:init`
authority. Reusable Framework gaps are fixed upstream; Waaseyaa Studio does not
own a parallel schema or compiler.

## Governing decision

ADR-023 expands `waaseyaa.site` v1 in place with an optional
`application_blueprint` section. Presence derives the generator feature token
`site-application-blueprint-v1`, in a runtime roster separate from authored
site capabilities. Authored YAML contains the proposal only; approval binds a
claimed actor and decision to the exact blueprint and full manifest digests,
with authenticity limited by the higher-layer decision mechanism. Applied
evidence extends `.waaseyaa/generated.json` inside the existing transaction and
makes the canonical approval receipt an explicit second input to rendering and
strict verification.

Separation requires a demonstrated consumer or architecture boundary. Product
or model-provider convenience is not sufficient.

## Work packages

1. **FW-SITE-BLUEPRINT-01A — authority and lifecycle (#2784).** Land ADR-023,
   this portable record, and the enduring golden-path contract.
2. **FW-SITE-BLUEPRINT-01B — typed contract (#2785).** Add the optional closed
   schema, typed PHP values, canonical identity, semantic validation, stable
   findings, and positive/negative fixtures while preserving old-v1 bytes.
3. **FW-SITE-BLUEPRINT-01C — schema authority (#2786).** Converge entity field
   and blueprint introspection on one canonical field/type authority.
4. **FW-SITE-BLUEPRINT-01D — transactional compilation (#2787).** Extend
   `site:init` dry-run/apply, exact-digest decisions, generated ownership,
   recovery, collision refusal, and idempotent replay.
5. **FW-SITE-BLUEPRINT-01E — governance (#2788).** Compile permissions, roles,
   policies, workflows, and transitions through existing default-deny runtime
   enforcement.
6. **FW-SITE-BLUEPRINT-01F — packaged proof (#2789).** Prove a governance-rich
   reference application from a clean packaged install without a model
   provider or hidden manual repair.

Each implementation work package is one review candidate with its own failing
boundary test, change fragment, exact verification evidence, and explicit spec
review. No package may claim a later work package's acceptance.

## Invariants

- `site-contract` and `site:init` remain the only schema and generation
  authorities.
- Existing v1 manifests without a blueprint remain valid and byte/digest
  stable.
- Unknown or unsupported input fails closed before writes with stable codes and
  JSON Pointer paths.
- Approval matches both the exact canonical blueprint digest and the complete
  proposed manifest digest.
- Authored state cannot impersonate approval or application evidence.
- Dry-run, collision refusal, atomic publication/recovery, generated ownership,
  and verification extend existing machinery rather than being wrapped.
- Validation and materialization require no model provider.
- Access defaults to deny and is proven through real Framework policy and
  workflow composition.

## Explicit exclusions

- arbitrary executable code, prompts, shell commands, Composer dependencies,
  secrets, deployment, DNS, and existing-data migration;
- a Studio-owned schema, validator, compiler, transaction log, or ownership
  manifest; and
- merge, release, deployment, production mutation, or a beta claim merely from
  completing an individual work package.

## Required program evidence

- a closed governance-rich reference blueprint and focused invalid fixtures;
- cross-platform canonicalization and stable finding codes;
- exact-digest approve/reject/supersede tests;
- transactional dry-run/apply, rollback, recovery, and idempotent replay;
- packaged entity, relationship, API, permission, policy, workflow, fixture,
  and generated-test acceptance; and
- provider-portability measurement only after the provider-independent path is
  green.

## Review-candidate identity and evidence

The parent is recorded above. The candidate is the Git commit containing this
record; a commit cannot embed its own SHA without changing that SHA. Git history
is the portable identity, and the current GitHub adapter mirrors it in PR #2798.

For work package 01A, run `git diff --check`,
`php bin/check-changelog-shape`, `bash tools/drift-detector.sh origin/main`, and
the governed Linux CI/preflight roster. Environment-limited local results do
not replace exact-head hosted evidence.
