# waaseyaa/relationship

**Layer 2 — Content Types**

Entity relationship modeling for Waaseyaa applications.

Defines the `relationship` entity type for typed connections between entities (e.g. author→article, tag→post). `RelationshipDiscoveryService` and `RelationshipTraversalService` power relationship-aware rendering in the SSR layer. `RelationshipAccessPolicy` is auto-discovered via `#[PolicyAttribute]`. See `docs/specs/relationship-modeling.md`.

Key classes: `Relationship`, `AuthorizedRelationshipTraversal`, `AuthorizedRelationshipEdge`, `MemberDirectoryEntry`, `RelationshipDiscoveryService`, `RelationshipTraversalService`, `RelationshipAccessPolicy`, `RelationshipServiceProvider`.

Application code that follows relationships for an end user should inject
`AuthorizedRelationshipTraversal` and call `edges($principal, $sourceType,
$sourceId, $options)`. It accepts domain options (`direction`,
`relationship_types`, `at`, and `limit`) rather than protected field names or
capabilities, and returns immutable `AuthorizedRelationshipEdge` projections.
The facade conceals a missing or non-viewable source and includes only active
relationship records whose edge and related endpoint are both viewable by the
principal. Lower-level traversal readers remain for framework-owned discovery
and system-context work; consumers must not use `status: all` as an access
bypass.

For group member directories, call `memberDirectory($principal, $groupId)`.
Administrators retain the broad view-authorized path. When broad group view is
not allowed, the scoped path requires an authenticated principal, a strict
exact-group `members_can_view_directory` opt-in, and that principal's live
direct membership in the same snapshot used to enumerate the group. It returns
only immutable `MemberDirectoryEntry` values containing `userId` and
`displayName`; it accepts no direction, relationship type, member id, field,
predicate, or transitivity option.
