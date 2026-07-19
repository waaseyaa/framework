# Boolean field storage normalization

Starting with `0.1.0-alpha.270`, every present, non-null entity field declared
or inferred as `boolean`/`bool` has one framework representation: native PHP
`bool`. The resolved field definition drives normalization while the private
entity value container is atomically sealed and on every later write. Create,
validation, persistence snapshots, hydration, guarded reads, and consumer
projections therefore share one type.

This supersedes alpha.266's repository rule that converted boolean fields to
integer `0`/`1` before persistence. Physical SQL storage may still encode a
boolean as `0`/`1`; that is an adapter detail and is canonicalized before an
entity becomes observable. Legacy `0`/`1` rows and write inputs remain accepted
at the closed ingress and immediately become native `bool`.

Read-side domain code may continue to use helpers such as
`Node::isPublished()`, but `get()` and public projections now also guarantee a
native `bool`; consumers no longer need int-vs-bool compatibility comparisons.

## Framework field audit

The field-definition audit found these boolean fields, all covered by the
repository boundary:

| Entity type | Boolean fields |
|---|---|
| `agent_audit_log` | `success` |
| `attachment` | `is_active` |
| `comment` | `status` |
| `node` | `status`, `promote`, `sticky` |
| `genealogy_tree` | `status` |
| `genealogy_family` | `status` |
| `genealogy_person` | `is_living`, `status` |
| `genealogy_event` | `status` |
| `thread_message` | `status` |
| `menu_link` | `enabled`, `expanded` |
| `media` | `status` |
| `path_alias` | `status` |
| `relationship` | `status` |
| `taxonomy_term` | `status` |
| `user` | `email_verified`, `status` |
| `oidc_client` | `is_confidential` |

`user.email_verified` and `oidc_client.is_confidential` rely on typed-property
inference; the other entries declare `boolean` explicitly. The architecture
gate reflects these first-party entity classes and rejects an integer default
on any resolved boolean definition.

The broader boolean-like inventory was also reviewed. These representations
remain unchanged because they are not declared content-entity boolean fields:

| Surface | Existing contract |
|---|---|
| `group.status` | Declared integer field using integer `0`/`1` |
| Audit-checkpoint `is_genesis` / `pruned` | Raw audit-table integer columns |
| `ConfigEntityBase.status` | Configuration/YAML state |
| Media file `status` | String lifecycle state |
