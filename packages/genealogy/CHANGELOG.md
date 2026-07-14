# Changelog — waaseyaa/genealogy

## Unreleased (alpha)

### Breaking

- **Privacy defaults:** Genealogy SSR and JSON:API consumers now evaluate **private-by-default** rules. Anonymous visitors are **denied** `view` on `genealogy_*` content and on genealogy `relationship` edges unless endpoint entities are themselves viewable.
- **`status` default:** New `genealogy_person`, `genealogy_family`, `genealogy_event`, and `genealogy_tree` rows default to **unpublished** (`status` off). Published visibility is aligned with `WorkflowVisibility` for non-node entity types (status normalization).
- **Tenancy:** Introduced **`genealogy_tree`** as the workspace root with **`owner_uid`**. Persons, families, and events carry **`tree_id`**; rows without a resolvable tree are not viewable.
- **Living axis:** `genealogy_person` includes **`is_living`** (boolean, conservative default **true** when unknown). Non-owners cannot `view` living persons without future grant semantics (C1).
- **Soft delete:** **`deleted_at`** string tombstone on person/family/event; non-empty values deny `view`.
- **SSR product gate:** Controllers require authenticated `User` accounts with **`genealogy_product_enabled`** (host-provided field, e.g. via `FieldDefinitionRegistry::mergeCoreFields` on `user`). Demo seed sets this for `uid` 1 in Minoo.
- **Field registry:** Genealogy entity field metadata is registered from the canonical entity classes. Runtime **`waaseyaa/field`** and **`waaseyaa/workflows`** are direct `require`s.

### Added

- **`GenealogyRelationshipType::IDENTITY_OF_USER`** — documented B2 user↔person identity edge constant; precedence vs `genealogy_share` is specified in `docs/specs/genealogy-policy-precedence.md`.
- **SSR redaction:** Neighbor and ancestor charts emit **placeholders** instead of hidden numeric ids.

### Downstream

- Apps that need **stricter** posture than package defaults may still register **Forbidden-first** overlay `AccessPolicyInterface` plugins; this remains supported but is not Minoo’s primary path.
