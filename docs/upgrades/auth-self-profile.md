# Auth self-profile saves

`Waaseyaa\Auth\Profile\SelfProfileUpdater` is an additive canonical operation.
It introduces no schema change or public HTTP endpoint. Consumers own their HTML
forms, CSRF-protected routes and presentation. Configure existing declared User
profile fields through `auth.self_profile_fields`; the default list is empty.
Mail is always eligible for the operation. Credential, identity-key, privilege,
tenant and community fields cannot be configured as ordinary profile fields.

Call `save` with the exact ambient immutable principal, the User's opaque
mutation token, the current password and an explicit allowlist of submitted
fields. Never copy an arbitrary request object into the changes array. Reads
remain subject to the existing audited self-profile/name readers.

The service must own the outermost canonical DBAL transaction. Wrong credentials,
stale snapshots, conflicting addresses (including legacy/inactive owners),
invalid fields and rate exhaustion refuse without changing the profile. A
successful email change clears verification, revokes owned auth tokens and
increments session generation in the same transaction. The caller must clear
its browser identity and require sign-in again. Existing second-factor state
remains unchanged. Password mutation still uses the canonical recovery/reset flow.

`SelfProfileUpdateResult` always describes a committed save. Check
`completionEffectsFailed` and avoid presenting a retryable unsaved outcome when
it is true. For verified-email deployments, also check `verificationRequired`
and `verificationSent`; verification delivery occurs after commit. A failed
delivery requires resend/help, not replay of the profile save. Those deployments
refuse address changes before saving when mail is unconfigured.

Consumers should test actual provider/audited-reader composition, own-account
isolation, CSRF, profile field edits, stale and duplicate refusals, password-link
delivery, session/token invalidation and preserved two-factor requirements.
See `AUTH-SELF-PROFILE-01` for the scoped design and qualification record.
