# FW-COMMUNITY-EVENTS-STARTER-01 — packaged governed starter

Status: source candidate; runtime and packaged-consumer acceptance remain open.
Anchor mirror: waaseyaa/framework#2981.
Parent: `623679303266812231d09469628b036a43dd3ccc`.

## Outcome

`waaseyaa/site-contract` packages a distinct `community-events@1` starter at
`resources/starters/community-events/v1.yaml`. It uses the existing closed
`waaseyaa.site` v1 `application_blueprint` contract and names Event, Venue, and
Organizer directly. The existing editorial `complete.yaml` fixture is unchanged.

The starter declares only explicit permissions for contributor, reviewer, and
administrator roles. It defines Draft → Review → Published transitions through
the canonical workflow vocabulary, Event-to-Venue and Event-to-Organizer
relationships, seed fixtures, and both allowed and denied checks. Organizer is
a business relationship, not an authenticated-account owner key: v1 grants
contributors collection-wide Draft editing and never compares unrelated
Organizer and Account ids. Administrators receive explicit all-state event and
Organizer/Venue CRUD policies. All named roles may view private-MVP events in
every state; anonymous access remains denied.

## Ownership

This source-only slice owns:

- `packages/site-contract/resources/starters/community-events/v1.yaml`;
- `packages/site-contract/resources/starters/community-events/README.md`;
- `packages/site-contract/tests/Unit/Starter/CommunityEventsStarterContractTest.php`;
- `packages/cli/tests/Unit/Site/Blueprint/CommunityEventsStarterCompilationTest.php`;
- this record and `changes/unreleased/2981.community-events-starter.added.md`.

It does not change the parser, canonicalizer, compiler, governance engine,
generators owned by #2849, shared CI, Studio, or a release surface.

## Stable source identity

The portable identity is package `waaseyaa/site-contract`, starter id
`community-events`, contract version `1`, and package-relative path
`resources/starters/community-events/v1.yaml`. A consumer records the installed
Composer version and exact source reference alongside that path. Those volatile
values are derived from the installed package; they are not hard-coded into the
starter.

The manifest's all-zero observed-lock digest is an explicit template placeholder.
A consumer must bind it to its reviewed dependency lock before approval or apply.

## Source acceptance

- The packaged YAML parses, canonicalizes, and round-trips through the existing
  `SiteManifestParser` without a starter-local parser.
- Entity, relationship, role, permission, policy, workflow, fixture, and check
  identities are asserted from the typed manifest.
- Administrator authority is exactly its declared permission list; no wildcard
  or bypass exists.
- Focused mutations prove an unresolved Venue fixture relationship fails with
  the canonical `SITE042_BLUEPRINT_UNRESOLVED_REFERENCE` finding.
- The existing compiler accepts the typed manifest and emits governed entity,
  relationship, policy, workflow, and behavioral-check artifacts.
- The generated Event definition is registered through the real
  `EntityTypeManager` and `FieldDefinitionRegistry`: title, summary, start time,
  and the Venue and Organizer business relationships are explicitly `Public`;
  only the engine-owned workflow state remains `Protected`. No registered field
  relies on the fail-closed `Internal` default, and no authorization input is
  promoted to a public projection.
- Contributor update authority is bounded to Draft through a canonical
  `workflow_state` policy. Administrator event and Organizer/Venue CRUD grants
  have matching policies rather than role-catalogue-only claims.

## Remaining #2981 acceptance

This source slice does not close #2981. Still required:

- a matched packaged-consumer install using the candidate artifact;
- replacement of the lock placeholder with the consumer's exact reviewed lock;
- exact-digest dry-run, approval, apply, reapply, and verification evidence;
- execution of generated runtime checks proving allowed and denied transitions,
  relationship integrity, and explicit administrator authority;
- consumer adoption evidence, release notes, and separately authorized package
  publication.
