# Boolean field storage normalization

Starting with `0.1.0-alpha.266`, the canonical entity repository stores every
present, non-null field declared or inferred as `boolean`/`bool` as integer
`0` or `1`. The normalization happens once after pre-save processing and before
the same snapshot is sent to base, bundle, or revision storage. Interactive,
batch, and migration-destination saves therefore share one stored shape.

Existing rows are normalized when they are next saved. Read-side domain code
should continue to use entity helpers such as `Node::isPublished()` rather
than compare raw field values.

## Framework field audit

The field-definition audit found these boolean fields, all covered by the
repository boundary:

| Entity type | Boolean fields |
|---|---|
| `attachment` | `is_active` |
| `comment` | `status` |
| `node` | `status`, `promote`, `sticky` |
| `genealogy_tree` | `status` |
| `genealogy_family` | `status` |
| `genealogy_person` | `is_living`, `status` |
| `genealogy_event` | `status` |
| `thread_message` | `status` |
| `path_alias` | `status` |
| `taxonomy_term` | `status` |
| `user` | `email_verified`, `status` |
| `oidc_client` | `is_confidential` |

`user.email_verified` and `oidc_client.is_confidential` rely on typed-property
inference; the other entries declare `boolean` explicitly.

The broader boolean-like inventory was also reviewed. These representations
remain unchanged because they are not declared content-entity boolean fields:

| Surface | Existing contract |
|---|---|
| `group.status` | Declared integer field using integer `0`/`1` |
| `relationship.status` | Relationship data value normalized to integer `0`/`1` |
| Audit-checkpoint `is_genesis` / `pruned` | Raw audit-table integer columns |
| `ConfigEntityBase.status` | Configuration/YAML state |
| Media file `status` | String lifecycle state |
