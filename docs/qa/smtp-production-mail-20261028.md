# SMTP production mail delivery record

Change identity: `smtp-production-mail-20261028`. Observed 2026-10-30 against
Framework main `624c70328e07e9c41f362305370ebf07cd0909c8`.

The SMTP implementation, transport-aware auth-mail readiness, and checked
account lifecycle are on Framework main. The earlier candidate-only status and
tenant-auth refusal below are historical as of October 28 and are superseded by
the October 29 provider retest and October 30 adoption receipt.

## Review evidence and limits

Independent review of immutable Framework source
`4d6392237781a68bbf39bfc8ce74a5340d31e9c3` is recorded in Sheguiandah's
`migration/production-2026-10-28/independent-review.md`. The reviewer approved
the SMTP authentication/reconnect behavior, sanitized failures, readiness
wiring, delegated authorization and session-generation revocation. The reviewed
source `4d639223...` is an ancestor of the later provider-role fix
`e5d77f88d1da1bc91a19180aa0fe717d23f6ab29` and coupled spec commit
`624c70328e07e9c41f362305370ebf07cd0909c8`, so that review source does not
contain those later changes. This source relationship does not establish when
the review itself occurred. The private receipt also reports that e5d77f88 was
independently approved, but provides no reviewer identity, exact bound verdict
or timestamp; the provider-role review gate remains unverified.

The Sheguiandah task thread reports that the provider-role binding fix passed
independent review and was proceeding through Framework CI before publication.
The report is in chat `Sheg launch`, thread
`01a163e7-b6e0-75b3-abf8-468ac5083614`, turn
`01a18416-cc2f-7da1-b7cf-1516971a2102`. No immutable verdict was located that
names the reviewer, binds the review to e5d77f88 or 624c703, or timestamps it
relative to main integration. The report is retrospective status evidence, not
proof of a pre-integration review. Timing relative to e5d77f88 integration at
2026-10-28 17:32:29 -04:00 cannot be established. Treat the provider-role review
as unverified until the Framework maintainer attaches the original receipt or
records that pre-integration review cannot be proven. The second coupled spec
is on `624c703`; no separate review receipt for it was located.

## Framework CI and split refs

Hosted Framework CI run [40773140959](https://github.com/waaseyaa/framework/actions/runs/40773140959)
and selected development split run
[40774854789](https://github.com/waaseyaa/framework/actions/runs/40774854789)
completed successfully at main `624c70328e07e9c41f362305370ebf07cd0909c8`.
A prior scoped development split run
[40769810031](https://github.com/waaseyaa/framework/actions/runs/40769810031)
recorded source `4d6392237781a68bbf39bfc8ce74a5340d31e9c3`, Mail main
`ec2a1731837e14615cbf8472bc1b05bd8e19c2a2`, and User main
`253ec1f498f174d23b745aa49ecb91ebc88be636`. The later selected split at
`624c703` recorded Foundation `fefc3d27d984aad10173407644fd0b1f384ab591`,
Routing `640e3d80863a50aba22236da51679861dde2ba51`, API
`c37d299548370a9735fe7d4dd00b5c4d96f2f402`, Database Legacy
`13cb2a9ccd6bfa3354e778f5dd97021be7b66d53`, and CLI
`f72f15f36590990612c3eea3e0503fde507922c7`. These are development `main`
refs, not release tags or release publication. The provenance artifacts were retrieved into the task evidence bundle. Their
primary GitHub artifact receipts remain linked above.

## Consumer adoption and operational boundary

Sheguiandah's independent adoption receipt records lock commit
`06a0b23551075f17966cb2b124fb7cec9cb06216`, application source
`2e283620cf123d961968fc411d46eba4b118645f`, Framework source `624c703...`,
and lock SHA-256
`d8d5bb2f6aaa7e4c4e0aad33793e3e058ae9a996475e14a5d7e61c802fdaaca3`. It
reports 788 installed runtime files matching the five-package cohort and
14 independent package tests / 117 assertions passing. Final consumer
qualification is recorded in
`migration/production-2026-10-28/clerk-mail-qualification.json`: two Stage-1
passes; CI 2,100 tests / 33,031 assertions; seal 2,310 tests / 35,043
assertions; browser acceptance; production-mode readiness using synthetic
loopback SMTP, with no delivery attempted. The consumer branch is prepared,
not deployed.

The October 29 retest from an isolated reviewed runtime on Web Networks' host
successfully delivered one operator test message after Sunwire's policy change.
Receipt: Sheguiandah `migration/mail-delivery-2026-10-29-retest/`. It does not
prove production-site activation or member-mail delivery. No production
deployment or DNS change is claimed. Release tag/publication remains separate.

## Historical candidate test receipt (2026-10-28)

The original candidate record used branch `codex/smtp-production-20261028`,
base `9cf95e395483f8faae37f64c24cba61623098209`, WSL Ubuntu, PHP 8.5.11 and
Symfony Mailer/Mime 8.1.10. Its focused command recorded 756 tests / 1,801
assertions passing. Loopback tests covered LOGIN and XOAUTH2, STARTTLS, TLS
versions, certificate and AUTH refusal, and reconnect authentication. Provider
tests exercised reset, verification and welcome dispatch. These counts and
cases describe the historical candidate run, not a rerun on Framework main.
No real Microsoft 365 submission occurred in that candidate run. The earlier
tenant authentication refusal was resolved for the isolated October 29 test
path; it is not current provider status. The implementation still does not
provide unattended OAuth token issuance or refresh.

This delivery record is reconciled under [FW-A36-DELIVERY-RECONCILIATION-01](../change-records/FW-A36-DELIVERY-RECONCILIATION-01.md).
