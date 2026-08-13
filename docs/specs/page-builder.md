# Governed Page Builder

Status: active design for framework issue #2344

Stability: pre-stable until the first downstream acceptance consumer passes

First acceptance consumer: Sheguiandah First Nation

## 1. Decision

Waaseyaa owns one headless page-building capability. Waaseyaa Admin SPA and
Anokii are clients of the same layout document, block registry, validation,
revision, preview, access, and concurrency contracts. Applications configure
brand tokens, templates, block availability, roles, and content bundles. They
do not create parallel page entities or synchronize between editors.

Drupal is the architectural reference: typed content, sections, regions,
blocks, media references, revisions, moderation, and permission-scoped layout
changes. Lovable is an interaction reference: select against the real preview,
edit directly, receive immediate feedback, and remain constrained by a design
system. Waaseyaa does not depend on either product.

## 2. Scope

Version 1 provides:

- a versioned, deterministic layout document;
- immutable layout and block definitions;
- a fail-closed registry;
- schema-validated block configuration;
- template and region restrictions;
- stable block instance identities;
- opaque media and entity references;
- deterministic JSON encoding and forward migrations;
- client-neutral edit commands and validation results;
- contracts used identically by Admin SPA and Anokii.

The existing revisionable content entity remains authoritative. For
Sheguiandah this is `node.page`. The layout document is stored as content on
that entity and therefore inherits entity access, field access, revision,
workflow, audit, tenancy, and optimistic-concurrency boundaries.

Version 1 does not provide arbitrary HTML, CSS, JavaScript, class names, code
generation, nested executable components, or unrestricted visual placement.

## 3. Package boundary

The new `waaseyaa/page-builder` package is a Layer 3 service. Its model and
validator depend only on lower-layer contracts. It does not render Twig, Vue,
or application templates and does not own HTTP routes.

- `page-builder` owns documents, definitions, registry, validation, migration,
  deterministic encoding, and edit commands.
- `admin-surface` exposes the authenticated HTTP surface and the generic Admin
  SPA client.
- Anokii consumes the same service and wire contract while applying a
  Nation-specific shell and labels.
- Applications own semantic renderers, public templates, brand tokens, and the
  authenticated unpublished-preview route.
- `publishing` supplies signed preview grants and revision operations.
- `listing` supplies dynamic collection results referenced by listing blocks.

GitHub is not a package, runtime, deployment, storage, evidence, or recovery
dependency. It is only the repository's current collaboration adapter.

## 4. Canonical layout document

The document is an immutable value with this logical shape:

```json
{
  "schema": "waaseyaa.layout",
  "version": 1,
  "template": {"id": "standard", "version": 1},
  "sections": [
    {
      "id": "sec_01J...",
      "layout": {"id": "one_column", "version": 1},
      "regions": {
        "main": [
          {
            "id": "blk_01J...",
            "type": "rich_text",
            "version": 1,
            "config": {"html": "<p>Welcome</p>"}
          }
        ]
      }
    }
  ]
}
```

Rules:

1. `schema` is exactly `waaseyaa.layout`.
2. `version` is a supported positive integer.
3. Template, layout, and block references carry explicit definition versions.
4. Section and block IDs are stable opaque identifiers unique within the
   document. Reordering does not replace them.
5. Regions are declared by the selected layout. Unknown or missing required
   regions fail validation.
6. Block configuration is JSON data and must validate against the registered
   block schema. Executable values are forbidden.
7. References use typed opaque identities, never display labels or paths as
   authority.
8. Object keys are encoded in canonical lexical order while section and block
   arrays preserve editorial order.
9. Unknown fields fail closed. A newer unsupported document version cannot be
   opened as editable content.
10. A migration creates a new document value. It never mutates retained
    revisions in place.

## 5. Definitions and registry

### 5.1 Block definition

A block definition declares:

- stable type ID and positive version;
- operator label and description keys;
- JSON Schema for configuration;
- semantic renderer key;
- optional allowed layout and region IDs;
- required permissions and reference capabilities;
- cache metadata declaration;
- accessibility requirements;
- migration from each supported predecessor version.

The renderer key is an application-facing semantic identity, not a PHP class,
template path, or JavaScript module path. Applications bind it to a renderer.

### 5.2 Layout definition

A layout definition declares a stable ID/version, ordered named regions,
required regions, responsive constraints, and allowed block types. It cannot
contain presentation code.

### 5.3 Template definition

A template definition declares allowed layouts, blocks, regions, immutable
regions, default sections, and whether a page may override the default layout.

### 5.4 Registry behavior

Registration is deterministic. Duplicate `(kind, id, version)` entries fail
boot. Lookup requires an exact ID and version. Missing definitions do not fall
back to a similarly named or latest definition. Registry manifests are safe to
cache and contain no closures, resources, or environment-specific paths.

## 6. Validation

Validation is pure and deterministic. It returns ordered typed violations with
stable machine codes and JSON Pointer locations. At minimum it checks:

- document envelope and supported version;
- exact template/layout/block definitions;
- unique and well-formed instance IDs;
- section, region, and block cardinality limits;
- template/layout/region allowlists;
- block configuration JSON Schema;
- typed references and declared reference capability;
- prohibited executable or style escape hatches;
- migration requirement for older block definitions.

Validation never loads referenced entity values and never makes access
decisions. Reference resolution and access are authoritative at the application
service boundary under the acting principal. A document that is structurally
valid may still be refused for inaccessible media or content.

## 7. Edit command contract

Clients submit commands against an observed entity revision token and document
fingerprint. Initial commands are:

