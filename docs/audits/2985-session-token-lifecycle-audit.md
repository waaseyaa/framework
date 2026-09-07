# #2985 — session and token lifecycle audit

Audit lane of **#2985**, anchored to open leads **#2816** and **#2769**.
Read-only: no runtime change, no class removal, no public-surface
reclassification, no test suites run.

- **Pinned source SHA:** `9c14c7eedf41c1678b27da24cc92089fb8cb8cd6` (`origin/main`)
- **Excluded:** in-flight Studio identity/approval work and the OAuth
  identity-provider flow (#2978, #2766, #2979). Cross-boundary notes in §8.

### Scope of every claim

Bounded static audit, **not a complete security assessment**. Negative claims
mean *"no such path was found by the searches recorded here"* over first-party
`packages/*/src` at one commit. They do not cover consumer applications,
published package versions differing from this tree, host `php.ini`, dynamic
dispatch, or reflection. Treat them as leads about where to look.

## Headline

**No confirmed authorization failure.** Two suspicions were chased to ground and
**both were disproved** (§3). The substantive results are a documented boundary
whose *security policy* has never been decided (§4 F1), and two structurally
different bearer mechanisms in production with materially different postures
(§4 F2).

## 1. Coverage matrix

| Area | Reviewed | Depth | Notes |
|---|---|---|---|
| Session issuance, cookie flags, fixation | ✅ | Full | |
| Session identity and per-request validation | ✅ | Full | |
| Session expiry | ✅ | Full | |
| Concurrent sessions / enumeration | ✅ | Full | |
| Bearer issuance, storage, verification, expiry, replay, scope | ✅ | Both mechanisms | |
| Account disablement enforcement | ✅ | Both paths, plus the wiring condition (F9) | |
| Generation-based revocation | ✅ | Full | |
| Existing issue and record coverage | ✅ | Full | |
| CSRF | ✅ | Full: generation, verification, per-surface scope, exemptions, SameSite | |
| **Rotation on privilege change** | ⚠️ Partial | No mechanism found | Lane did not return |
| **2FA lifecycle** | ❌ Not reviewed | — | Shipped per `two-factor-auth.md`; out of lane |
| **OAuth provider / MCP OAuth** | ❌ Not reviewed | — | Excluded / #1640 |
| Test suites | ❌ Not run | — | Per scope |

The revocation lane did not return; its ground was covered by my own
verification (§3 D1, §4 F1) and the issuance lane. Rotation-on-privilege-change
remains unsearched.

## 2. The lifecycle, as it actually runs

**Issuance.** `LoginController.php:79-85` verifies credentials, then
`AuthenticatedSession::issue($user, $identity->generation)` (`:134`) followed by
`session_regenerate_id(true)` (`:135`). Regeneration also occurs on
registration auto-login (`RegisterController.php:192`), 2FA completion
(`VerifyTwoFactorController.php:118`) and password reset
(`ResetPasswordController.php:66`). **Session-fixation defence is present on
every authentication transition.**

**Session identity** is exactly two keys —
`waaseyaa_uid` and `waaseyaa_session_generation`
(`packages/user/src/Session/AuthenticatedSession.php:12-13`). The generation is
a field on the **user**, not per session.

**Cookie policy.** `SessionMiddleware::applySessionCookieIni` (`:132-146`) via
`SessionCookiePolicy` (`:30-35`): `httponly=true`, `secure` auto-detected,
`samesite=Lax`, `use_strict_mode=true`. Lifetime, path and domain are **not
set** and fall to host `php.ini`.

**Per-request validation** (`SessionMiddleware::resolveAccount`, `:214-261`):
uid present → generation present and integral → user loads → **stored generation
compared to the user's live `session_generation`** (`:241-246`), mismatch clears
identity and logs a revoked-stale-session event → then the eligibility gate
(`:248-255`). If a higher-priority bearer middleware already set `_account`, the
session path short-circuits entirely (`:95-112`).

## 3. Disproved — two suspicions chased to ground

### D1 — Account disablement *is* enforced, on both paths
An earlier reading suggested blocking an account might not invalidate live
sessions, since `SessionMiddleware` never consults an `isActive()`/status field
and the only `AuthenticationEligibilityInterface` implementation is named
`VerifiedEmailAuthenticationEligibility`. **Inferring from the class name was
wrong.** Its docblock reads *"Canonical **active-account** and verified-email
authentication policy"* and `allows()` checks `$verification->active` **first**,
returning false for an inactive account before the email check
(`packages/auth/src/Authentication/VerifiedEmailAuthenticationEligibility.php:21-31`).

`SessionMiddleware` invokes it on **both** paths every request —
`AuthenticationStage::BearerResolution` (`:99-100`) and
`AuthenticationStage::ExistingSession` (`:249-250`) — downgrading to
`AnonymousUser` on rejection. The durable bearer path independently re-loads the
owner with `'status' => 1` (`DurableBearerTokenAuth.php:103-107`).

**Disablement takes effect on the next request — but only when `waaseyaa/auth`
is installed.** That condition is not incidental; see F9, which qualifies this
result. D1 disproves "disablement is never enforced"; it does not establish
"disablement is always enforced".

### D2 — Token storage and comparison are correct
`AuthTokenRepository` stores `hash_hmac('sha256', $plain, $secret)` — a keyed
hash, never the raw token (`:65,71,88`). `DatabaseBearerTokenStore` stores
`hash('sha256', $secret)` (`:325`), compares with `hash_equals` (`:107`), levels
timing for unknown ids with a `DUMMY_HASH` compare (`:47,99-109`), and compares
audience with `hash_equals` (`:115`). The plaintext secret is returned once via
`IssuedBearerToken`, which resists serialization (`:24-66`).

## 4. Confirmed findings

### F1 — Password reset revokes sessions but not bearer access; the boundary is documented, the *policy* is not
**CONFIRMED. Documented mechanical boundary; undecided security posture.**

`ResetPasswordController.php:56-58` reads the generation, increments it, and
saves it with the new password hash — invalidating every prior session on its
next request. The bearer path never consults the generation: a search for
`generation` across `BearerAuthMiddleware.php` and `DatabaseBearerTokenStore.php`
returns **nothing**, and `ResetPasswordController` contains no bearer revocation
(searched `bearer|Bearer|revokeAll|BearerTokenStore` — no match).

This is **documented**: `docs/change-records/FW-AUTH-SESSION-REVOCATION-01.md:17`
states *"Bearer-authenticated requests are unaffected because
`BearerAuthMiddleware` resolves their account before the PHP-session path."*

So this is not an undiscovered defect, and it should not be reported as one.
But the record documents the **mechanism**, not the **policy**. Nothing states
that "a user resetting a compromised password should retain live API access" is
intended. For a user resetting *because* they were compromised, browser access
is revoked and API access is not, and no compensating control was found. That is
a design question worth deciding explicitly — the same shape as the queue
audit's `#[UniqueJob]` limitation: documented, therefore not a defect, but worth
a decision rather than inheritance.

### F2 — Two structurally different bearer mechanisms, both in production
**CONFIRMED. The #2985 competing-implementation result for this lane.**

| | Path A — `BearerAuthMiddleware` | Path B — `DatabaseBearerTokenStore` |
|---|---|---|
| Wired | Every HTTP request, priority 40 | MCP write/public tiers + `bearer-token:*` CLI |
| Form | Externally-minted JWT, or static config key | Opaque `mbt_<id>.<256-bit secret>` |
| Storage | JWT stateless; static keys **plaintext in config** | `secret_hash` only, unique-indexed |
| Comparison | `hash_equals` for JWT (`:114`); static keys via `isset($this->apiKeys[$token])` (`:57`) — **not constant-time** |`hash_equals` + dummy compare |
| Expiry | Caller-supplied `exp`; **absent `exp` means never expires** (`:118-124`) | Mandatory, checked inline; default 30d, bounded 60s–90d |
| Scope/audience | None — full account authority | Mandatory scopes + audience, fail-closed |
| Revocation | **None found** | Per-token, and account status re-checked each use |

Both are live. A caller reasoning about "the framework's bearer token" gets
materially different guarantees depending on which path serves the request.
Path B is the careful implementation; Path A is the one on every HTTP request.

Recorded as a **structural finding**, not an exploit claim: Path A's weaknesses
are only reachable by a deployment that configures `jwt_secret` or `api_keys`,
and I did not establish what a default deployment configures. `BearerTokenAuth`
(the MCP static map) is already marked *"Superseded for production"* in its own
docblock (`packages/mcp/src/Auth/BearerTokenAuth.php:13-19`); Path A carries no
equivalent marker.

### F3 — No framework-enforced session expiry
**CONFIRMED.** `rg` for `gc_maxlifetime|cookie_lifetime` across `packages/` and
`public/` returns **zero hits**. There is no idle-timeout (no last-activity
timestamp is written to `$_SESSION`), no absolute lifetime, and no custom save
handler. Expiry is entirely PHP's probabilistic `session.gc_maxlifetime` GC plus
the host's `session.cookie_lifetime` (commonly `0`, a browser-session cookie).

That is a materially weaker guarantee than an enforced lifetime, and it is host
`php.ini`-dependent rather than framework-controlled — so two deployments of the
same framework version can differ. Recorded because `docs/specs/auth-consumer-extensions.md`
states the framework owns sessions.

### F4 — No per-session record; revocation is all-or-nothing
**CONFIRMED.** No persisted per-session entity exists (`UserSession` is a
per-request DTO, `:15-38`). Because the generation counter is per **user**, all
of an account's sessions live or die together. There is **no way to enumerate,
count, or selectively revoke** one device's session — no operator command and no
self-service surface was found.

This is the mechanism behind #2816's "revocation fail-closed" acceptance
criterion and is worth stating plainly: "sign out my other devices" is not
expressible today.

### F5 — Bearer tokens carry no tenant scope
**CONFIRMED, and it is exactly what #2816 proposes to add.**
`AuthorizationPrincipalInterface::tenantId()` exists
(`packages/access/src/AuthorizationPrincipalInterface.php:16`), and
`AccountPrincipalFactory::fromAccountInContext($account, ?tenantId, ?communityId)`
can populate it — but the durable bearer path calls `fromAccount()`, which
passes **null** (`AccountPrincipalFactory.php:16-19`), and `BearerTokenRecord`
has no tenant field. So #2816 accurately describes a proposal, not a regression.

### F6 — Token scope is a ceiling, not the authority
**CONFIRMED, and the distinction matters for revocation.** Path B re-resolves the
principal from the live account each request
(`DurableBearerTokenAuth.php:103-122`), so permission *reductions* on the account
take effect immediately; but token `scopes` are baked in at issuance and are an
additional ceiling, so narrowing a token's scope requires rotating it.

### F7 — `/graphql` CSRF rests on a single control
**CONFIRMED code facts; NOT exploitable at default configuration.**
Verified directly, because it is the most security-sensitive claim here.

Three facts, each checked:
1. `/graphql` is registered `->allowAll()->methods('GET','POST')->csrfExempt()`
   (`packages/graphql/src/GraphQlRouteProvider.php:17-24`), so `CsrfMiddleware`
   does not check it.
2. `GraphQlEndpoint` contains **no `Content-Type` check anywhere** — `parseRequest()`
   `json_decode`s a POST body unconditionally (`:241-276`). So the content-type
   reasoning that justifies the JSON:API exemption does not hold here.
3. Its mutation gate is *authentication* (`$this->account->isAuthenticated()`,
   `:132-137`), and for a browser client that account comes from the session
   cookie.

Elsewhere the framework has **two** independent controls: a CSRF token, or
(for JSON:API) a non-simple content type *plus* a CORS preflight that cannot
succeed because `Access-Control-Allow-Credentials` is never emitted. On
`/graphql` both are switched off, leaving **`SameSite=Lax`** as the only
control (`packages/user/src/Session/SessionCookiePolicy.php:30-35`).

**Exposure is UNRESOLVED — an earlier revision of this report concluded
`SameSite=Lax` made it non-exploitable. That conclusion is withdrawn.**
`SameSite=Lax` is a *default*, not a guarantee: `SessionCookiePolicy::sameSite()`
explicitly accepts `'none'` as a valid configured value
(`packages/user/src/Session/SessionCookiePolicy.php:83-89`), and cross-site
embedding is a normal reason to set it. Resting a non-exploitability claim on a
configurable cookie attribute is exactly the reasoning error this audit
programme has had to withdraw twice before.

What is **confirmed**: the route is `csrfExempt()`; `parseRequest()` POST-decodes
the body with `json_decode` and **no `Content-Type` inspection anywhere**
(`:241-276`); the mutation gate is authentication only (`:132-137`); and CORS is
an exact-match allowlist defaulting to `localhost:3000`/`127.0.0.1:3000` that
never emits `Access-Control-Allow-Credentials`
(`packages/foundation/src/Http/CorsHandler.php:25,61`).

What is **not established**, and would be required before claiming either
exploitability or safety:
1. that a mutation actually **executes** to completion via a `text/plain`-shaped
   body — only `parseRequest()` was traced, not the resolver path;
2. that a session cookie actually **attaches** to such a cross-site request in a
   target deployment — this is the `SameSite` question, and it is
   deployment-dependent, not framework-fixed;
3. what `SameSite`, origin allowlist and embedding posture real deployments
   actually configure.

Until those are answered, treat this as **conditional, unproven exposure with
confirmed code facts** — not as safe, and not as a demonstrated vulnerability.
The narrow correction (a content-type check in `parseRequest()`, or dropping
`csrfExempt()` so the existing JSON allowlist decides) is cheap enough that it
does not need the question resolved first.

Recorded as **defence-in-depth reduced to one deployment-configurable control on
one route, with exploitability unresolved**.

### F8 — CSRF is otherwise correctly scoped
**CONFIRMED, recorded as a strength.** `CsrfMiddleware` is global middleware at
priority 20, enforced by default for POST/PUT/PATCH/DELETE with explicit
per-route opt-out, `bin2hex(random_bytes(32))` tokens in the session, and
`hash_equals` for all three token sources
(`packages/user/src/Middleware/CsrfMiddleware.php:18,26,170,194-206,229-260`).
Bearer-authenticated MCP write routes are exempt, which is *correct* — no
cookie, no CSRF exposure. And the framework demonstrably distinguishes
"JSON but still cookie-reachable" from "JSON and safe": approval and
page-builder routes call `->requireCsrf()` despite JSON bodies
(`packages/api/src/ApiServiceProvider.php:686,708`;
`packages/admin-surface/src/AdminSurfaceServiceProvider.php:402,409,429`).

### F9 — Disablement enforcement is silently absent without `waaseyaa/auth`
**CONFIRMED. Conditional on a supported metapackage configuration.**

The only production `AuthenticationEligibilityInterface` implementation,
`VerifiedEmailAuthenticationEligibility`, ships in **`waaseyaa/auth`**.
`HttpKernel` resolves it **by string FQCN** and, when it cannot, sets
`$authenticationEligibility = null` (`packages/foundation/src/Kernel/HttpKernel.php:616-625`).
It raises only if `auth.require_verified_email` is explicitly configured
truthy — so in the ordinary case the control simply becomes absent, with no
error and no log line. `SessionMiddleware` then skips both checks, because each
is guarded by `$this->authenticationEligibility !== null` (`:96-101`, `:248-251`).

The metapackage graph makes that reachable. Verified directly:

| Metapackage | requires `waaseyaa/auth`? | requires `waaseyaa/user`? |
|---|---|---|
| `core` | **no** | yes |
| `cms` | **no** (inherits `user` via `core`) | — |
| `full` | **no** (inherits `user` via `core`) | — |

Only the root `composer.json` — the dev skeleton / `waaseyaa/framework` — pulls
`waaseyaa/auth`.

So a consumer installing `core`, `cms` or `full` and issuing sessions itself on
`waaseyaa/user`'s `AuthenticatedSession` gets a pipeline where the account
`status` (Active) field is **never consulted** for live sessions or
bearer-resolved accounts. `SessionMiddleware::resolveAccount` checks only
`session_generation`, and nothing but password reset ever bumps that (F1, and
the revocation lane's independent search for role-change or admin-disablement
writers found none).

**Scope, stated carefully.** This is not exploitable on a default framework
install, because the skeleton requires `waaseyaa/auth`. It requires a consumer
to build authentication on `waaseyaa/user` without `waaseyaa/auth` — plausible,
since `AuthenticatedSession` and `SessionMiddleware` both live in `user`, but
not the documented path. This audit did **not** establish that any real consumer
is in that configuration.

**Why it is still worth raising:** the degradation is *silent*. A security
control disappears because a package is absent, and the only signal is an
exception in the one case where a *different* setting
(`auth.require_verified_email`) was explicitly turned on. A control that fails
open quietly, keyed on package presence, is the shape worth fixing regardless of
whether anyone is currently exposed — for example by failing closed when a
session-issuing surface is present without an eligibility policy, or by moving
the canonical policy into `waaseyaa/user` beside the middleware that needs it.

This finding is why D1 is stated as a qualified disproof rather than a clean
one.

## 5. Existing issue coverage

| Issue | State | Relationship | Evidence |
|---|---|---|---|
| **#2816** | OPEN p1 blocker | **Confirmed as unimplemented, accurately scoped.** F5 (no tenant binding) and F4 (no per-session revocation) are precisely its two halves | `AccountPrincipalFactory.php:16-19`; no `tenantId` on `BearerTokenRecord` |
| **#2769** | OPEN blocker | Not traced — mail-outbox concern, adjacent to this lane | recon |
| #2700 | CLOSED | Shipped the generation mechanism; its record documents F1's boundary | `FW-AUTH-SESSION-REVOCATION-01.md:17` |
| #2757 | CLOSED | Shipped the eligibility gate that closes D1 | `VerifiedEmailAuthenticationEligibility` |
| #2276 | CLOSED | Scoped bearer tokens — Path B | — |
| #2699 / #2775 | OPEN | Secure local token delivery; atomic token spending — adjacent, not traced | recon |
| #2149 / #2146 / #2154 | CLOSED | Prior CSRF/session-cookie iterations; F7 is adjacent ground worth re-checking against them | recon |
| **F9** | — | **No owning issue. Highest-priority item in this lane** — a silently absent security control keyed on package presence | `HttpKernel.php:616-625`; metapackage graph |
| **F1, F2, F3, F7** | — | **No owning issue.** F7 and F2 next; F1 is a decision for #2816's design; F4 is already inside #2816 | — |

## 6. Compatibility constraints

- `BearerTokenStoreInterface` and `AuthenticationEligibilityInterface` are
  declared `public`. Signature changes are semver events.
- **Enforcing a session lifetime (F3) would log users out** who are currently
  kept alive by a permissive host `php.ini` — a behavioural change requiring a
  deprecation note even though it strengthens the default.
- **Revoking bearer tokens on password reset (F1) would break integrations**
  that reasonably rely on today's documented behaviour. It is a policy change,
  not a bug fix, and needs an announced migration.
- Retiring or hardening Path A (F2) affects any deployment configuring
  `jwt_secret`/`api_keys`; per the charter, no-known-callers does not authorize
  removal.

## 7. Focused acceptance criteria

Each fails for one specific defect. None to be implemented under this audit.

1. **F1:** after a password reset, a previously issued bearer token is either
   rejected or explicitly documented as surviving. *Discriminates:* today it
   survives silently, and the decision is unrecorded.
2. **F2:** a static `api_keys` comparison uses `hash_equals`.
   *Discriminates:* fails today at `BearerAuthMiddleware.php:57`.
3. **F2:** a JWT presented without an `exp` claim is rejected.
   *Discriminates:* fails today — `:122` skips expiry when the claim is absent.
4. **F3:** a session idle beyond a configured lifetime is refused regardless of
   host `php.ini`. *Discriminates:* nothing enforces this today.
5. **F4:** an account holder can enumerate and revoke one session without
   invalidating the others. *Discriminates:* not expressible today.
6. **D1 (regression guard):** disabling an account rejects both a live cookie
   session and a live bearer token on the next request. *Discriminates:* pins
   the behaviour that is currently correct **when `waaseyaa/auth` is present**.
7. **F9:** a kernel that composes a session-issuing pipeline without an
   eligibility policy either refuses to boot or logs a loud warning.
   *Discriminates:* fails today — the control degrades to `null` in silence.

## 8. Cross-boundary notes

1. Studio identity/approval (#2978, #2766) and the OAuth IdP flow were not
   examined. If either mints its own bearer credential, it should be reconciled
   against F2's two mechanisms rather than becoming a third.
2. #1640 (OAuth 2.1 resource-server auth for MCP) would interact with Path B's
   audience/scope model.
3. Three OIDC routes call `csrfExempt()`
   (`packages/routing/src/OidcHttpRoutes.php:71,84,97`). They were located but
   deliberately **not** analysed, being inside the excluded identity-provider
   flow. They should be checked by whoever owns #2978/#2766.

## 9. Next disjoint lane — recommendation

**Rate limiting and abuse controls** (`AtomicRateLimiterInterface` in
`packages/auth`, the login/verification limiters, and #2775 "atomic token
spending"). Rationale: #2769 (this lane's second lead, untraced) is a
rate-limiting-and-outbox concern; #2775 is open and concerns non-atomic token
spending, which is the same shape as the queue audit's fenced-settlement
finding; and the auth limiters are the one remaining control on the
authentication path that no audit has examined.

Not next: the excluded OIDC/Studio identity flows (#2978, #2766, #2979),
`packages/access` field-read internals (#2847), or any area already
checkpointed.
