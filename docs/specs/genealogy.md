> **Distribution-extension package** — `waaseyaa/genealogy` is a *distribution-extension*,
> not framework substrate. Per charter directive DIR-004 (Framework vs Distribution
> Architecture), domain content like Indigenous family lineage modelling is delivered
> as a separately-versioned package consumers opt into, and is **not** required by
> `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. See
> `docs/specs/extraction-log.md` for the reclassification record.

# Genealogy package (v0.1)

Greenfield genealogy modeling for Waaseyaa, inspired by public feature areas of HuMo-genealogy (person/family views, charts, relationships) without schema or code migration from HuMo.

## Entity types

| ID | Label | Purpose |
|----|-------|---------|
| `genealogy_tree` | Tree | Tenancy root: owner account, grants anchor, workspace |
| `genealogy_person` | Person | Individual in a tree |
| `genealogy_family` | Family | Household / family group |
| `genealogy_event` | Event | Vital or narrative event (birth, marriage, etc.) |

Content rows default to **unpublished** (`status` off). **`tree_id`** links persons/families/events to a **`genealogy_tree`**. Persons carry **`is_living`** (boolean; conservative default) and optional **`deleted_at`** tombstones for soft-delete.

## Relationship types (`relationship` entity)

Edges use the shared `relationship` entity type. `relationship_type` (bundle) values owned by this package:

| `relationship_type` | Directionality | From | To | Meaning |
|---------------------|----------------|------|-----|---------|
| `genealogy_parent_of` | directed | `genealogy_person` (parent) | `genealogy_person` (child) | Lineage |
| `genealogy_spouse_of` | bidirectional | `genealogy_person` | `genealogy_person` | Marriage / partnership |
| `genealogy_member_of_family` | directed | `genealogy_person` | `genealogy_family` | Household membership |
| `genealogy_identity_of_user` | directed | `user` | `genealogy_person` | “This account is this person” (B2); precedence vs grants: `docs/specs/genealogy-policy-precedence.md` |

Edges participate in traversal only when endpoint entities are **viewable** under the same access rules as direct loads.

## Access

- **Content:** `GenealogyContentAccessPolicy` enforces **private-by-default**, **`WorkflowVisibility`/`status` normalization**, **tree ownership**, **`is_living`** rules for non-owners, and **tombstones**. Anonymous visitors are denied `view` on genealogy content.
- **Graph edges:** `GenealogyRelationshipAccessPolicy` (registered at HTTP kernel boot) denies `view` when either endpoint entity fails `view`. Generic `RelationshipAccessPolicy` still applies for non-genealogy types.

## JSON:API

Standard auto-routes apply (`JsonApiRouteProvider`):

- `GET /api/genealogy_person`, `GET /api/genealogy_person/{id}` (and parallel for `genealogy_family`, `genealogy_event`, `genealogy_tree`, `relationship`).

Writes (`POST`/`PATCH`/`DELETE`) require authentication per global JSON:API route rules.

## Public SSR (read-only)

Registered before the catch-all render routes:

| Route | Controller | Purpose |
|-------|------------|---------|
| `GET /genealogy/person/{id}` | `GenealogySsrController::person` | Profile |
| `GET /genealogy/family/{id}` | `GenealogySsrController::family` | Members list |
| `GET /genealogy/person/{id}/ancestors` | `GenealogySsrController::ancestorChart` | Text ancestor levels |

Templates live in `packages/genealogy/templates/` (`*.html.twig`).

## Domain services

- `GenealogyPedigreeService` — parents, children, spouses, ordered ancestor generations (deterministic ordering by numeric id tie-break).
- `GenealogyFamilyService` — members of a family via `genealogy_member_of_family`.

## Cross-references

- Relationship modeling, ordering, and visibility: [relationship-modeling.md](relationship-modeling.md)
- JSON:API attributes and access: [jsonapi.md](jsonapi.md)
