# F9 — account-active enforcement is absent without `waaseyaa/auth`

Handoff for Codex. **Read-only audit artifact: no runtime change is proposed
here and none was made.** The reproducer is a standalone script, not a test,
and is not wired into any suite.

## Versions

| | |
|---|---|
| Source SHA | `a55fba00be75e3e0f0aaf8fc1dedd8e699c66d36` (audit branch, tree identical to `origin/main` @ `9c14c7eed` for all packages under test) |
| `VERSION` | `0.1.0-alpha.300` |
| PHP | 8.5.8 |
| Packages exercised | `waaseyaa/user`, `waaseyaa/access`, `waaseyaa/foundation`, `waaseyaa/entity`, and `waaseyaa/auth` **only in control case B** |

## What was reproduced

`docs/audits/2985-f9/f9-reproducer.php` drives the **real**
`Waaseyaa\User\Middleware\SessionMiddleware` twice, changing exactly one
variable: whether the eligibility policy from `waaseyaa/auth` is wired.

Fixture in both cases, identical:
- a **persisted** `User`, `uid = 42`, **`status = 0` (disabled)**;
- `session_generation = 7` on the user, **unchanged** — no revocation signal;
- a valid session carrying `uid = 42` and generation `7`, so the generation
  check passes;
- a `UserInternalFieldReaderInterface` reporting the persisted truth:
  `verification()->active = false`, `sessionIdentity()->generation = 7`.

Run with `php docs/audits/2985-f9/f9-reproducer.php` from a worktree with
`composer install` completed.

### Observed output

```
Disabled user (status=0), session_generation UNCHANGED at 7, valid session for uid 42

A. no waaseyaa/auth (null policy)  => User          | authenticated=true  | id=42 | _authenticated route: ALLOWED
B. waaseyaa/auth installed         => AnonymousUser | authenticated=false | id=0  | _authenticated route: unauthenticated(401)
```

The second column is what `SessionMiddleware` places in `_account`. The last
column is the verdict from the **real** `Waaseyaa\Access\AccessChecker` for a
route declaring `_authenticated => true`.

## What this proves, and what it does not

**Proven.** With no eligibility policy wired, a **disabled** account resolves to
an authenticated `User`, and a route requiring authentication **admits it**.
With the policy wired, the identical request is refused 401. The only
difference between the two runs is the presence of that policy.

**Not proven.** This does **not** demonstrate downstream data access. No
protected resource was fetched, no entity policy was evaluated, and no write was
performed. Entity-level authorization still applies afterwards and would
evaluate this account's roles — which, for a disabled user, are whatever roles
the row still carries. Establishing real data exposure needs an end-to-end
request against a protected resource, which this audit did not do.

**Distinguish the two carefully:** this is *missing-policy behaviour with a
demonstrated route-level consequence*, not *proven unauthorized data access*.

## Why the configuration is reachable

`HttpKernel` resolves the policy by **string FQCN** and, on failure, sets it to
`null`, raising only when `auth.require_verified_email` was explicitly
configured truthy (`packages/foundation/src/Kernel/HttpKernel.php:616-625`).
`SessionMiddleware` guards both call sites behind that null — the existing
session branch (`:248-251`) and the bearer branch (`:96-101`).

Verified metapackage graph:

| Metapackage | requires `waaseyaa/auth`? |
|---|---|
| `core` | **no** (requires `waaseyaa/user`) |
| `cms` | **no** |
| `full` | **no** |

Only the root skeleton (`waaseyaa/framework`) pulls `waaseyaa/auth`. A consumer
on a curated metapackage that issues sessions on `waaseyaa/user`'s own
`AuthenticatedSession` lands in case A. This audit did **not** establish that
any real consumer is in that configuration.

## Bearer behaviour, traced separately

- The **generic** bearer path shares the same conditionality: the eligibility
  gate at `SessionMiddleware.php:96-101` is guarded by the same null check, so
  in case A a bearer-resolved disabled account is also not re-checked.
- The **MCP durable** path is unaffected: `DurableBearerTokenAuth` re-loads the
  owner with an explicit `'status' => 1` condition
  (`packages/mcp/src/Auth/DurableBearerTokenAuth.php:103-107`), independent of
  the eligibility policy. MCP is protected in both cases.
- Neither path consults `session_generation` at all, so this is orthogonal to
  password-reset revocation (finding F1).

## Smallest canonical repair — recommendation, not a change

**Make the active-account check unconditional in `SessionMiddleware`; leave
verified-email in the optional policy.**

The data required is already guaranteed present. `SessionMiddleware` holds a
`UserInternalFieldReaderInterface`, and `HttpKernel` **throws** when it cannot
resolve one — *"The HTTP pipeline requires the audited User internal-field
reader"* (`HttpKernel.php:612-614`). So `$this->internalFields->verification($user)->active`
is available on every production pipeline, including one without
`waaseyaa/auth`.

Repair boundary — **one file**, `packages/user/src/Middleware/SessionMiddleware.php`:

1. In the existing-session branch, immediately after the generation comparison
   and **before** the optional eligibility gate, refuse when
   `verification($user)->active` is false.
2. Apply the same check in the bearer branch (`:96-101`), before its eligibility
   gate.
3. Leave `VerifiedEmailAuthenticationEligibility` unchanged and still optional;
   its own active check becomes redundant but harmless defence in depth.

This adds **no new interface and no second eligibility implementation** — the
explicit constraint on this handoff. It moves a mandatory invariant next to the
middleware that enforces it, and leaves the genuinely optional policy
(verified-email) optional.

**Alternatives considered and not recommended.** Failing the kernel closed when
no policy resolves would break every metapackage consumer that never wanted
`waaseyaa/auth`. Moving the whole policy class into `waaseyaa/user` would drag
`AuthConfig` and the verified-email concern down with it, widening `user`'s
surface for one boolean.

## Repair scope — do not broaden

The patch is **only** unconditional active-account enforcement, in both
resolution paths. Explicitly **out of scope** for this repair, each tracked
separately in the lane report:

- **F3** session expiry — no framework-enforced lifetime. Separate decision.
- **F1 / F2** bearer revocation and the two bearer mechanisms. Separate.
- **F7** the `/graphql` CSRF question. Separate, and still conditional.

Bundling any of these would make the patch unreviewable and would couple a
mandatory invariant to three undecided design questions.

## Qualification matrix — required

Four cases. The first three are the agreed acceptance set; the fourth is the
no-regression control.

| # | Account | Eligibility policy | Required result | Status today |
|---|---|---|---|---|
| 1 | **inactive** (`status = 0`) | **not wired** | refused → `AnonymousUser` | **fails** — resolves authenticated, route ALLOWED |
| 2 | **active** (`status = 1`) | **not wired** | succeeds → authenticated `User` | passes; must not regress |
| 3 | active, email **unverified**, `require_verified_email = true` | wired | refused → `AnonymousUser` | passes; must not regress |
| 4 | active, email verified | wired | succeeds → authenticated `User` | passes; must not regress |

Case 2 is the one that makes this a real qualification rather than a
one-directional assertion: a repair that refuses *everything* when the policy
is absent would satisfy case 1 and break every metapackage consumer. Case 3
proves the optional verified-email policy is untouched.

Each case must be exercised **in both resolution paths** — the existing-session
branch and the generic bearer branch — since the repair changes both. The MCP
durable path needs no case: it re-queries `status = 1` independently and is
unaffected either way.

A test suite asserting only case 1 would pass after a repair that is too broad;
a suite asserting only cases 3–4 would have passed before the defect existed.
