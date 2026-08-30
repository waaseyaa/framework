# FW-AUTH-SESSION-REVOCATION-01 — Password reset revokes authenticated sessions

Status: implemented

Issue: #2700

## Problem

Resetting an account password changed the credential but left every previously issued PHP session authorized. A stolen session cookie therefore remained usable after the account owner completed the recovery flow.

## Decision

The `user` entity owns an internal, non-negative `session_generation`, stored in the existing SQL-blob `_data` payload. Every password-authenticated session records both the user ID and the current generation. `SessionMiddleware` resolves the user through the canonical repository and accepts the session only when its integer generation exactly matches the audited internal value.

`ResetPasswordController` reads the current generation through `UserInternalFieldReaderInterface`, increments it in the same entity save as the new password hash, then clears the reset request's session identity. All sessions issued before that save become anonymous on their next request. Sessions issued after the save bind the new generation and remain valid.

Sessions created before this contract have no generation and fail closed. Bearer-authenticated requests are unaffected because `BearerAuthMiddleware` resolves their account before the PHP-session path.

## Authority and construction

- `AuthenticatedSession` owns the session key constants and identity mutations.
- `AuditedUserInternalFieldReader` owns access to the internal generation under the existing `user.session-identity` / `SessionBootstrap` capability.
- `HttpKernel` treats that audited reader as a production-required dependency when constructing `SessionMiddleware`.
- `session_generation` is Internal and forbidden on generic user field read/write surfaces, including for administrators.

No table migration is required: `User` uses the default SQL-blob backend and the new field is backward-compatible with existing rows through a default of zero.

## Verification

Regression coverage pins missing-generation rejection, stale-generation rejection and identity clearing, matching-generation acceptance, reset increment, invalid-token non-mutation, every session issuance path, capability declaration/read scope, generic-field denial, and route/kernel composition.

A disposable real-HTTP control-plane probe retained one cookie jar across password reset. Before reset, `/api/user/me` returned 200. Reset returned 200 and persisted `session_generation: 1`; the retained cookie then returned 401. The old password returned 401, while the new password issued a generation-bound session that returned 200 from `/api/user/me`.