- add, duplicate, move, configure, and remove block;
- add, move, configure, and remove section;
- change an allowed section layout;
- restore a prior entity revision as a new revision.

Commands identify sections and blocks by stable instance ID, never array index
alone. A successful command returns the complete validated document, new
fingerprint, and change summary. It does not persist by itself. Persistence is
one revision-creating entity mutation with the observed concurrency token.

Unknown targets, stale fingerprints, invalid destinations, inaccessible
references, and definition-version mismatches are typed failures. No command
silently drops content or coerces an unknown block.

### 7.1 Persistence boundary

`page-builder` defines a client-neutral draft gateway. Its application service
reads the current entity revision and layout document, checks both the observed
entity revision and document fingerprint, applies one pure edit command,
validates the complete result, and submits one canonical layout value with an
idempotency key. It never calls an entity repository directly.

`publishing` supplies the first gateway adapter through `ContentPublisher`.
The adapter updates only the configured layout field and carries the observed
entity revision into the existing write-time optimistic-locking path. A
successful edit therefore creates one ordinary content revision and returns a
fresh revision identifier; a stale edit cannot silently overwrite a newer
draft. This seam deliberately does not claim to complete the wider DB-03
universal mutation-token contract. A future opaque aggregate token may replace
the current revision identifier behind the same gateway without giving either
editor client a private persistence path.

## 8. Preview and rendering

Published and preview rendering use the same semantic renderer bindings and
design tokens. Preview differs only in its selected unpublished entity
revision and authenticated editing chrome.

Preview must be authenticated, short-lived, non-indexable, non-public,
non-cache-confusable, and bound to the exact entity revision. The page-builder
package supplies renderer-neutral view data. The application owns the route and
theme rendering; `publishing` owns the signed preview grant.

Every rendered block root includes its stable instance ID as inert editor
metadata for selection. Public output must not expose privileged configuration,
internal entity identifiers, tokens, or editing endpoints.

## 9. Structured editorial content

Updates, Events, Jobs, and Announcements remain typed content entities. They are
not bespoke page layouts. A dynamic listing block stores a registered listing
identity and bounded display options. It does not copy listing results into the
page document.

Admin SPA and Anokii must provide the same high-volume capabilities: saved
views, contains search, state/date/expiry filters, deterministic sorting,
type-specific create templates, governed pickers, real timezone-aware date
controls, duplicate-as-new, exact-theme preview, explicit revision save,
workflow actions, and authorized audited bulk archive.

## 10. Client parity

Admin SPA and Anokii may use different shells and labels but must consume the
same wire contract. A draft saved in either client opens identically in the
other, including document, instance IDs, definition versions, validation,
entity revision token, preview revision, and history.

Both clients must pass one shared contract fixture suite. Client-only behavior
cannot broaden server capability or bypass access, validation, workflow,
concurrency, or audit checks.

## 11. Accessibility and safety

- Every operation is keyboard complete.
- Regions and order have textual announcements and controls; position is not
  conveyed visually alone.
- Focus is restored predictably after insert, move, configure, and delete.
- Destructive actions require explicit accessible confirmation.
- Phone, tablet, and desktop previews use the actual responsive components.
- Required alt text, headings, link meaning, contrast-compatible variants, and
  semantic landmarks are enforced by block schemas and renderers.
- Autosave is local recovery only. Server persistence is an explicit revision-
  creating save.
- Concurrent edits fail honestly and offer compare/reapply; last-write-wins is
  not an accepted conflict strategy.

## 12. Initial Sheguiandah definitions

Initial blocks: rich text, image, gallery, document card, callout, contact and
location, service grid, latest updates, upcoming events, open jobs,
announcement banner, and approved separator/spacing.

Initial layouts: one column, two equal columns, two-thirds/one-third, and
one-third/two-thirds. Sheguiandah templates restrict which layouts and blocks
are available and bind every style choice to its design system.

## 13. Acceptance gates

1. Invalid or unknown document shapes fail before persistence.
2. Serialization is deterministic and round-trips byte-identically.
3. Duplicate definitions and instance IDs fail closed.
4. Every edit command preserves untouched block bytes and stable IDs.
5. Admin SPA and Anokii pass the same fixture and mutation contract tests.
6. A stale editor cannot overwrite a newer draft.
7. Preview renders the exact unpublished revision through the public theme.
8. Published rendering contains no editing authority or executable content.
9. A Communications Officer completes Sheguiandah page, update, event, and job
   scenarios locally without raw HTML or developer intervention.
10. All scenarios are keyboard complete and pass automated accessibility
    checks at phone, tablet, and desktop widths.

## 14. Work packages

| WP | Outcome | Dependencies |
|---|---|---|
| WP01 | Package scaffold, value objects, canonical codec, registry, validator | none |
| WP02 | Stable edit commands, document fingerprint, typed failures | WP01 |
| WP03 | Entity field/application service and optimistic-concurrency binding | WP02 |
| WP04 | Admin surface wire contract and authenticated preview integration | WP03 |
| WP05 | Admin SPA visual builder client | WP04 |
| WP06 | Anokii client and Sheguiandah branded shell integration | WP04 |
| WP07 | Structured high-volume editorial workspace and listing blocks | WP03 |
| WP08 | Shared browser, accessibility, role, preview, and revision acceptance | WP05-WP07 |
| WP09 | Exact downstream package integration and staging acceptance | WP08 |

Framework issue #2343 consumes the completed capability through fresh-site
recipes after Sheguiandah acceptance. It is not a predecessor of this work.
