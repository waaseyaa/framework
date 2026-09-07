# Community events starter

`v1.yaml` is the Framework-owned `community-events@1` starter for a governed
Event, Venue, and Organizer application.

Supported customization:

- labels and optional descriptive fields for events, venues, and organizers;
- Event-to-Venue and Event-to-Organizer relationships;
- explicit contributor, reviewer, and administrator permission grants;
- Draft → Review → Published editorial flow.

Authorization semantics:

- Organizer is a public business relationship, not an authenticated-account
  ownership field. The starter never compares an Organizer row id with an
  account id and never grants access because those unrelated numbers happen to
  match.
- Contributors can create events and edit events while they are Draft.
  Administrators can manage events in every state and create, update, or delete
  Organizer and Venue records through explicit permissions.
- This private-MVP starter lets its three named roles view events in every
  workflow state. Anonymous access remains denied. `published` is a workflow
  state; it does not implicitly make an event public.

Limits:

- The all-zero `framework.observed_lock_sha256` is a template placeholder. Bind
  the manifest to the consumer's reviewed lock before approval or apply.
- Payments, ticketing, messaging, geocoding, recurrence, capacity management,
  multi-workspace tenancy, deployment, and provider-generated proposals are not
  supplied by this starter.
- Administrator has no implicit bypass; it holds only permissions listed in the
  manifest.
- Per-organizer membership and self-owned event editing are not claimed by v1;
  they require a canonical authenticated-account-to-workspace ownership model.
- Package presence alone is not approval or runtime qualification. Run the
  canonical dry-run, decision, apply, verification, and generated behavioral
  checks in the matched consumer cohort.

Stable provenance is the installed `waaseyaa/site-contract` Composer version,
its exact source reference, and the package-relative path
`resources/starters/community-events/v1.yaml`.
