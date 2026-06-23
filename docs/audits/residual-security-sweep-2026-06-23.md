# Residual Security Re-Sweep — 2026-06-23 (post-C-24)

Adversarial claim-vs-code + reachability sweep run after the C-24 dead-layer
removal, to answer honestly: *is the reachable defect surface truly exhausted?*
Method: (1) re-verify the merged security/data-integrity fixes still hold on
current `main`; (2) a fresh reachability + claim-vs-code pass across all 75
packages (grouped into 5 buckets); (3) spot-check the audit Mediums that were
leads-only. Reachable findings are fixed failing-first as their own PRs;
everything else is recorded here with code evidence and a verdict.

**Grounding note:** findings marked **[confirmed]** were independently
ground-verified in code by the author. Findings marked **[agent-reported]** come
from the bucket sweep with the cited file:line evidence but were not yet
re-derived by hand — they are recorded for triage, not yet acted on.

---

## 0. Regression baseline — the merged fixes still hold

Full `Unit` (8467) + `Integration` (1305) suites are **green on current `main`**,
and the static gates (dead-code, phpstan, surface-parity, cs, package-layers,
symfony-imports, composer-policy) pass. Every merged security fix is guarded by a
regression test in those suites, so a green run is positive evidence that **no
fix was reverted or regressed**. Spot-checked fix *markers* in code (not just
tests): C-6 deny-by-default (`SqlEntityQuery:411` uses `isAllowed()`); messaging
participant policy present; #1648 audit-guard unquoting present; genealogy SSR
system-context topology present. **No regression found.**

---

## 1. Fixed this sweep (failing-first PRs)

| Finding | Severity | PR | Status |
|---------|----------|----|--------|
| `relationship.traverse` agent tool enumerated rows with **no** per-entity view gate; anon-reachable via the default MCP allowlist | High (anon info-disclosure) | #1768 | **merged** |
| `node` system fields (`uid`/`type`/`created`/`changed`) edit-unprotected → JSON:API mass-assignment on update | High (privilege/integrity) | #1769 | **merged** |

Both **[confirmed]** end-to-end (failing-first tests verified red against pre-fix
code).

---

## 2. Reachable, fix-recommended (not yet done)

These are framework-owned and reachable; each warrants its own failing-first PR.

### 2.1 `VectorSearchTool` returns results with no per-entity view filter — **[agent-reported]**
- Evidence: `ai-tools/src/Vector/VectorSearchTool.php:60-97` checks only
  `requireCapability('tool.vector.search')`, then returns `$storage->search(...)`
  raw (entity ids + metadata) with no `canViewEntity` filter.
- Reachability: NOT in the anon allowlist (so not anon-reachable), but reachable
  by any authenticated initiator granted `tool.vector.search` without `view` on
  the matched entities. Same class as #1768.
