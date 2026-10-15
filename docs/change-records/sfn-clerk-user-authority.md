# SFN clerk account authority

Local candidate, October 14, 2026. Owner: Russell Jones. No merge, split,
release or deployment is authorized by this record.

Forge mirror: [Framework #3205](https://github.com/waaseyaa/framework/issues/3205).
Regression captured red first. User and kernel delegate-binding tests pass 390
tests / 787 assertions; EntityAccessHandler and access-policy registry callers
pass 21 / 31 on PHP 8.5.5. Package-layer and Composer policy checks pass.
Bound/unbound policy discovery and successive actors on the same retained
handler are proven. Independent review remains before merge.

Sheguiandah at site `15000a3` locks User
`a5a3f310bf6e63f78770e5384cb15f72ab087690`. Its UserAccessPolicy has identical
bytes to Framework `38bd59f386de7234de12c2a6171c6b6f2692cc69`. A clerk with
`sfn_manage_members` cannot create a user, update another user, or change
active status. Giving the clerk `administer users` would also grant broad role,
account and deletion authority. Raw repository writes would bypass this policy.

The smallest upstream extension is an optional UserManagementDelegateInterface
consumed by the existing UserAccessPolicy. It admits authenticated immutable
principals to consumer-scoped update and active-status decisions. It
does not release Internal fields, grant role/permission/email-verification edits,
enable generic credential writes, or change deletion. With no delegate, existing
behavior is unchanged. Waaseyaa's EntityAccessHandler remains authoritative.
Generic creation stays administrator-only. Review identified that an actor-only
create predicate would not bind the required approved intake; creation needs a
separate canonical provisioning boundary with an explicit verified reference.
The consumer owns the target roster and consent decision. The delegate must
use audited subject inputs and carry no request state in a retained worker.

Kernel policy discovery is also exercised with a present and absent binding,
and successive actors on the same retained handler. Tests must distinguish an
admitted clerk/target from another actor, another
target, anonymous and mutable accounts; denied update must also deny status.
Existing credential/privilege and self-deletion refusal tests must still pass.

This is one prerequisite, not full clerk parity. Subsequent consumer work must
connect verified consent intake, canonical identity uniqueness/provisioning,
profile edits, disable/re-enable and session invalidation, controlled welcome
mail through the existing token/mailer services, per-record history and the
final SQL-backed migration. Exact reviewed split refs are required before the
site can consume the extension. No local path dependency or vendor patch.
