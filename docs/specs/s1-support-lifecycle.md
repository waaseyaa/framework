# S1 support and lifecycle contract

Status: proposed for S0-FW-02  
Contract version: `s1-v1`  
Anchor: waaseyaa/framework#2336 (`F-REL-002`)

## Purpose

Waaseyaa must not imply platform support from a dependency constraint or a green
job on an unbounded hosted runner. This contract defines the one deployable
profile whose framework test point is supported, separates that point from the
pending consumer certification point, and gives every declared axis an owner,
boundary, evidence source, and review horizon.

This is a bounded technical support contract for a pre-1.0 alpha. It is not a
commercial availability, response-time, or backward-compatibility SLA. The word
"enterprise" does not expand the contract.

## Profile status

`S1` is the only candidate production profile. Framework conformance is tested
by this repository. End-to-end consumer certification remains pending
`S0-SHEG-02`; until that evidence exists, S1 is a candidate and must not be
described as fully certified.

`H1` is unsupported. No claim is made for horizontal multi-node serving,
shared/remote filesystems, external cache coordination, or non-SQLite database
operation.

## Normative support matrix

| Axis | S1 boundary | Role | Evidence |
|---|---|---|---|
| PHP | `>=8.5.0 <8.6.0`, latest available 8.5 patch | serving and CLI runtime | Composer platform constraint plus an explicit PHP 8.5 conformance job |
| Composer | exact stable `2.x` feature line recorded in the contract (currently 2.10); exact patch recorded by CI | dependency resolution/build tool, not serving runtime | contract checker and CI version output |
| Node.js | `>=24.0.0 <25.0.0`, Node 24 LTS | Admin SPA build/test tool, not serving runtime | `.nvmrc`, package engine, explicit conformance job |
| SQLite | `>=3.40.0 <4.0.0` | S1 database runtime | real runtime assertion plus compiler-boundary fixtures at documented SQLite capability checkpoints |
| Framework CI OS | Ubuntu 24.04, x86-64 | authoritative framework test point | explicit `ubuntu-24.04` runner and environment evidence |
| Consumer OS | Ubuntu 24.04, x86-64 | pending S1 consumer certification point | `S0-SHEG-02` consumer evidence; not yet certified here |
| Filesystem | local ext4 with POSIX rename, locking, permissions, and durable-write semantics | pending S1 consumer certification point | `S0-SHEG-02`; NFS, SMB, object-mounted, and clustered filesystems unsupported |
| Web runtime | Apache 2.4 with PHP-FPM 8.5 | pending S1 consumer certification point | `S0-SHEG-02`; other servers or SAPIs are unclaimed |
| Admin browsers | Chromium and Firefox revisions installed by the exact locked Playwright release | browser runtime | production-shaped Playwright smoke for both projects |

The Playwright lock, not a branded browser marketing version, defines the tested
browser revisions. WebKit/Safari and branded-browser-specific behavior are not
supported by this contract.

MySQL and PostgreSQL references are compatibility aspirations, not S1 support.
They remain outside this contract until the separate database conformance finding
is closed with real driver evidence.

## Lifecycle policy

- Only the newest tagged alpha receives fixes. Fixes ship forward in a new,
  immutable tag; alpha releases are not patched in place.
- The preceding three alpha trains receive documented upgrade treatment under
  the stability charter. That window is an upgrade-path commitment, not a
  backport or compatibility promise.
- Older alpha trains are end-of-life. A security remediation may require an
  upgrade to the newest tag.
- There is no response-time, uptime, or commercial support SLA.
- A dependency reaching end-of-life does not silently widen or freeze support.
  The contract must be revised and re-verified before the affected point moves.

The current upstream planning horizons recorded in `support/s1-v1.json` are:

- PHP 8.5 active support through 2027-12-31 and security support through
  2029-12-31.
- Node.js 24 maintenance begins 2026-10-20 and reaches end-of-life 2028-04-30.
- Ubuntu 24.04 standard security maintenance runs through 2029-05.
- SQLite security servicing is supplied through the supported Ubuntu package
  source; upstream SQLite does not publish an equivalent fixed LTS window.

These dates are planning inputs, not evidence that an arbitrary patch is safe.
They must be reviewed at least quarterly, at every tagged release, and no later
than 90 days before the earliest recorded support-reducing or end-of-life
transition. A move from active to maintenance support that does not reduce the
declared S1 point is still recorded, but is not itself the notice deadline.

## Machine-readable authority and fail-closed parity

`support/s1-v1.json` is the machine-readable authority. Human documentation may
explain it but may not widen it. `bin/check-support-contract` must fail when:

- the contract is malformed, expired for review, or declares a profile other
  than S1 as supported;
- PHP, Node, SQLite, OS, or browser declarations drift from executable
  constraints and CI test points;
- any authoritative CI job uses a floating OS label or omits version evidence;
- the consumer certification point is presented as complete before its evidence
  reference is populated; or
- documentation claims H1, WebKit/Safari, remote filesystems, MySQL, or
  PostgreSQL support.

CI validates repository parity. It does not query mutable upstream web pages and
does not turn hosted-runner metadata into consumer certification.

## Change control

Any boundary expansion or lifecycle change requires a stable, forge-neutral
change record, an updated contract version or revision, a red conformance test,
matching executable evidence, changelog and upgrade-impact review, and
independent review approval. A forge issue and pull request may mirror that
record but cannot replace it.
Removing a supported point is a breaking change under the pre-1.0 policy.