- Recommendation: apply `canViewEntity()` + `applyFieldAccessFilter()` per result
  (mirror #1768). Low risk.

### 2.2 Search `totalHits`/`totalPages`/facets leak access-restricted counts + metadata — **[agent-reported]**
- Evidence: `search/src/Fts5/Fts5SearchProvider.php:100-102` computes `totalHits`
  via raw `COUNT(*)` with no access predicate; `buildFacets()` (`:281-318`)
  aggregates `content_type`/`source_name`/`topics` over the unfiltered `WHERE`.
  Only the per-row `hits` loop applies the checker (`:137-145`). An in-code
  comment concedes the count "may include documents the caller cannot view."
- Reachability: exposed to templates via the `search()` Twig function
  (`SearchTwigExtension`), the intended public-search surface — an anon user
  varying `q`/filters reads counts + facet buckets as an existence/metadata
  oracle (row bodies are filtered).
- Recommendation: filter the count + facet aggregation through the same access
  predicate as the hit loop, or document + cap. Medium effort.

### 2.3 GraphQL emits `internal: true` fields (no internal-field stripping) — **[agent-reported]**
- Evidence: every other surface drops internal fields
  (`api/src/ResourceSerializer.php:33,189`, ai-tools `EntityReadTool.php:167`),
  but `graphql/src/Schema/EntityTypeBuilder.php:90-149` emits every field and the
  resolvers (`EntityResolver.php:130-131`) apply only the open-by-default
  `FieldAccessPolicy`. Known credential fields are saved today only by a
  *coincident* policy forbid — any entity marking a field `internal:true` without
  also writing a FieldAccessPolicy forbid leaks via GraphQL while hidden
  everywhere else. Introspection also discloses internal field *names*.
- Recommendation: add an internal-field drop + `ALWAYS_INTERNAL_FIELDS` backstop
  in the GraphQL output builder/resolvers, matching the REST serializer. Medium.

### 2.4 SSR `EntityRenderer` emits all fields with no internal-drop / field filter — **[agent-reported]**
- Evidence: `ssr/src/EntityRenderer.php:33-78,111-133` formats every field;
  `entity.html.twig` prints `field.formatted|raw`; no account/access/internal
  check. The stock content route only resolves `content`-group types (User not
  reachable today), so no credential leak now — but it is unsafe-by-construction
  vs its JSON:API/MCP siblings (a content entity with an `internal:true` field
  leaks). Defense-in-depth fix recommended.

### 2.5 `PathAlias` view ignores its own `status` flag — **[agent-reported]**, low
- Evidence: `path/src/PathAliasAccessPolicy.php:27-28` returns `allowed` for
  `view` to everyone with no status check, though `PathAlias` carries a `status`
  ("active") field. Leaks inactive internal path mappings. Low sensitivity.

### 2.6 Media upload silent overwrite/clobber under predictable public name — **[agent-reported]**, low
- Evidence: `media/src/Http/MediaRouter.php:84-98` builds the dest from the
  client filename (traversal blocked, base name preserved) and `move()`s with no
  uniqueness check; the hardened `UploadHandler::generateSafeFilename()`
  (`random_bytes(4)`) exists but is **not wired** to the route. Two `logo.png`
  uploads overwrite; SVG is allowed → predictable `/files/<name>.svg`. Low-priv
  authenticated; integrity/stored-XSS-file concern, not private-byte disclosure.

### 2.7 `/oidc/revoke` not bound to the authenticating client (RFC 7009) — **[confirmed via agent]**, low
- Evidence: `oidc/src/Revoke/RevocationController.php:78-129` revokes any token
  matching the submitted value without checking `record->clientId ===
  authenticated client`. Denial-only, attacker needs the high-entropy token
  value; spec-conformance gap, not escalation/disclosure.

### 2.8 `llms.txt` emits unescaped URL/summary/title — **[agent-reported]**, low
- Evidence: `seo/src/Llms/LlmsTxtGenerator.php:54-64` emits entity-derived
  `summary`/`title`/URL raw; `escape()` only strips `[`/`]`. Agent-facing
  `text/plain` (link/content spoofing, not browser XSS). Harden: validate URL
  scheme, escape newlines.

---

## 3. Capability defects — real weaknesses, reachability depends on app wiring

Framework primitives that are unsafe-by-default; whether they are *live* depends
on consuming-app route/caller wiring beyond the framework packages. Hardening
recommended; not a confirmed live framework hole.

### 3.1 `EntityParamConverter` injects route-bound entities with NO access check — **[agent-reported]**
- Evidence: `routing/src/ParamConverter/EntityParamConverter.php:61-70` does
  `getStorage()->load($rawId)` and injects; `SqlEntityStorage::load()` is a direct
  PK select that does **not** route through `SqlEntityQuery` (the #1751 deny-by-
  default layer) and never calls the access handler. Any route wiring
  `entityParameter()` without also requiring auth/permission (or whose controller
  doesn't re-check) is an IDOR primitive. Recommend: view-check in the converter.

### 3.2 `EntityDeepLinkRouteBuilder` emits auth-less entity routes by default — **[agent-reported]**
- Evidence: `routing/src/EntityDeepLinkRouteBuilder.php:54-62` returns a builder
  pre-wired with `entityParameter()+GET` and **no** access option. Forgetting to
  chain `requireAuthentication()` → anon-reachable raw entity load (compounds
  3.1). Insecure default; recommend a secure-by-default posture.

### 3.3 `http-client` is an unmitigated SSRF / scheme-read / redirect / header-injection capability — **[agent-reported]**
- Evidence: `http-client/src/StreamHttpClient.php:46` (`@file_get_contents`) and
  `PhpStreamSseClient.php:52` (`@fopen`) pass the URL to PHP stream wrappers with
  no scheme validation (`file://`/`php://`/`data://`/`phar://` accepted), no
  host/IP allowlist (metadata/loopback/RFC1918 reachable), redirects followed by
  default, CRLF-unsanitized header interpolation, and full URL in exception
  messages. Exploitable wherever an untrusted URL/header reaches it (webhooks,
  unfurlers, AI fetch). Recommend: scheme allowlist, host/IP guard, `max_redirects`,
  CRLF strip.

### 3.4 `#[GateAttribute]` is inert — its docblock promises enforcement no code provides — **[confirmed]**
- Evidence: the Gate enforcement path works (`AccessChecker` consumes the `_gate`
  option → `checkGate` → `GateInterface`; `GateAccessTest` passes), but
  `AccessChecker::applyGateToRoute()` — the only transfer of `#[GateAttribute]` →
  `_gate` — has **zero production callers**, and there is no `RouteBuilder::gate()`
  setter. The attribute's `@api` docblock ("the AccessChecker reads this attribute
  and calls the Gate") is **false**. No framework route relies on it, so it is a
  consumer *footgun*, not a live hole.
- Recommendation (decision): either (a) **wire** it — add a `RouteBuilder::gate()`
  setter and/or an attribute-scan compilation step calling `applyGateToRoute()`,
  making the documented behavior real; or (b) **deprecate/mark `@internal`** and
  correct the docblock. (b) is the smaller honest fix if the attribute path was
  never finished; (a) is a small feature. Recommend (a)'s `RouteBuilder::gate()`
  setter (the enforcement already works) + docblock correction.

---

## 4. Decision-needed (design / product / editorial policy)

### 4.1 `relationship` is `group: 'content'` → published relationships are anon-viewable
- Evidence: `relationship/src/Relationship.php:11` (`#[ContentEntityType]`,
  `status=1` default) + `RelationshipServiceProvider.php:25` (`group: 'content'`).
  The framework-default `PublishedContentAccessPolicy` (`access/.../PublishedContentAccessPolicy.php:39`,
  `PUBLIC_GROUP='content'`) returns `allowed` for anon `view` of any published
  content-group entity, **overriding** `RelationshipAccessPolicy`'s own intent
  that `view` requires `access content`. So the policy's `access content` gate is
  silently nullified. **[confirmed]**
- Decision: is a relationship between published content itself public? If yes,
  this is by-design and `RelationshipAccessPolicy`'s view branch is dead; if no,
  `relationship` should not be content-group (it affects discovery/SSR too — has
  blast radius), or the policy precedence should change. Not changed unilaterally.
  (#1768 already closed the tool-level *bypass* regardless of this decision.)

### 4.2 Node editorial booleans `status`/`promote`/`sticky` writable by `edit own` authors
- Companion to #1769 (which gated the system fields). Whether an author may
  self-publish (`status`) or self-promote to the front page (`promote`/`sticky`)
  via the API is an editorial-permission decision; the framework has no
  publish-specific permission today. Recommend deciding whether to introduce a
  `administer nodes`-gated (or publish-permission-gated) field forbid for these.

### 4.3 Admin `SurfaceQuery` filter/sort is a field-access oracle — **[agent-reported]**, admin-gated
- Evidence: `admin-surface/src/Query/SurfaceQueryParser.php:29-66` accepts any
  `filter[<field>]`/`sort=<field>` with no allow-list; row membership/order leaks
  hidden field values (per-character oracle). Reachable only by `administer
  content`. Low severity (admin-only); recommend a field allow-list.

### 4.4 `billing` latent hardening before activation — **[agent-reported]**
- The package is v0.1 scaffolding (StripeClient unbound, webhook unrouted, DTOs
  not entities) → no reachable defect today. Before activation it needs:
  timing-safe webhook signature verify, `event.id` idempotency, owner-scoped
  AccessPolicy + `internal:true` on amounts/Stripe ids, owner from session not
  request, and an env gate preventing `FakeStripeClient` binding in prod.

---

## 5. Verified clean / by-design (high-value checks that PASSED)

- **Auth / OIDC crypto + lifecycle** (bucket A): constant-time compares
  (`hash_equals`/`password_verify`), single-use auth codes, PKCE S256-only,
  refresh-token rotation + theft detection, JWT alg-pinned (no `alg:none`),
  2FA replay/recovery handling, JWKS emits only public key material. Credential
  fields (`User` 2FA, `oidc_client.client_secret_hash`) double-layered
  (policy-forbidden + `internal:true`). Mass-assignment of user privilege fields
  closed (UserAccessPolicy).
- **MCP** (bucket B): anon surface read-only + deny-by-default; `ReadOnlyToolRegistry`
  hides destructive/off-allowlist tools; `/mcp/write` fails closed to 401. #1711
  GraphQL total-count leak FIXED; #1752 schema/openapi auth gate HOLDS; JsonApiController
  index/show/store/update/destroy all access-gate + 404-not-403 (no oracle).
- **AI agent tools** (bucket D): #1638 per-field edit-access + #1637 audit/list-arg
  fixes present & not bypassable; entity read/write/revision tools all enforce the
  per-entity gate + internal-field drop, fail-closed wiring; bimaaji + wayfinding
  guardrails gate correctly; observability logs no secrets/payloads; embedding
  providers config-fixed (no SSRF).
- **Content/ingress** (bucket C): attachment private-byte download deny-by-default
  + realpath containment + 404-only (#1761) CLEAN; structured-import has no write
  path; northcloud base URL env-only (no request-derived SSRF); SEO JSON-LD/meta/
  sitemap escaped + status-filtered; path-alias creation admin-gated, no open
  redirect.
- **Infra/ops** (bucket E): messaging participant-only policy (the prior no-policy
  defect FIXED); genealogy SSR system-context topology + per-person redaction
  FIXED; #1648 audit-guard bypass FIXED; telescope/debug admin-gated or
  APP_DEBUG-gated, no body/secret capture; mail no CRLF/SSTI/recipient-override;
  listing filters strictly allowlisted; mercure publish-only.

---

## 6. Honest conclusion

The reachable surface is **substantially but not fully exhausted.** Two genuine
reachable defects were found and fixed (#1768, #1769). The §2 items are real and
fix-recommended but lower-severity or non-anon; the §3 items are capability
footguns whose live reachability depends on app wiring; the §4 items are design/
product decisions. The cryptographic, token-lifecycle, MCP-allowlist, and
agent-tool access surfaces are solid, and the previously-merged fixes all hold.
The remaining §2 fixes plus the §3/§4 decisions are the honest tail — none are
critical anon-reachable data-disclosure holes of the #1768 class, but the sweep
was **not** a rubber stamp: it surfaced eight new reachable/footgun items and two
design contradictions that should be triaged rather than assumed absent.
