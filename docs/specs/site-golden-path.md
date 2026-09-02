# Fresh-site golden path

<!-- Spec reviewed 2026-09-01 - ADR-023 / FW-SITE-BLUEPRINT-01: governed application blueprints extend waaseyaa.site v1 in place; proposal bytes are authored, while exact-digest decision and applied evidence remain separate and generated. -->

## Purpose

A fresh Waaseyaa application must make its product and architecture decisions
explicit before feature implementation, generate supported first-party
integration patterns from those decisions, and fail closed when the resulting
application bypasses declared framework boundaries.

The golden path is provider-neutral. Its authority is a versioned application
manifest, local executable commands, portable Git revision identities, Composer
provenance, and generated tests. A forge or hosted CI system may execute those
commands, but GitHub, GitLab, Forgejo, Gitea, or any other provider is an
adapter, never part of the application contract.

## Authoritative artifacts

The Layer 0 `waaseyaa/site-contract` package owns the schema, typed values,
deterministic parser, canonical manifest identity, and version-disposition
policy. It has no dependency on a forge, CI provider, CLI, generator, runtime
container, recipe package, or application.

Every initialized application owns these files:

- `.waaseyaa/site.yaml`: canonical capability manifest;
- `.waaseyaa/site.schema.json`: the exact framework schema version understood
  when the manifest was last migrated;
- `.waaseyaa/generated.json`: generator version, manifest identity, managed
  artifact modes and digests, and declared extension-region identity;
- `.waaseyaa/.gitignore`: provider-neutral exclusion of the runtime lock,
  transaction journal, and recoverable staging residue;
- `AGENTS.md`: generated agent and maintainer instructions that point back to
  the manifest and executable gates;
- `tests/Architecture/SiteContractTest.php`: application contract and bypass
  checks;
- `tests/Acceptance/SiteGoldenPathTest.php`: generated behavior checks for
  active recipes; and
- `bin/maintenance/site-verify`: provider-neutral command that runs manifest,
  architecture, strict-doctor, and generated acceptance gates in canonical
  order.

`.waaseyaa/generated.json` binds every managed artifact to the generator
version and content digest that produced it. User-owned extension regions are
explicit; regeneration refuses an unrecognized edit instead of overwriting it.

## Capability manifest

The manifest has a strict versioned schema. Unknown keys, duplicate capability
identities, invalid state transitions, and unsupported schema versions fail.
Every known capability is exactly one of:

- `active`: implemented now and required to pass its recipe and verification;
- `planned`: intentionally absent from runtime, with an owner-visible note; or
- `not_needed`: intentionally excluded with a non-empty reason.

Installed packages do not imply activation. Conversely, an active capability
must declare the package/provider, configuration authority, public routes,
data classification, lifecycle operations, and verification appropriate to
that capability.

The top-level manifest binds:

- schema and generator versions;
- application identity;
- framework revision policy and observed lock digest;
- canonical origin as configuration authority, never a source literal;
- content types and canonical route templates;
- active, planned, and excluded capabilities;
- personal-data stores and their consent, retention, export, and deletion
  operations;
- selected generated recipes and their artifact digests; and
- the provider-neutral verification command.

When governed visual authoring is active, the manifest also declares the
authoritative revisionable page bundle, layout field, block/layout/template
registry, preview route owner, editorial permissions, and every enabled client
surface. `admin_spa` and `anokii` are clients of one page-builder service and
wire contract; they can never select different page stores, validators, or
publication paths.

Manifest migration is explicit and produces a reviewable diff. Runtime boot
never silently rewrites it.

### Governed application blueprints

`waaseyaa.site` v1 may contain one optional, closed
`application_blueprint` section (ADR-023). It describes a model-independent
application proposal: entities and fields, relationships, permissions and
roles, policies, workflows, fixtures, and generated behavioural checks. The
exact vocabulary and semantic constraints are owned by the Layer 0
`waaseyaa/site-contract` package; a product may not substitute a private DTO,
validator, or compiler.

