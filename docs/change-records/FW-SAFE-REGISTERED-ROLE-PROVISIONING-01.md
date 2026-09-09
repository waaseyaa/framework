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

`User` owns two internal lower-case identity keys. Its pure
`EntityCreationValuesInterface` implementation overwrites caller-supplied keys
from the semantic name and mail after field defaults and before sealed
construction. The same derivation is used by direct construction, while
explicit name/mail mutation keeps both keys synchronized. Declarative storage
unique keys therefore serialize simultaneous safe provisioning and every
current repository creation/rename path. An exact retry verifies active
identity, mail, current password hash, roles, and permissions without writing.
A mismatch refuses
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

The Community Events process fixture materializes its provider from the
Framework-owned `community-events@1` starter through the canonical compiler;
it contains no handwritten role provider. Packaged-form acceptance additionally
installs the exact candidate into a disposable generated application, applies
that starter, and runs the public command against the application's booted
repository. A later process compares stored contributor, reviewer, and event
administrator authorization with both the emitted provider and the kernel's
validated role repository. Exact retry, identity conflict, unknown role,
reserved administrator, exit/result agreement, and credential non-disclosure
remain explicit controls.

## Compatibility boundary

Existing rows remain nullable and do not make schema sync fail. Hydration never
invokes the creation-value contract, so loading and saving an unrelated field
does not silently claim an identity binding. Operators must separately preflight
historical case variants and backfill older rows before relying on these keys as
a complete old-database identity invariant. This slice does not silently merge,
rename, or repair historical identities.

The command does not implement invitations, browser sessions, OAuth, generated
application deployment, or release behavior.

## Local validation

- Focused entity creation, physical uniqueness, audited identity lookup,
  registered-role, handler, and fresh-process concurrency tests: 81 tests,
  351 assertions.
- Adjacent User, entity-instantiation, audit, and CLI compatibility tests: 382
  tests, 806 assertions.
- Targeted PHPStan, repository coding style, Composer manifest validation, PHP
  lint, and patch whitespace checks pass on the local candidate.

These checks qualify the bounded command and current creation paths. They do
not qualify an upgraded database until its historical identity rows have been
preflighted and backfilled under a separately reviewed migration procedure.
