# Fresh-site golden path

<!-- Spec reviewed 2026-08-13 - Initial design for #2343. -->

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

Existing unrecognized files are never overwritten. Re-running the same inputs
is byte-identical. An ordinary publication failure rolls back every governed
target before returning. If the process or host stops mid-publication, the
next initializer run recovers the journal to the exact prior generation before
starting new work. A cleanup failure after the durable commit cannot
reinterpret the new generation as failed or roll it back.

Generator evolution is explicit. Existing files are validated against the
digests recorded by the generator that created them; a changed artifact set or
managed byte sequence requires a declared generator-version migration. The
current renderer is never used to pretend that historical output was produced
by a newer version.

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