The optional section does not change the normalized shape of a manifest that
omits it. Existing v1 manifests therefore render to the same bytes and retain
their existing digest. Presence of the section derives the required generator
feature token `site-application-blueprint-v1`. Generator feature tokens are a
runtime-negotiation roster separate from authored `capabilities` and recipe
capability references. An older closed parser rejects the unknown section, and
a newer parser refuses dry-run rendering or publication when the installed
generator cohort does not advertise that exact feature. No cohort may silently
ignore a blueprint.

The manifest document remains byte/digest stable when the section is absent,
but the generated `.waaseyaa/site.schema.json` necessarily changes when the
optional property is added. An initialized site takes the existing
changed-managed-bytes upgrade path: rebind
`framework.observed_lock_sha256` to the reviewed dependency lock and re-run
`site:init`. This is not the unrecoverable changed-artifact-set case. Until the
rebind, strict doctor, generated verification, and the generated architecture
test are red; today's `SITE010_GENERATED_ARTIFACT_DRIFT` wording classifies the
mismatch as substitution. #2787 owns the decision and test for distinguishing
this reviewed schema-upgrade case.

Authored YAML contains the proposal, never mutation authority. The canonical
blueprint digest covers its fixed schema id, contract version, and complete
payload; the section also participates in the full site-manifest digest. An
approval or rejection receipt is separate request evidence that binds the
decision and claimed actor identifier to both exact digests. That binding
prevents transfer after proposal/context drift; actor authenticity is only as
strong as the higher-layer decision mechanism. Only a matching approval may
enter apply. A proposal needs no approval to validate or dry-run.

Lifecycle is derived rather than trusted from an authored state field:
`proposed` has no matching decision, `approved` is the request-scoped state of a
matching approval before publication, `applied` has the canonical approval and
matching evidence in `.waaseyaa/generated.json`, `rejected` is request-scoped
unless a higher layer retains its matching rejection, and
`superseded` has applied evidence, or higher-layer retained decision evidence
supplied with the request, for different bytes. The
initializer extends the existing generated metadata and installs it last in
the existing transaction; it does not create another generated artifact,
approval authority, or transaction log. Receipt-aware rendering and strict
verification are explicit #2787 changes: current generated metadata is a pure
function of the manifest, while blueprint application makes the canonical
approval receipt a second input. Blueprint-free output remains byte-identical.
Strict verification re-derives state and fails closed on missing or mismatched
evidence.

`waaseyaa.generated` remains version 1. Its optional
`application_blueprint` evidence member is emitted only for an applied
blueprint. Older readers reject the extended closed shape; newer readers accept
both the historical exact v1 shape and the extended shape. The blueprint-free
metadata bytes remain unchanged.

AI systems are untrusted proposal producers. Provider names, prompts,
transcripts, confidence, and repair metadata remain outside the contract. A
human-authored proposal and an AI-proposed one pass through the same parser,
validator, exact-digest decision boundary, initializer, and verifier.

### Closed vocabulary (#2785)

Namespace `Waaseyaa\SiteContract\Blueprint\`. Every list is a closed mapping
(`additionalProperties: false`); every id uses the manifest's stable-id
grammar `^[a-z][a-z0-9_-]*$` except a permission id/reference, which uses
`^[a-z0-9_-]+( [a-z0-9_-]+)*$` (lowercase words, single spaces; a literal `*`
anywhere is `SITE045`, never a silent grammar failure).

- **`entities`** (min 1) — `id` (must equal an existing `content_types[].id`),
  `label`, `storage` (`BlueprintStorage`: `sql-blob` | `sql-column`, equal to
  `Waaseyaa\Entity\Storage\PrimaryStorageBackend::SQL_BLOB`/`SQL_COLUMN`),
  `revisionable`, `translatable`, `keys` (`id`, `uuid`, `label` always;
  `revision` iff `revisionable`; `langcode`/`default_langcode` iff
  `translatable`; optional `owner` naming a relationship field on this
  entity), `fields` (each: `id`, `type` — `BlueprintFieldType`, the 13
  `#[FieldType(id: ...)]` ids under `packages/field/src/Item/` minus
  `entity_reference` (owned by relationships), `file`, and `image` (media is
  out of scope) — `required`, `cardinality` (≥1 or `-1`), `translatable`,
  `revisionable`, `indexed` (requires `sql-column`), `values` (required iff
  `type: enum`, forbidden otherwise)).
