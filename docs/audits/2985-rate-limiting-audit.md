# #2985 audit lane — rate limiting

**Anchor:** #2985 (framework-wide competing-implementation audit)
**Lane:** rate limiting and attempt throttling
**Pinned commit:** `a61c62f5d80d1651ff8ed654c93d7d34382316cf`
**Branch / worktree:** `audit/2985-rate-limiting` — `fw-2985-ratelimit-audit`
**Mode:** read-only against runtime source. The only file added is a
characterization test (below); no runtime file was modified. **No repair is
implemented in this lane** — Codex owns repair integration.

## Method and evidence standard

Five research lanes traced the limiter surface; every load-bearing claim below
was then re-verified directly against source by the lane owner before being
recorded. Claims that rest only on a research lane's report are marked
*(reported, not independently re-verified)*. Claims that rest on an executed
test are marked **measured**; consequences that were reasoned but not executed
are marked **unmeasured** and stated as such rather than quantified.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| `Waaseyaa\Auth\DatabaseRateLimiter` (production-bound) | Yes | Full source read |
| `Waaseyaa\Auth\RateLimiter` (in-memory) | Yes | Confirmed not bound in production |
| `Waaseyaa\Foundation\RateLimit\{Database,InMemory}RateLimiter` | Yes | Source read; atomicity checked |
| All 12 `packages/auth/src/Controller/*.php` | Yes | Per-file limiter-reference census |
| `RateLimitMiddleware` + `http_security.rate_limit` wiring | Yes | Traced to `HttpKernel:649-653` |
| MCP / API content-search limiter reuse | Partial | Binding traced; runtime behaviour not probed |
| Queue `#[RateLimited]` / `AttributeGuard` | Partial | Deferred to the queue lane's finding |
| `AuthTokenRepository` consume paths (#2775) | Yes | Callers enumerated and verified |
| OIDC identity-provider flow | **No** | Out of lane |
| Studio identity/approval | **No** | Excluded by standing instruction |
| Session/token lifecycle | **No** | Covered by the prior session lane |
| Real multi-process concurrency behaviour | **No** | See "unmeasured" below |

## The intended policy — settled, not inferred

Closed issue **#763** (`feat(auth): apply rate limiting to POST /api/auth/login`)
is the origin of this code and states the contract explicitly:

- "Limit: 5 attempts per IP per minute (configurable)"
- "5 **failed** login attempts within 60 seconds → 429 on 6th attempt"
- "**Successful login does not count against the limit**"

So the answer to "five admitted verification attempts, or five failed attempts"
is **five failed attempts per IP per 60-second window**. The implementation
shape follows that intent faithfully: `hit()` is reached only from the two
failure branches (`LoginController.php:88`, `:96`) and success `clear()`s
(`:104`). The sequential control test below reproduces #763's acceptance
criterion exactly — `[401,401,401,401,401,429,429,429]`, a 429 on the 6th.

Two parts of #763 are **not** met at this commit:

1. **"(configurable)" was never implemented.** The `5` and the `60` are integer
   literals at `LoginController.php:43,88,96`. `AuthConfig` has no rate-limit
   field. Operators cannot tune login throttling.
2. **"does not count against the limit" was implemented as "erases the whole
   bucket".** Not counting a success is weaker than deleting every other
   account's recorded failures on the same IP. See F-3.

## F-1 — CONFIRMED (measured): the login gate admits more than five requests to credential verification

`LoginController::__invoke()` gates on a **pure read** and writes the counter
only afterwards:

- `:43` `tooManyAttempts($rateLimitKey, 5)` → `attempts($key) >= $maxAttempts`
  (`DatabaseRateLimiter.php:92-95`). No write.
- `:51-85` JSON decode → `findActiveByLogin()` → `password_verify()`. The last
  is deliberately slow by construction.
- `:88` / `:96` `hit($rateLimitKey, 60)` — the first write, on failure only.

Concurrent requests all read the same pre-increment count, all pass, and all
reach credential verification. This is a check-then-act window, not a defect in
the limiter: `DatabaseRateLimiter::hit()` increments atomically in SQL
(`:84-89`), and the sequential control confirms the gate is exact when writes
are serialized.

**Measured**, in `packages/auth/tests/Unit/Controller/LoginRateLimitInterleavingTest.php`
(4 tests, 18 assertions, 0.156s), against the production `DatabaseRateLimiter`
on real SQLite with a real user and real `password_verify()`:

- Bucket seeded to 4 — the intended policy permits **exactly one** further
  failed attempt in the window.
- **Eight** requests are admitted; **eight** return 401 (only reachable at
  `:92`, after `password_verify()` has run); **zero** are refused at the gate.
- After the in-flight writes land, the bucket reads **12** against a limit of 5,
  and the next request is correctly refused.

Concurrency is modelled deterministically rather than with threads: a decorator
forwards every read to the real limiter and holds `hit()` writes until an
explicit flush. That is precisely the state of N in-flight requests whose gate
reads have completed and whose counter writes have not yet committed — a
realizable interleaving. The post-flush assertions confirm every deferred write
did land, so the model neither invents nor loses counts.

**Unmeasured:** how far a real deployment exceeds five. The ceiling is set by
attacker concurrency, worker-pool size and DB contention, none of which this
lane probed. Failures still record, and sustained attack is still throttled at
5 per 60s, so this is a burst amplification of bounded size — not an unbounded
bypass. Quantifying it needs a real multi-process probe, which was not run.

## F-2 — CONFIRMED: changing the gate to `consume()` alone is not sufficient

The atomic primitive already exists and is already bound. `AuthServiceProvider.php:57-61`
binds **both** `AtomicRateLimiterInterface` and `RateLimiterInterface` to the
same `DatabaseRateLimiter` instance; `LoginController.php:27` simply injects the
non-atomic view of it. So the tempting repair is a one-line swap to
`consume($key, 5, 60)`. That is **not** policy-preserving, for three independent
reasons:

1. **It changes what is counted.** `consume()` increments at the gate, before
   the outcome is known — five *admitted* attempts, not five *failed* ones.
   That contradicts #763's "successful login does not count against the limit".
   The success-time `clear()` masks this for the common case but not under
   concurrency: five simultaneous **successful** logins from one NAT'd address
   would each consume, and the sixth would be refused before verification even
   though nothing failed. Today that cannot happen. Any repair that counts at
   the gate needs a compensating refund on success, a per-account key, or an
   explicit decision to change the policy.
2. **`clear()` remains a bypass regardless of atomicity.** See F-3 — it is
   sequential and needs no race at all.
3. **`consume()` is not portable.** See C-1.

## F-3 — CONFIRMED (measured): a success on one account erases failures recorded against another

The bucket is keyed by IP alone (`'login:' . $ip`, `LoginController.php:41`) and
`clear()` is an unconditional row DELETE (`DatabaseRateLimiter.php:112-119`).
One valid credential therefore zeroes the failure count accumulated against
every other account on that address.

**Measured** by `a_success_on_one_account_clears_failures_recorded_against_another`:
four failed attempts against `victim` (bucket = 4), one successful login as
`attacker` from the same IP, bucket = 0, and the budget against `victim` is
replenished in full. Entirely sequential — atomicity is irrelevant to it.

An attacker holding any one valid account on a shared address can interleave a
successful login between bursts and never be throttled. Shared addresses are
the normal case for NAT, office egress and VPN exits, which is also why the
IP-only key harms legitimate co-located users. **Unmeasured:** how common a
shared-egress deployment is in practice for this framework's installed base.

## F-4 — CONFIRMED: four credential-bearing endpoints have no limiter at all

Per-file census of all 12 auth controllers (count of `limiter|tooManyAttempts|->consume(|->hit(`):

| Controller | Limiter refs | Keying / limit |
|---|---|---|
| `LoginController` | 6 | `login:<ip>` — 5 / 60s |
| `ForgotPasswordController` | 6 | `forgot:email:<email>` 3/900s **and** `forgot:ip:<ip>` 10/3600s |
| `ResendVerificationController` | 6 | hashed email 3/3600s + ip 10/3600s — **atomic `consume()`** |
| `RegisterController` | 4 | `register:<ip>` — 5 / 900s |
| `VerifyTwoFactorController` | 4 | `2fa-verify:<ip>` — 5 / 60s |
| **`ResetPasswordController`** | **0** | reset-token submission, `allowAll()` route |
| **`VerifyEmailController`** | **0** | verification-token submission, `allowAll()` route |
| **`EnableTwoFactorController`** | **0** | session-gated |
| **`DisableTwoFactorController`** | **0** | session-gated |
| `SetupTwoFactorController` | 0 | no credential check performed |
| `MeController`, `LogoutController` | 0 | no credential check performed |

Severity is **not** uniform across the four, and should not be reported as if it
were:

- **`ResetPasswordController` / `VerifyEmailController`** submit a token that is
  a 256-bit random value compared as a SQL equality on an HMAC-SHA256 hash
  (`AuthTokenRepository.php:88-96`). Brute force is bounded by entropy, not by
  throttling. The practical concern is the absence of a cost ceiling on an
  unauthenticated, `allowAll()` endpoint, not token guessing.
- **`Disable`/`EnableTwoFactorController`** require an authenticated session
  (`_account instanceof User` → 401, `DisableTwoFactorController.php:27-33`).
  But `DisableTwoFactorController`'s own docblock states its threat model:
  *"This guards against an attacker with a hijacked session silently disabling
  2FA."* That guard is a 6-digit TOTP or a recovery code, checked by
  `TwoFactorService::verify()` with **no attempt limit**. TOTP replay protection
  (`TwoFactorService.php:106-113`) prevents reuse of a matched step but does not
  limit guessing. The control's stated purpose is materially weakened by the
  absence of a limiter — this is the sharpest of the four.
  Recovery-code checking additionally runs a linear `password_verify()` scan
  (`:120-129`), so unlimited attempts are also a CPU-cost amplifier.

Where a limiter *is* present on the 2FA surface, `VerifyTwoFactorController`
keys it by IP only (`2fa-verify:<ip>`), and TOTP and recovery codes share that
single bucket.

## F-5 — CONFIRMED: three different counting policies across one auth surface

- `LoginController` counts **failures only** (`hit()` on the failure branches).
- `ForgotPasswordController` counts **every attempt** — `hit()` at `:67-68` runs
  before the user lookup, unconditionally.
- `ResendVerificationController` counts **every attempt, atomically**.

These are three answers to the same question in one package. At least one is
likely deliberate, but no docblock states the reasoning, and #763 only ever
specified the login case.

## F-6 — CONFIRMED: the widest-exposure limiter is the weakest one

`Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter` backs `RateLimitMiddleware`,
which is **enabled by default** (`HttpKernel.php:721` defaults `enabled` to true)
at 60 req/60s per IP for *every* request. Its consume path is a genuine
read-modify-write in PHP — `$count = (int) $row['count'] + 1;` followed by a
separate `->update()` (`packages/foundation/src/RateLimit/DatabaseRateLimiter.php`,
lines shown in this lane's transcript) — unlike its same-named auth sibling,
which implements an atomic upsert for the identical purpose. Nothing in either
docblock explains why the global gate got the weaker implementation. This has
the shape of independent invention rather than a decision.

## F-7 — CONFIRMED: `ForgotPasswordController` does not normalize the email key

`:43` `$email = trim((string) ($body['email'] ?? ''));` — no `strtolower()` —
and `:57` `$emailKey = 'forgot:email:' . $email`. `Victim@example.com` and
`victim@example.com` occupy different buckets, multiplying the per-email budget
by case variation. `ResendVerificationController` does normalize (lowercase then
SHA-256, `:49,58`), so the correct pattern already exists one file away. The
per-IP bucket (10/3600s) still bounds the total.

## F-8 — CONFIRMED: #2775's token-spend migration is half-finished

`AuthTokenRepository` ships **both** an unguarded `consumeToken(int $tokenId): void`
(`:114-122` — no `consumed_at IS NULL` predicate, no expiry re-check, affected
rows discarded) and an atomic `consumeTokenIfAvailable()` (`:124-150` — all
invariants re-checked in one UPDATE, `execute() === 1` checked). Verified caller
census:

- Atomic: `EmailVerificationTransaction.php:39` only.
- Unguarded: `ResetPasswordController.php:62`, `RegisterController.php:158`.

So email verification was hardened and password reset and invite redemption were
not, reproducing the exact read-then-write window the atomic method's own
docblock warns callers against. Consequences differ: competing password writes
for reset (last-write-wins, both requests return 200); more than one account
minted from a single-use invite for register. *(Interleaving reasoned from
source, not executed — no concurrency probe was run for this finding.)*

## Corrected and disproved leads

- **`auth.rate_limit.max_attempts` does not exist.** A research lane reported
  this config key. The real key is `http_security.rate_limit.max_attempts`
  (`config/waaseyaa.php:78-84`, consumed at `HttpKernel.php:649-653`), which
  configures the *HTTP middleware*, not the login limiter. Login's limit is
  hardcoded and has no config namespace. Recorded as corrected, not as a finding.
- **`RateLimitMiddleware` is not orphaned.** #2490 (closed) concerned middleware
  the pipeline never instantiated. This one is instantiated and enabled by
  default; it becomes a no-op only on explicit `enabled: false`.
- **`AtomicRateLimiterAdapter` is not a competing implementation.** It delegates
  to the auth `DatabaseRateLimiter` via the kernel-services bus. Auth, API
  content-search and MCP all converge on one implementation and one
  `rate_limits` table — the good case, recorded to keep the finding honest.
- **`AuditedToolDispatcher` does not rate-limit.** No limiter symbol appears in
  `packages/ai-tools/src/Dispatch/`. Agent-tool throttling exists only at the
  MCP transport boundary. *(reported, spot-checked)*
- **`RateLimitException` in `packages/ai-agent` is not a limiter** — it is
  reaction to an upstream provider's 429. Not counted.
- **`ProvidersConfig`'s `rate_limit_per_min` appears unenforced** — declared and
  validated, no runtime reader found. *(reported, not independently
  re-verified; bounded to the paths that lane searched.)*

## Compatibility constraints on any repair

- **C-1: `consume()` is not MySQL-portable.** `DatabaseRateLimiter.php:44-47`
  emits `INSERT … ON CONFLICT (bucket_key) DO UPDATE`, which is SQLite/Postgres
  syntax. This is not a speculative concern: `MigrationRunState::upsert()`
  (`packages/migration/src/MigrationRunState.php:403-412`) documents removing
  *this exact pattern* because it "contradicted the schema layer's portability
  contract … the table is MySQL-portable but the write was not." Routing the
  login path onto `consume()` would newly expose login to a syntax error on
  MySQL deployments. Either fix the portability first or do not route login
  through it. *(Code-level claim; no MySQL deployment was exercised.)*
- **C-2: surface asymmetry.** `RateLimiterInterface` is `@internal`;
  `AtomicRateLimiterInterface` is `@api`. Only `consume()` carries an atomicity
  contract — the five inherited methods carry none even on the "Atomic"
  interface. Adding a method to the `@api` interface is a breaking change for
  implementors under the stability charter.
- **C-3: policy change is observable.** Any move to gate-time counting changes
  behaviour for shared-egress users. It needs an explicit decision against
  #763's stated contract, not a silent swap.
- **C-4: failure mode is fail-closed-by-exception.** No auth controller wraps a
  limiter call in try/catch; a DB outage yields an uncaught exception (500), not
  an allow. Content-search and MCP by contrast catch explicitly and return 503.
  A repair should not accidentally convert the auth path to fail-open.

## Focused acceptance criteria

For F-1 / F-2 (whoever owns the repair):

1. Under the interleaving modelled by `LoginRateLimitInterleavingTest`, at most
   five requests reach credential verification in one window. The existing
   characterization assertions must be **inverted**, not deleted.
2. The sequential control still yields `[401,401,401,401,401,429,429,429]` —
   #763's acceptance criterion is preserved exactly.
3. A successful login still does not consume budget, including when several
   successful logins from one IP overlap.
4. F-3 is addressed explicitly: state whether success clears the whole IP bucket
   or only the succeeding principal's contribution, and test the cross-account
   case either way.
5. No new `ON CONFLICT` dependency on the login path unless C-1 is resolved first.
6. Behaviour on limiter/DB failure remains fail-closed.

For F-4: each of the four unlimited endpoints gets an explicit disposition —
limiter added, or a recorded decision that entropy/session-gating is the
intended control. `DisableTwoFactorController` should be decided first.

For F-8: `ResetPasswordController` and `RegisterController` move to
`consumeTokenIfAvailable()`, with a test proving a second concurrent spend of
one token fails.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#763** | closed | Origin of the login limiter; **authority for the intended policy**. Its "(configurable)" criterion is unmet, and its "does not count against the limit" criterion is implemented as a whole-bucket delete (F-3). |
| **#2775** | open, p0, `status:blocked`, `release:beta-blocker` | F-8 is directly in scope: the caller census above identifies exactly which two callers remain unguarded. |
| **#2769** | open, `release:beta-blocker` | Adjacent: the synchronous-mail timing side channel on the same public endpoints this lane censused. Not re-litigated here. |
| **#2490** | closed | Corrected above — not the same defect; this middleware is wired. |
| **#2985** | open | This lane's anchor. |
| **#517** | closed | Queue-side rate limits; deferred to the queue lane. |

No new issue is filed by this lane. F-1/F-2/F-3 are new findings without an
existing home and need one; that filing decision belongs with the anchor owner,
since #2775 is already p0-blocked and should not absorb an unrelated defect.

## Residual work not covered

- Real multi-process concurrency measurement of F-1's ceiling.
- OIDC identity-provider flow; Studio identity/approval (excluded).
- Queue `AttributeGuard` behaviour under `DbalQueue` (queue lane).
- Whether `ProvidersConfig.rate_limit_per_min` has any consumer outside the
  three packages searched.
- MCP and content-search limiter behaviour at runtime (bindings traced only).

## Recommended next disjoint lane

**Cache invalidation and tag propagation.** It is disjoint from every lane run
so far (media, queue, authorization, config, session, rate limiting), it spans
the same cross-layer listener seam the layer rules single out as exempt, and the
listing/cache-tag conventions in `docs/conventions/cache-tags-and-contexts.md`
give an intended contract to audit actual behaviour against.
