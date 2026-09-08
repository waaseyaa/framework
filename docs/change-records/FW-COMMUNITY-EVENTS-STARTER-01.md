# FW-COMMUNITY-EVENTS-STARTER-01 — packaged governed starter

Status: partial implementation checkpoint; local packaged-consumer generation,
governance, workflow acceptance, and Event Organizer/Venue reference-existence
discrimination are owned by the community-events packaged-form check. Tip
qualification binds the candidate that last passed that check.
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
- `tests/PackagedForm/check-community-events-starter` and its cleanup roster
  entry;
- `tests/PackagedForm/community-events-reference-existence.php` (Phase 7
  reference-existence discriminator);
- this record, `changes/unreleased/2981.community-events-starter.added.md`, and
  `changes/unreleased/2981.community-events-reference-existence.added.md`.

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

## Packaged runtime evidence

### Generation / governance / workflow (prior seal)

The exact clean candidate `55bed0d69e1d20ff3c126ddaadbc18116eed1357`
was sealed into 79 local Composer artifacts and installed into a disposable
consumer as `0.1.0-alpha.300`. The installed starter was bound to that
consumer's exact lock before the canonical approval was issued.

- lock SHA-256: `f00ece9e337b7a782199441336e2be796a1289eb36bd12ed67a3245ee53f5278`;
- manifest digest: `961ed81b67c05a6958735a7d049ff82ddc46399af88a2a788f6899c925a3b04e`;
- blueprint digest: `11fdd7b39e34e939496f55b9b9a29bee24b43eecc288e6fb098678d032e36e4f`;
- plan digest: `15be30bd1ae0bd3c82a83cb434394a3db263ae70b08cb43e16a508046659f305`;
- decision receipt id: `4f7a74d2d27da655e264961d581641127bb70f51499625b08d1bab980fbe77f6`.

Preview returned `planned` without changing the project snapshot. The matched
approval applied 26 paths and an unchanged replay returned `no_changes` under
the same plan and decision identities. Registered generated Event fields named
real registered Organizer and Venue target definitions; this proves target
metadata and boot registration, not target-row existence. A sequential real
`TransitionService` run over generated Event, workflow, and role classes proved
Draft → Review contributor submission, contributor publication denial from
Review, and reviewer publication and return. The generated access, JSON API,
default-deny, role, and workflow tests passed: 45 tests, 135 assertions. Strict
doctor reported no findings.

### Event Organizer/Venue reference existence (this slice)

Packaged-form Phase 7 (`community-events-reference-existence.php`) boots the
generated Event/Organizer/Venue types with `EntityValidator` +
`EntityIdentifierResolver` (kernel-equivalent) and proves:

- missing Organizer + valid Venue → `EntityValidationException` on `organizer`,
  JSON:API `422`, no durable Event row;
- valid Organizer + missing Venue → property path `venue`, JSON:API `422`, no
  durable Event row;
- both missing → property paths `organizer` and `venue`, JSON:API `422`, no
  durable Event row;
- both present → JSON:API `201` with persisted organizer/venue ids;
- anonymous create remains `403` (authorization), distinct from validation
  refusals.

**Observed baseline (honest):** the generated `JsonApiGovernanceChecksTest`
still constructs repositories without `EntityValidator` and seeds with
`validate: false`, so its create-allowed case can return `201` for numeric
organizer/venue attributes without target rows. That harness omission is not
treated as production behavior. Framework #2989 supplies the existence
substrate; Phase 7 shows it on the community-events generated types when
validation is active. No defect requiring a generator or #2989 code change was
observed under that composition.

## Remaining #2981 acceptance

This checkpoint does not publish a package or close Studio consumer readiness.
Still required:

- independent review and the repository's governed current-base qualification
  (absorb behind-2 on main via merge, not rebase of the preserved starter);
- consumer adoption evidence, release notes, and separately authorized package
  publication;
- Studio catalog / browser apply / preview wiring (Studio #16 / #4 / #19) —
  developable against local Framework cohorts; Packagist qualification remains
  a later delivery check.