- **`relationships`** — `id`, `from: {entity, field}` (the field id created on
  that entity), `to: {entity}`, `cardinality`, `required`, `on_delete`
  (`BlueprintOnDelete`: `restrict` | `nullify`).
- **`permissions`** — `id`, `title`.
- **`roles`** — `id`, `label`, `permissions` (unique refs into `permissions`).
- **`policies`** — default-deny; `id`, `entity`, `operation`
  (`BlueprintOperation`: `view` | `create` | `update` | `delete`),
  `condition` (`BlueprintConditionKind`, `kind`-dispatched closed shape):
  `permission` → `{kind, permission}`; `ownership` → `{kind, permission}`
  (requires `entity.keys.owner`, `SITE046`); `workflow_state` → `{kind,
  permission, states}` (requires the entity bound to exactly one workflow).
  No expression, script, callable, or regex condition exists.
- **`workflows`** — `id`, `label`, `initial_state`, `states` (min 1: `id`,
  `label`, `published`), `transitions` (`id`, `label`, `from` (state ids),
  `to`, `permission`), `bindings` (`{entity}`; the entity must be
  revisionable and not translatable, `SITE043`; an entity binds to at most
  one workflow across all workflows).
- **`fixtures`** — `id`, `entity`, `values` (closed to the entity's declared
  fields plus its relationship `from.field`s; a relationship value is a
  fixture id — or list, per cardinality — of the relationship's `to.entity`),
  optional `workflow_state` (only when the entity is bound).
- **`checks`** (`BlueprintCheckKind`, `kind`-dispatched): `role_permission`
  (`role`, `permission`, `expect: granted|denied`); `workflow_transition`
  (`role`, `workflow`, `transition`, `expect: allowed|denied`);
  `entity_access` (`role`, `entity`, `operation`, optional `fixture`,
  `expect: allow|deny`); `fixture_present` (`fixture`).

Authority-bearing keys (`state`, `status`, `approved`, `applied`, `approval`,
`decision`, `receipt`, `lifecycle`) are not in any closed shape at any level —
authoring one fails `SITE001_UNKNOWN_KEY`, never silently accepted or ignored.

**Canonical order.** Each id-keyed collection (`entities`, `relationships`,
`permissions`, `roles`, `policies`, `workflows`, `fixtures`, `checks`, and
`fields`/`states`/`transitions` within their parent) is emitted sorted by id.
`roles[].permissions`, `transitions[].from`, enum `values`, and
`workflow_state` condition `states` are sorted `SORT_STRING`. Optional scalars
with defaults are always emitted (`required: false`, `cardinality: 1`,
`translatable: false`, `revisionable: false`, `indexed: false`, `on_delete:
restrict`); optional keys with no default (`keys.owner`, `fixtures[].workflow_state`,
non-enum `values`, …) are omitted, never emitted as `null`. `fixtures[].values`
data (scalar lists, not structural collections) keeps authored order.

**Digest formula.** `ApplicationBlueprint::$digest` is `sha256` over the
canonical JSON of `{schema: "waaseyaa.application_blueprint",
contract_version: 1, payload: <normalized section without contract_version>}`.
It participates independently from the full site-manifest digest: a blueprint
value change moves both; a manifest-context-only change (e.g.
`application.name`) moves only the manifest digest.

**Error codes** (JSON Pointer paths rooted at `/application_blueprint`; generic
manifest codes `SITE001`/`SITE010`–`SITE012`/`SITE014`/`SITE020`/`SITE021`
apply for ordinary shape/type/duplicate failures):

| Code | When |
|---|---|
| `SITE040_BLUEPRINT_UNSUPPORTED_CONTRACT_VERSION` | `contract_version` other than `1` |
| `SITE041_BLUEPRINT_UNKNOWN_CONTENT_TYPE` | an entity id is not a `content_types[].id` |
| `SITE042_BLUEPRINT_UNRESOLVED_REFERENCE` | any dangling entity/field/permission/role/workflow/state/fixture/transition reference |
| `SITE043_BLUEPRINT_WORKFLOW_BINDING_UNSUPPORTED` | bound entity not revisionable, or translatable, or bound twice |
| `SITE044_BLUEPRINT_FIELD_PREREQUISITE` | field/key flags don't match their entity prerequisite, or `values` mismatches `type` |
| `SITE045_BLUEPRINT_WILDCARD_PERMISSION` | `*` anywhere in a permission id or reference |
| `SITE046_BLUEPRINT_OWNERSHIP_FIELD_REQUIRED` | an `ownership` condition on an entity without `keys.owner` |
| `SITE047_BLUEPRINT_UNSUPPORTED_CONDITION` | an unknown condition/check `kind` |
| `SITE050_DECISION_RECEIPT_INVALID` | a decision receipt fails its closed shape or grammar |

Structural shape/grammar/per-collection-duplicate-id checks
(`ApplicationBlueprintParser`) fail first; cross-collection semantic checks
(`ApplicationBlueprintValidator`, invoked immediately after by
`SiteManifestParser::parse()`) run once the whole section is typed.

### Decision receipt and lifecycle (#2785, ADR-023 D-4/D-5)

`Blueprint\BlueprintDecisionReceipt::fromArray()` types a closed mapping
(`schema` = `waaseyaa.blueprint_decision`, `version` = `1`, `decision`
(`BlueprintDecision`: `approved` | `rejected`), `blueprint_digest`,
`manifest_digest` (both sha256), `actor`, `decided_at` (RFC 3339 UTC,
`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$`), `mechanism`). It is request input,
not a second site file — `site-contract` defines its shape and exact-digest
matching only; a higher layer decides how one is produced, authenticated, or
retained. `matches(SiteManifest $manifest)` is true only when both digests
equal the manifest's current ones. `Blueprint\BlueprintLifecycleResolver::resolve()`
derives `BlueprintLifecycle` (`proposed` | `approved` | `rejected`) from a
manifest and an optional receipt — never trusted from YAML: no receipt, or a
non-matching one, resolves `proposed`; a matching receipt resolves its
decision; a rejection never resolves `approved`; editing any blueprint or
manifest byte after approval reverts resolution to `proposed`. `applied` and
`superseded` require `.waaseyaa/generated.json` evidence and are #2787's
initializer/doctor extension, not part of this resolver.

## Initialization

### Canonical fresh-project lifecycle

A fresh project reaches a valid, verifiable state through exactly five ordered
phases, and consumer-facing documentation names no other sequence (#2644):

1. **create** — `composer create-project waaseyaa/waaseyaa`;
2. **site contract** — `waaseyaa site:init`, which produces `.waaseyaa/site.yaml`,
   the governed artifact set, and the generated verification command;
3. **install** — `waaseyaa install:init`, which applies the reviewed migration
   catalog, synchronizes entity storage schema, and activates the configuration
   generation;
4. **verify** — `composer site-verify`; and
5. **serve**.

`install:init` is the single materialization step. It subsumes `migrate` and
`schema:sync`, and it is the only one of the three that also activates the
configuration generation, so a site materialized by `db:init` plus `migrate`
passes verification while being an invalid installation. It is idempotent, so it
is re-run after any later `site:init`. `db:init` is a database-administration
command and is not part of this lifecycle.

`site:init` precedes `install:init` because recipe-declared entity types must
exist before schema synchronization runs. Because the phases are ordered,
verification before phase 2 is a definite state rather than an error: the
verification entry reports that the project is not initialized and names
`site:init`, and it does so without booting the kernel.

`waaseyaa site:init` is both interactive and automation-safe. Interactive mode
asks product questions in plain language. Non-interactive mode accepts a
complete answer document and refuses omitted required decisions.

Initialization is transactional:

1. inspect the project and existing artifacts without writing;
2. validate the complete proposed manifest;
3. render all generated artifacts into a temporary directory;
4. run syntax, schema, and collision checks there;
5. show or emit the proposed changes; and
6. acquire the project initialization lock and publish through a durable
   transaction journal, installing `.waaseyaa/generated.json` last and marking
   the journal committed only after every target is durable.

Host portability is explicit rather than assumed (#2644). `SiteHostPlatform` is
injected into the initializer and declares three capabilities the transaction
depends on, each of which the framework previously took for granted:

| Capability | POSIX | Windows | Consequence when absent |
|---|---|---|---|
| synchronize a directory handle | yes | no | the durability guarantee narrows to process death, not host death |
| enforce permission bits | yes | no | modes are declared, never compared |
| hard-link counts | yes | no | the aliasing clause of the private-file check is not enforced; the symlink and regular-file clauses still are |

On a host without directory synchronization the journal, the lock, and the
write-then-rename ordering are unchanged, so the transaction remains atomic and
recoverable across process death; only host-crash durability is POSIX-only. The
capability is injected rather than read inline from `DIRECTORY_SEPARATOR` so the
non-POSIX branch is exercised by the ordinary test suite on a Linux runner — an
untestable platform branch would be a claim rather than a proof — and the tests
assert that both hosts publish a byte-identical artifact set.

Existing unrecognized files are never overwritten. Re-running the same inputs
is byte-identical. An ordinary publication failure rolls back every governed
target before returning. If the process or host stops mid-publication, the
next initializer run recovers the journal to the exact prior generation before
starting new work. A cleanup failure after the durable commit cannot
reinterpret the new generation as failed or roll it back.

Generator evolution is explicit. Existing files are validated against the
digests recorded by the generator that created them, and the current renderer is
never used to pretend that historical output was produced by a newer version.
The two kinds of change are not equally recoverable, and the difference is
load-bearing (#2644):

- **A changed artifact set** — one generated file added or removed — is compared
  unconditionally, outside the manifest-digest guard, and refuses regeneration
  on every already-initialized project with no override and no migration path.
  Treat the set as frozen; a new committed file belongs in the skeleton, not in
  the generated set.
- **Changed managed bytes** of an existing artifact refuse only while the
  manifest digest is unchanged, because that is the case regeneration cannot
  distinguish from a substitution. Rebinding
  `framework.observed_lock_sha256` to the reviewed dependency lock changes the
  manifest digest, which is exactly the signal that the change is an upgrade,
  and regeneration then proceeds. That rebind is the sanctioned path, and the
  refusal message names it.

There is no generator-version migration engine. `generator_version` is read from
the project's own manifest and the framework has no way to raise it, so the
version-mismatch branch cannot fire on a framework upgrade; the manifest rebind
is what carries a project across a renderer change.

## Recipe contract

A recipe is a versioned first-party generator with four parts:

1. a manifest fragment and validation rules;
2. generated application composition using supported extension points;
3. architecture assertions that prohibit competing authorities; and
4. acceptance tests proving externally observable behavior.

Recipes may generate application code and configuration, but must not create a
private framework fork or duplicate framework-owned services.

### Published content

The first published-content recipe generates:

- bundle and field definitions;
- a registered `ListingDefinition` for pageable indexes;
- canonical detail and index routes through Path/routing authorities;
- sitemap contribution using the same canonical route resolver;
- title, description, canonical metadata, and JSON-LD integration;
- container-registered application services;
- index and detail templates; and
- access-aware listing, pagination, route, sitemap, and metadata tests.

Internal entity paths such as `/node/{id}` cannot enter the public sitemap when
a canonical application route is declared.

### Subscription

The first subscription recipe generates:

- framework-managed private storage and a tracked migration;
- container-registered repository/service boundaries;
- input validation and normalized identifiers;
- consent evidence and privacy classification;
- unsubscribe and retention/deletion lifecycle operations;
- Mail and Queue integration points that remain disabled until unsubscribe is
  proven; and
- tests showing that subscriber records and secrets are not publicly exposed.

The recipe never constructs raw PDO, performs runtime DDL, or sends mail merely
because the package is installed.

### Governed visual authoring

The governed-authoring recipe composes the existing `waaseyaa/page-builder`
capability rather than generating a site-specific builder. It generates:

- the layout field on the declared revisionable page bundle;
- an application-owned, versioned block/layout/template registry;
- semantic public renderers bound to application design tokens;
- one authenticated page-builder surface, exact-revision preview route, and
  ordinary revision/workflow persistence path;
- the generic Waaseyaa Admin SPA client and, when selected, an Anokii module
  adapter that opens the same drafts and builder workspace;
- role-scoped content inventory actions for pages and typed high-volume
  Updates, Events, Jobs, and Announcements; and
- parity, keyboard, accessibility, concurrency, preview, history, and
  restore-as-new-draft acceptance tests.

Drupal Canvas and Drupal core are the architecture benchmark for typed
content, governed components, media, revisions, moderation, and permissions.
Lovable is the interaction benchmark for direct selection against the real
preview, immediate feedback, responsive previews, and design-system
constraints. Neither product is a dependency or runtime authority.

Free-form HTML, CSS, JavaScript, arbitrary class names, and client-owned save
paths are not generated. A draft saved from Admin SPA must open byte-identically
from Anokii and vice versa.

## Strict diagnostics

`waaseyaa site:doctor --strict` emits a versioned machine-readable report and a
human summary. Exit zero means all required checks passed. Warnings can never
produce an `OK` summary in strict mode.

The doctor is read-only in the literal sense: it inspects the filesystem, never
boots the kernel, and never opens or creates the application database. This is a
governed invariant, not an incidental property of the current checks (#2644).
`ConsoleKernel` runs `site:doctor` through its boot-free command seam for that
reason — ordinary console boot materializes the database before any
restricted-discovery guard, so a booting doctor would create the zero-table
SQLite file it is meant to report on, and would diagnose a missing site contract
as an inactive configuration generation. A future check that needs kernel state
belongs in a different command.

The doctor validates:

- manifest schema and internal references;
- active recipe/provider/configuration wiring;
- generated artifact digests and approved extension regions;
- framework and Composer provenance policy;
- canonical-origin configuration and route ownership;
- CI adapter presence when the manifest declares a production application;
- privacy lifecycle completeness for every personal-data store;
- sitemap canonicality and public resolution; and
- every architecture rule below.

Machine findings have stable identifiers, severity, exact file/line evidence,
and remediation text. Suppressions are versioned, scoped to one exact finding
and source digest, justified, expiring, and rejected when unused.

## Architecture rules

Discovery is repo-wide over shipped application source and configuration, not a
hand-selected path list. Every candidate occurrence is classified exactly once
by verifier-owned taxonomy. At minimum strict mode rejects:

- raw `PDO` or `SQLite3` construction outside declared framework/offline test
  authorities;
- runtime DDL such as `CREATE TABLE`, `ALTER TABLE`, or `DROP TABLE`;
- repositories, stores, or controllers constructed in route registration;
- `$_SERVER`-derived canonical URLs;
- hardcoded production origins outside typed configuration;
- manual in-memory sorting or pagination for a declared Listing;
- active delivery for a personal-data subscription without unsubscribe;
- sitemap URLs that disagree with declared canonical public routes; and
- missing or substituted generated artifacts.

The scanner must be substitution-resistant: its query universe, taxonomy,
required mappings, and finding semantics are owned by executable framework
code and tested with count-preserving valid-class substitutions as well as
omissions.

## Generated verification

Verification has two layers. `bin/maintenance/site-verify` is *generated* by
`site:init` and does the proving. `.ci/site-verify.php` is *committed* by the
skeleton, is the entry point every adapter invokes, and exists because the
generated command does not exist until phase 2 of the lifecycle.

The committed entry is plain PHP, loads no autoloader, and boots no kernel, so
it answers correctly before `composer install` and before `site:init`. It exits
3 naming `site:init` when there is no site contract, 2 when dependencies are
absent, and otherwise delegates to the generated command through `PHP_BINARY`
and returns its status. Composer invokes it as `@php .ci/site-verify.php` so
that it runs on native Windows, where Composer cannot execute a shebang script
at all; the POSIX `.ci/site-verify` shell script execs the same file, so the
pre-init instruction cannot differ between invocation paths (#2644).

`bin/maintenance/site-verify` runs without network access after dependencies
are installed. It is itself portable PHP: it re-executes the doctor and each
acceptance test through `escapeshellarg(PHP_BINARY)` rather than relying on a
child shebang. It proves:

- manifest validation and generated-artifact integrity;
- architecture rules;
- strict doctor semantics;
- listing ordering, pagination, and access filtering;
- absolute canonical sitemap URLs with no internal-path leakage;
- SEO metadata and structured data;
- declared form validation and storage boundaries;
- unsubscribe before any delivery can be enabled; and
- wiring continuity after a framework dependency update.

A production manifest may require a CI adapter, but generated CI contains only
calls to this provider-neutral command. The initial skeleton may ship a GitHub
Actions adapter because the framework currently uses GitHub; equivalent
Forgejo/Gitea/GitLab/local runners remain conforming when they execute the same
command and preserve its evidence.

## Evidence and provenance

Verification emits a canonical JSON report binding manifest digest, generator
version, framework revision, Composer lock digest, source tree identity,
artifact digests, finding set, test commands, and result. It contains no secret
values. A forge check is useful delivery evidence but does not replace this
portable report.

## Work packages

1. **WP1, contract:** schema, typed manifest model, deterministic parser,
   validator, and migration policy.
2. **WP2, initialization:** transactional `site:init`, collision handling,
   deterministic regeneration, and generated repository instructions.
3. **WP3, doctor:** strict report semantics, complete architecture discovery,
   suppression contract, and provider-neutral verification entry point.
4. **WP4, published content:** complete Listing/Path/SEO recipe and generated
   acceptance tests.
5. **WP5, subscription:** private storage/migration/privacy/Mail/Queue recipe
   and generated acceptance tests.
6. **WP6, governed authoring:** page-builder composition, Admin SPA and optional
   Anokii client parity, exact-theme preview, and generated role-based tests.
7. **WP7, reference consumer:** clean create-project fixture, offline
   regeneration, strict verification, upgrade continuity, and CI adapters.

Each work package is one issue-traceable PR with a red boundary test before
implementation. No work package may weaken strict mode to make a fixture pass.

## Acceptance gates

- A fresh application can initialize interactively or from a complete answer
  document and receives a versioned valid manifest.
- Repeated initialization is byte-identical; partial failure leaves the target
  unchanged.
- Published-content and subscription recipes generate complete supported
  integrations and their acceptance suites.
- Governed authoring generates one shared page authority. Admin SPA and an
  enabled Anokii adapter open the same draft, preview revision, history, and
  workflow path; removing either client leaves the content model unchanged.
- A communications role can create, find, duplicate, edit, preview, revise,
  and publish pages, updates, events, jobs, and announcements without raw HTML
  or developer intervention.
- An agent following only generated `AGENTS.md` uses Listings for a pageable
  index and cannot pass strict verification after substituting raw PDO/runtime
  DDL or an internal sitemap route.
- Strict diagnostics return non-zero for missing provenance, inconsistent
  capability wiring, prohibited architecture, stale generated artifacts, and
  incomplete privacy lifecycle operations.
- A clean consumer proves create-project through provider-neutral production
  preflight without contacting or depending on a forge at runtime.

## Non-goals

- enabling every installed package;
- guessing application product decisions;
- generating unrestricted free-form application code;
- making GitHub or any hosted service a runtime authority;
- replacing application-specific design and content decisions; or
- treating documentation, passing unit tests, or a green forge badge alone as
  proof of convergence.
