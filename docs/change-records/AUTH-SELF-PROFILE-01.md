# AUTH-SELF-PROFILE-01

Restore a consumer's My Account profile save through a canonical Auth operation.
The existing self reader releases name/mail and the generic User API permits
self-edit, but neither coordinates reauthentication, email verification state,
outstanding recovery tokens and session generation when the email changes.

Design: a request-principal-bound self operation accepts an opaque mutation
expectation, current password, mail and explicitly configured noncredential
profile fields. It reloads the actor's own account, checks entity/field edit
access, reauthenticates and saves through the canonical shared repository
transaction. Email changes clear verification, revoke tokens and increment
session generation atomically. The caller must clear its browser identity after
an email change. Password changes continue through existing recovery/reset.
For deployments requiring verified email, refuse an address change unless mail
delivery is configured; send the canonical verification message after commit.
Delivery failure is an explicit saved-result condition, never a false rollback.

No schema change, new public HTTP endpoint, role grant or alternate persistence
path. App-owned optional names remain app configuration. Existing Symfony HTTP
and validation components remain unchanged; this addition is Auth domain policy.

Verification: focused service admission, credential refusal, stale expectation,
field injection, uniqueness/transaction rollback and email token/session tests;
then real consumer kernel and browser acceptance. Independent authentication and
persistence review is required before integration. Publication, published-package
adoption and staging/production qualification remain separately evidenced.
