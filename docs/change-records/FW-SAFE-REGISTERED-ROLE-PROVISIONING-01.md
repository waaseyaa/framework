# FW-SAFE-REGISTERED-ROLE-PROVISIONING-01 — bounded account and role bootstrap

- **Issue:** [#3046](https://github.com/waaseyaa/framework/issues/3046)
- **Studio prerequisite:** [Studio #27](https://github.com/waaseyaa/studio/issues/27)
- **Status:** implemented on a local candidate; independent review, merge,
  release, deployment, and generated-app journey acceptance are not claimed.

## Decision

`user:provision-registered` reads one size-bounded, canonical JSON request from
stdin. The request contains the username, mail address, password, and one
explicit registered role. The command has no arguments or options that can
carry credential bytes, and it never reads a credential from the environment.
Malformed, non-canonical, oversized, reserved-administrator, and unknown-role
requests refuse before persistence.

The canonical v1 input object has exactly these keys, in canonical ordering:

```json
{"email":"owner@example.test","password":"<credential bytes>","role":"contributor","schema":"waaseyaa.user-provision-registered-command.v1","username":"community-owner","version":1}
```

The command reads at most 4096 bytes including the final LF. An orchestrator
can connect an already-open private descriptor to stdin; the descriptor path,
credential, and model-specific environment never become command arguments.
Success and refusal use exit codes 0 and 1; a proven commit followed by failed
completion work uses exit code 2 with status `uncertain`.

Registered role replacement and permission-union behavior moved from
`UserAssignRoleHandler` to the shared `RegisteredRoleAssignmentService`; both
commands now use that one authority. Provisioning creates a User with its final
password hash, role membership, and flattened registered permissions in one
repository save. It never grants the reserved `administrator` role as a
fallback.

Safe provisioning writes two internal lower-case identity keys with the account.
Declarative storage unique keys cover those fields and serialize simultaneous
safe-provisioning requests. Direct `User` construction and explicit name/mail
mutation keep supplied bindings synchronized, but the repository's sealed
initializer bypasses constructors; ordinary repository creation does not yet
join this namespace. An exact retry verifies active identity, mail, current
password hash, roles, and permissions without writing. A mismatch refuses
without resetting credentials or privileges. A repository post-commit
completion failure yields the distinct `uncertain` status. A failed save is
reconciled once through the same exact identity reader and is also `uncertain`
when the durable outcome is not yet observable; the command never retries the
write. Failures known to precede a save yield `refused`, without exposing
exception detail.

The audited user-identity reader now exposes `loginExists()` alongside
`mailExists()`. Provisioning uses both predicates to refuse inactive or
historical name/mail ownership before creation; it does not issue a private
unaudited identity query.

## Compatibility boundary

Existing rows remain nullable and do not make schema sync fail. Operators must
separately preflight and backfill older rows, then migrate every account-creation
path to the shared binding before relying on these keys as a global account
identity invariant. Until then, concurrent ordinary repository creation can
race safe provisioning. This slice proves only safe-command concurrency and
does not silently merge or repair historical identities.

The command does not implement invitations, browser sessions, OAuth, generated
application deployment, or release behavior.
