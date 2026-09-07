# #2985 — route and entity authorization audit

Audit lane of **#2985**. Read-only: no runtime change, no class removal, no
public-surface reclassification, no test suites run.

- **Pinned source SHA:** `e83ec8ba9ff3bf499c1740943c33e1f3d5f4e82f` (`origin/main`)
- **Exclusion honoured:** Codex holds unmerged repairs to protected-**field**
  read visibility (#2847, worktree `fw-2847-read-visibility`). Field-level read
  validation was not audited or judged. Cross-boundary questions are in §8.

## Headline

**No confirmed unauthorized-access route was found.** Every `allowAll()` route
examined resolves to *intentionally public* or *protected downstream*. The
scariest available reading — that entity writes can proceed unchecked over
HTTP — is **disproved** in §5.

Two confirmed behaviours do need a decision, and one undocumented asymmetry is
the substantive finding: **entity writes are enforced by callers, not by the
repository, and only the JSON:API caller enforces them.**

## 1. Scope and coverage matrix

| Area | Reviewed | Depth | Notes |
|---|---|---|---|
| `AccessChecker` route options | ✅ | All six options, combination, default | |
| `AuthorizationMiddleware` | ✅ | Full outcome handling | |
| Middleware ordering | ✅ | Real `#[AsMiddleware]` priorities | |
| `EntityAccessHandler::check` / `checkCreateAccess` | ✅ | Combination + no-policy default | |
| Entity-level call sites | ✅ | ~50 enumerated for polarity | |
| Gate layer | ✅ | Both implementations | |
| Account/principal resolution | ✅ | All `SessionMiddleware` branches | |
| Anonymous / missing account | ✅ | All `_account` readers | |
| `allowAll()` route inventory | ⚠️ Partial | 57 sites; risk-ranked, highest examined individually | Not every one traced to its handler |
| Non-HTTP entrypoints | ✅ | CLI, queue, scheduler, MCP, local operator | |
| Entity **write** enforcement | ✅ | Repository vs caller | |
| **Protected-FIELD read** | ⛔ Excluded | — | #2847, Codex in flight |
| **SSR authorization path** | ❌ Not reviewed | — | Noted by a lane, not traced |
| **Tenancy scoping** (#2815/#2816) | ❌ Not reviewed | — | Open design issues |
| **Every `allowAll()` handler** | ❌ Not exhaustive | — | See §4 residual |
| Test suites | ❌ Not run | — | Static audit per scope |

## 2. The route → entity chain, as it actually runs

Middleware priority is **descending** (`HttpMiddlewareStackComposer.php:38-42`,
`$right <=> $left`), so higher runs first:

`SecurityHeaders(100)` → `Compression/DebugHeader(90)` → `RateLimit(80)` →
`BodySizeLimit(70)` → `RequestLogging(60)` → `ETag(50)` → **`BearerAuth(40)`** →
**`Session(30)`** → `FieldReadContext(15)` → **`Authorization(10)`** → handler.

CLAUDE.md's "SessionMiddleware → AuthorizationMiddleware" is correct but omits
`BearerAuthMiddleware(40)`, which runs earlier and may pre-set `_account`.

Route matching (`HttpKernel::matchRoute`, `:545-592`) happens *before* the
pipeline is built, and the pipeline's terminal handler dispatches the domain
router — so every matched route passes `AuthorizationMiddleware` before any
controller runs. No domain router bypasses the pipeline.

## 3. CONFIRMED BEHAVIOUR — route/entity polarity differs (contract undecided)

Recorded as behaviour, not as a defect, pending a decision on the intended
contract.

**Route layer — Neutral passes.** `AccessChecker::check()` returns
`AccessResult::neutral('No access requirements specified on route.')` when a
route declares no options (`packages/access/src/AccessChecker.php:117-119`).
`AuthorizationMiddleware` denies only on `isUnauthenticated()` → 401
(`Middleware/AuthorizationMiddleware.php:74`) and `isForbidden()` → 403
(`:94`); every other outcome falls through to `$next->handle($request)`
(`:113`). **Neutral is therefore treated exactly like Allowed.**

**Entity layer — Neutral denies.** `EntityAccessHandler::check()` initialises
`AccessResult::neutral('No policy provided an opinion.')`
(`EntityAccessHandler.php:128`), accumulates with `orIf()` and short-circuits
on Forbidden (`:139-143`). With no applicable policy it returns that Neutral,
and every entity-level caller gates on `isAllowed()`, which is strict equality
to `Allowed` (`AccessResult.php:61-64`). **Neutral is therefore denial.**

Note this is a *different* asymmetry from the entity-vs-field one CLAUDE.md
documents as intentional. No document states this one.

**Why a global change would be wrong.** Flipping route-layer Neutral to deny
would break every legitimately public route — 57 `allowAll()` registrations
exist, and the framework's own pattern (§4) is deliberately public-at-transport
with enforcement downstream. The narrowest correction supported by evidence is
in §7; the contract itself should be documented separately.

## 4. Route classification

`allowAll()` appears at 57 production sites; `requireAuthentication()` at 40;
`requirePermission()`/`requireRole()` at 40.

| Route / family | Registration | Classification | Evidence |
|---|---|---|---|
| JSON:API entity CRUD (index/show/store/update/destroy) | `JsonApiRouteProvider.php:91,137,159,170` `->allowAll()` | **Protected downstream** | `JsonApiController` checks `checkCreateAccess`/`check` before every write; principal guaranteed non-null (§5) |
| `media.download` / `media.view` | `BuiltinRouteRegistrar.php:93-112` `->allowAll()` | **Protected downstream** | Comment names `MediaDownloadRouter` as the enforcement point; verified in the media audit (entity `view` check, 404 concealment) |
| `attachment.download` | `BuiltinRouteRegistrar.php:121-128` | **Protected downstream** | `AttachmentDownloadRouter` reads `_authorization_principal` and self-enforces |
| `admin_spa` `GET /admin/{path}` | `AdminSurfaceServiceProvider.php:238-240` | **Intentionally public** | Serves the SPA shell; data arrives via separately authorized API calls |
| `debug.error_preview` `/_error/{statusCode}` | `DebugServiceProvider.php:24-28` | **Intentionally public, dev-only** | Route is not registered unless `RuntimePolicy::resolve($config)->debug` (`:20-22,47-50`); kernel refuses to boot with `APP_DEBUG=true` in production |
| Auth / OIDC endpoints (19 sites) | `AuthOidcRouteServiceProvider.php`, `OidcHttpRoutes.php` | **Intentionally public** | Login/callback/discovery must be reachable unauthenticated |
| MCP (4 sites) | `McpRouteProvider.php` | **Protected downstream** | `McpEndpoint.php:408-409,617` authenticates via `McpAuthInterface` and installs the principal per request |
| GraphQL endpoint | `GraphQlRouteProvider.php`, `GraphQlEndpoint.php` | **Protected downstream** | `GraphQlAccessGuard` performs entity and field checks |
| SSR public pages, genealogy SSR | `SsrServiceProvider.php`, `GenealogyServiceProvider.php` | **Intentionally public** | Public read surfaces; `SeoPublicController` explicitly runs as anonymous |
| CORS preflight | `HttpKernel::handleCors()` `:951-975`, before routing | **Intentionally public** | Returns bare 204, carries no data |
| Remaining `allowAll()` sites (workspace, wayfinding, api, recipe) | various | **Unresolved** | Not individually traced — see residual below |

**Residual:** roughly a dozen `allowAll()` registrations were not traced to
their handler. They are the honest gap in this lane; none is in a
high-risk-named package, but "not traced" is not "safe".

## 5. Disproved — HTTP entity writes are not unchecked

An investigator correctly observed that `EntityRepository::save()` and
`delete()` never call `EntityAccessHandler`
(`packages/entity-storage/src/EntityRepository.php:655-664`, `:739`), and that
`JsonApiController` guards its checks with
`if ($this->accessHandler !== null && $this->account !== null)`
(`packages/api/src/JsonApiController.php:845`). Read alone, that suggests a
null handler or account would skip authorization and write anyway.

**On the HTTP path that branch is unreachable:**

- `JsonApiRouter` declares `private readonly EntityAccessHandler $accessHandler`
  (`packages/foundation/src/Http/Router/JsonApiRouter.php:27`) — **non-nullable**,
  and passes it at `:69`.
- The principal comes from `WaaseyaaContext::fromRequest()`, which **throws**
  `LogicException` when the account is not an `AccessAccountInterface` or the
  resolved principal is null
  (`packages/foundation/src/Http/Router/WaaseyaaContext.php:34-42`).

So both conditions are guaranteed true before any write. This is exactly why a
direct handler invocation proves nothing about reachability — the real
registration and middleware chain fail closed first. **No focused proof is
warranted, because there is no defect here to prove.**

## 6. Confirmed findings

### F1 — Entity writes are enforced by callers, not by the repository
**CONFIRMED. Undocumented asymmetry. The substantive finding.**

`EntityRepository::save()`/`delete()` perform no access check. Enforcement
exists only where a caller adds it, and **only the JSON:API controller does**
(`JsonApiController.php:845-895`, `:1049-1158`, `:1470`). CLI mutation
(`EntityCreateHandler.php:49-51`), classification jobs (`RedactJob.php:146`,
`PurgeJob.php:167`) and backfills (`WorkflowsBackfillStateHandler.php:356`)
call `save()`/`delete()` directly.

The **read** side is different and is intentional: every CLI/job
`accessCheck(false)` carries an inline "system sweep / no account in scope"
justification, which CLAUDE.md documents as the sanctioned opt-out, and
`tools/getquery-bindings-baseline.txt` holds exactly one entry — a PHPDoc
example, with a reason.

The **write** side has no such statement. Nothing in `docs/specs/`, and no
docblock on `EntityRepository`, asserts either that `save()` is access-checked
or that CLI writes are exempt. It is an emergent property of where enforcement
happened to be implemented. **Classify: divergent, not decided.**

### F2 — `_gate` is wired but dormant
**CONFIRMED.** No production route sets `_gate`. The only matches are bimaaji
introspection reading route options, `RouteBuilder::gate()`'s own definition
(`packages/routing/src/RouteBuilder.php:129-139`), and a comment. CLAUDE.md
lists `_gate` as one of six route access options; zero routes use it.
`GateAttribute` is explicitly documented as non-enforcing
(`packages/routing/src/Attribute/GateAttribute.php:18-21`).

### F3 — Two `allows()` on one interface, with unrelated semantics
**CONFIRMED. The clearest instance of the equivalence trap.**

- `EntityAccessGate::allows()` → `handler->check($subject, $ability, $user)->isAllowed()`
  (`packages/access/src/Gate/EntityAccessGate.php:62`). Bound as the production
  `GateInterface` (`HttpKernel.php:605`).
- `Gate::allows()` → `(bool) $policy->{$ability}($user, $subject)`
  (`packages/access/src/Gate/Gate.php:50`) — resolves a policy by naming
  convention and **never touches `AccessResult` at all**. Denies on a missing
  policy or method (`:41-47`). Wired only as `new Gate([])`, a deliberate
  deny-all fallback (`packages/listing/src/ServiceProvider.php:379`).

A caller typed to `GateInterface` cannot tell which is installed, and the two
deny for entirely different reasons. Not a live defect — both fail closed — but
a real substitution hazard.

### F4 — `EntityAccessGate` silently degrades to anonymous
**CONFIRMED.** `resolveCurrentPrincipal()` returns
`new AuthorizationPrincipal(0, false, [], [], 'anonymous')` when no field-read
scope principal is installed and the account context is not itself a principal
(`EntityAccessGate.php:139-151`). `User` implements `AccountInterface` but
**not** `AuthorizationPrincipalInterface`
(`packages/user/src/User.php:30`), so a raw `User` takes the fallback.

On HTTP this is masked: `FieldReadContextMiddleware` (priority 15) converts
`_account` into a principal before `AuthorizationMiddleware`. Off the HTTP
path, a real user would be evaluated against the anonymous grant set. It fails
*closed* with respect to over-privileging (anonymous is normally weaker), but
it degrades silently rather than erroring. The docblock acknowledges the
fallthrough, so this is a stated behaviour whose failure mode is worth deciding
on, not an accident.

### F5 — `RECOGNIZED_OPERATIONS` is declared and never used
**CONFIRMED.** `EntityAccessHandler::RECOGNIZED_OPERATIONS` (`:45`) is
referenced nowhere else. `check()` accepts any operation string; only
`view_revision` and `translate` are special-cased. An unrecognized operation
produces an all-Neutral aggregate → denied — fail-closed, but *only because
policy authors consistently default to Neutral*, not because the handler
enforces a vocabulary.

### F6 — Bundle-scoped policy filtering is dormant
**CONFIRMED.** `EntityAccessHandler::resolveBundles()` (`:632-637`) reads
`#[AccessPolicy(...)]` (`Waaseyaa\Access\Attribute\AccessPolicy`), but no
policy class in the repository declares it — real policies use the *different*
attribute `Waaseyaa\Access\Gate\PolicyAttribute`, which is what
`PackageManifestCompiler` scans (`:18`, `:214`). So the bundle filter is always
empty in practice. Two similarly-purposed attributes, one of them inert.

### F7 — Multiple policies per entity type are all consulted
**CONFIRMED, and correct.** `$policies` is keyed by class-string, so two
policies claiming one entity type both register and both contribute, OR-combined
with Forbidden short-circuit. Worth stating because "which one wins" has no
answer — neither does; they compose.

## 7. Narrowest corrections supported by evidence

Deliberately **not** a global Neutral change.

1. **F1:** document the write-authorization boundary — state explicitly that
   `EntityRepository::save()`/`delete()` are not enforcement points and that
   callers own the check. If enforcement is wanted for non-HTTP writes, it
   belongs in the specific callers, not in the repository (which would break
   every legitimate system sweep).
2. **Route policy:** document that the route layer is opt-in and that
   public-at-transport-plus-enforce-downstream is the intended pattern. Then,
   narrowly, consider an `AH00x` rule in the existing `bin/check-access-hardening`
   gate requiring every `allowAll()` registration to carry an inline rationale —
   the gate already enforces "AH004 every RouteBuilder chain must declare an
   explicit access posture", so this is an extension of a live mechanism rather
   than a new scanner.
3. **F2:** either adopt `_gate` on a real route or record it as reserved.
4. **F4:** decide whether `EntityAccessGate` should fail loud instead of
   silently resolving anonymous when no principal is installed.
5. **F5/F6:** either use `RECOGNIZED_OPERATIONS` and `#[AccessPolicy]`, or
   remove them from the mental model by documenting them as reserved. Note both
   are shipped surface; the stability charter governs any removal.

## 8. Cross-boundary questions for Codex (#2847) — not adjudicated here

1. `ProtectedFieldReadPolicyInterface`'s docblock says "Neutral is interpreted
   as denial by the future evaluator", but the live caller
   `EntityAccessHandler::checkProtectedFieldRead()` (`:512-550`) combines with
   `orIf()`/Forbidden-short-circuit, so an all-Neutral aggregate resolves to
   Neutral today. Does #2847 intend to flip that aggregation?
2. `checkProtectedEntityRead()` (entity-level, in scope here) and
   `checkProtectedFieldRead()` (field-level, excluded) share naming and live in
   the same class. Does the in-flight repair assume any shared state or call
   order between them?
3. **#2159** (OPEN, **p0**, beta-blocker) — anonymous published reads return
   empty. Mechanism verified here: `checkProtectedEntityRead()` returns
   `forbidden('...missing required field "X"')` when a declared input is absent
   from the compiled subject (`:249-251`), else stays Neutral → denied. That is
   **fail-closed by design**; the gap is that scaffolded entities do not declare
   the required classification — which is #2847's acceptance ("carry explicitly
   selected canonical read visibility through scaffold metadata"). Three audits
   now converge on the same root cause: `make:content-type` emits no read-level
   classification. Recorded for Codex; not adjudicated.

## 9. Existing issues affected

| Issue | State | Relationship | Evidence |
|---|---|---|---|
| **#2159** | OPEN **p0** | Mechanism confirmed; root cause sits in #2847's lane | `EntityAccessHandler.php:249-251` |
| #2847 | OPEN p1 | Excluded; three cross-boundary questions in §8 | — |
| #2848 | OPEN p2 | Generated policies must be default-deny — consistent with F7's compose-don't-override model | — |
| #2433 / #2432 | OPEN p2 | Config activation/import lack authorization policies — same "caller owns the check" shape as F1 | — |
| #2815 / #2816 | OPEN | Tenancy scoping; `AuthorizationPrincipalInterface` already carries `tenantId()` | `AuthorizationPrincipalInterface.php:12-18` |
| #2169 | OPEN | GraphQL reveals type names for fully-denied entities — consistent with F3's layer split | — |
| #1605 | OPEN | Unauthorized-vs-empty over JSON:API — directly downstream of §3's Neutral semantics | — |

No new issue is proposed. F1 is the only finding without an owner, and it is a
documentation-and-decision item that belongs on #2985's synthesis rather than a
new ticket.

## 10. Compatibility constraints

- `packages/access` declares 44 public entries; `AccessPolicyInterface`,
  `FieldAccessPolicyInterface`, `GateInterface`,
  `ProtectedEntityReadPolicyInterface` are all `public`. Any signature change is
  a semver event.
- Concrete finals (`EntityAccessHandler`, `AccessChecker`, `AccessResult`,
  `Gate`, `EntityAccessGate`) are absent from `public-surface.php`. Per charter
  §2 / audit C-16 that is **correct** — concrete finals are deliberately
  untracked — and must not be "fixed" by adding rows.
- Changing route-layer Neutral handling globally would alter behaviour for all
  57 `allowAll()` routes. Rejected in §7 for that reason.
- F5/F6 removals would drop shipped symbols; no-known-callers does not
  authorize removal.

## 11. Focused acceptance criteria

Each fails for one specific defect. **Any route-reachability proof must drive
the real registration and middleware chain with both an anonymous and an
authorized control — a direct handler call proves nothing.**

1. **F1:** a CLI entity write for an entity whose policy forbids the operation
   is refused, or is documented as intentionally exempt. *Discriminates:*
   currently succeeds silently.
2. **Route policy:** every `allowAll()` registration carries an inline
   rationale, enforced by an added rule in the existing
   `bin/check-access-hardening`. *Discriminates:* fails on a new unjustified
   public route.
3. **F4:** `EntityAccessGate` with no installed principal either raises or is
   asserted to resolve anonymous deliberately. *Discriminates:* currently
   silent.
4. **F2:** a route using `_gate` denies an unauthorized principal end to end
   through the middleware chain. *Discriminates:* no such route exists today.
5. **§3 contract:** an option-less route is reachable anonymously — pinned as
   intended behaviour with an anonymous control, or changed. *Discriminates:*
   makes the undecided contract explicit either way.

None should be implemented under this audit.

## 12. Next disjoint lane — recommendation

**Configuration management and the sync/activation path** (`packages/config`
`Sync/`, `Dependency/`, `Audit/`, `Backend/`, plus `config:*` CLI). Open
issues #2432 and #2433 already say destructive config import and rollback have
**no authorization policy** — the same "caller owns the check" shape as F1, on
a surface that mutates activation state. It is disjoint from Codex's #2847/#2848
lanes, from #2984, and from all three delivered audits.

Not next: `packages/access` field-read internals (#2847), `packages/cli/src/Site/`
or `packages/search` (Codex active), or any area already checkpointed.
