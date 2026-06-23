# Residual Security Sweep — Remediation

**Mission:** `residual-security-sweep-remediation-01KVVD4R`
**Type:** software-dev · **Base/target:** `main` · **Created:** 2026-06-23
**Source of record:** `docs/audits/residual-security-sweep-2026-06-23.md`

## Purpose

Work down the *reachable* tail of the 2026-06-23 residual security re-sweep.
Each remaining §2/§3 item that is genuinely reachable in current code gets its
own failing-first PR, squash-merged to `main`. Items that turn out **not**
reachable on closer grounding are recorded in the ledger with code evidence and
a verdict instead of being force-fixed.

§2.1 (`VectorSearchTool` per-entity gate) is already **DONE** — shipped as
**PR #1771**, merged to `main` (`0a05f2e9d`). Not in scope here.

## Method (per WP, non-negotiable)

1. **Ground in current code first.** Re-derive the cited file:line evidence on
   `main`. Several items are flagged caller-dependent — confirm *reachability*
   before writing any fix.
2. **If not reachable:** record the finding + evidence + verdict in the ledger
   and stop. Do not force a fix or add a BC shim.
3. **If reachable:** **failing-first** — write the test, run it RED against the
   unfixed code, then fix to green. No backwards-compat shims.
4. **Gates before merge:** targeted `phpunit` (changed package), `php-cs-fixer`
   (per file), `phpstan` (changed src), `composer check-dead-code`, and the
   drift detector (`BASE_REF=main bash tools/drift-detector.sh`).
5. **CHANGELOG.md:** bold-lead bullet under `### Fixed` — what / why / how /
   acceptance / honest reachability note (house style: the `vector.search` and
   `relationship.traverse` entries).
6. **Merge:** `gh pr create --base main …` → review (Opus) → `gh pr merge <n>
   --squash --delete-branch --admin` → `git checkout main && git pull`.

## Established conventions (carried from PR #1771)

- **Internal-field drop pattern** is the reference for §2.3/§2.4: mirror
  `api/src/ResourceSerializer.php` `filterInternalFields()` +
  `ALWAYS_INTERNAL_FIELDS = ['pass','password','password_hash']` backstop.
- `EntityAccessHandler::__construct(array $policies = [])` takes ONE arg. A
  field policy implements BOTH `AccessPolicyInterface` AND
  `FieldAccessPolicyInterface` and goes in that same array.
- **Spec-drift gate:** if a MAPPED package's `src` is touched, add a dated
  `spec reviewed YYYY-MM-DD` HTML comment to the mapped `docs/specs/<name>.md`
  AND/OR a `spec-reviewed: docs/specs/<name>.md, <reason>` commit footer.
  Mapped here: `graphql`→`api-layer.md`, `seo`→`seo.md`, `routing`→`api-layer.md`.
  Unmapped (advisory-only, one-line note welcome): `ssr`, `search`, `path`,
  `media`, `oidc`.
- Commit subject: `fix(<pkg>): <imperative>`. Footers: `spec-reviewed: …`,
  `no-changelog: changelog entry included under ### Fixed`, `Co-Authored-By`.

## Work packages (priority order)

### Priority 2 — internal-field disclosure on read surfaces

**WP01 · §2.3 — GraphQL emits `internal:true` fields** (`graphql`, MAPPED→api-layer.md)
- Evidence: `graphql/src/Schema/EntityTypeBuilder.php` `buildOutputFields()`
  (~L120-148) emits every resolved field; resolvers apply only the open-by-default
  `FieldAccessPolicy`. Introspection also discloses internal field *names*.
- Fix: in `buildOutputFields`, skip a field when
  `$def->getSetting('internal') === true` OR the name is an always-internal
  credential key. Schema-level drop is primary (field becomes unqueryable AND
  uninspectable). Mirror `ResourceSerializer` + `ALWAYS_INTERNAL_FIELDS`.
- Acceptance: failing-first test proving an `internal:true` field (and a
  credential-named field) is absent from query output AND from introspection.

**WP02 · §2.4 — SSR `EntityRenderer` no internal-drop** (`ssr`, UNMAPPED, advisory)
- Evidence: `ssr/src/EntityRenderer.php` (~L33-78, L111-133) formats every field;
  `entity.html.twig` prints `field.formatted|raw`; no internal/account check.
  Stock content route only resolves content-group types today → no live
  credential leak; this is defense-in-depth / unsafe-by-construction vs siblings.
- Fix: apply the same internal-drop in the renderer.
- Acceptance: failing-first test proving a content entity with an `internal:true`
  field does not render that field.

### Priority 3 — lower severity, each failing-first

**WP03 · §2.2 — Fts5 count/facet leak** (`search`, UNMAPPED, advisory)
- Evidence: `search/src/Fts5/Fts5SearchProvider.php` `totalHits` (L100-102, raw
  `COUNT(*)`) and `buildFacets()` (L281-318) aggregate over the unfiltered WHERE;
  only the per-row hits loop (L137-145) applies the checker. Reachable via the
  `search()` Twig function (`SearchTwigExtension`), the public-search surface →
  anon existence/metadata oracle.
- Fix: filter count + facets through the same access predicate as the hit loop,
  or document + cap (decide on grounding).
- Acceptance: failing-first test proving count/facets exclude access-restricted
  docs for an anon caller.

**WP04 · §2.5 — PathAlias ignores `status`** (`path`, UNMAPPED, advisory)
- Evidence: `path/src/PathAliasAccessPolicy.php` (L27-28) returns `allowed` for
  `view` to everyone despite a `status` field → leaks inactive path mappings.
- Fix: gate `view` on `status` (active) for non-privileged accounts.
- Acceptance: failing-first test proving anon `view` of an inactive alias is not
  allowed; active alias still viewable.

**WP05 · §2.6 — Media upload clobber** (`media`, UNMAPPED, advisory)
- Evidence: `media/src/Http/MediaRouter.php` (L84-98) builds dest from client
  filename and `move()`s with no uniqueness; hardened
  `UploadHandler::generateSafeFilename()` (`random_bytes(4)`) exists but is NOT
  wired. Two `logo.png` overwrite; SVG → predictable `/files/<name>.svg`.
- Fix: wire `generateSafeFilename()` into the route.
- Acceptance: failing-first test proving two same-name uploads produce distinct,
  non-predictable stored paths.

**WP06 · §2.7 — OIDC revoke not client-bound (RFC 7009)** (`oidc`, UNMAPPED, advisory)
- Evidence: `oidc/src/Revoke/RevocationController.php` (L78-129) revokes any
  token matching the value without checking `record->clientId === authenticated
  client`.
- Fix: require the authenticated client own the token before revoking; per RFC
  7009, mismatched client → treat as success-noop (no revoke), not a 4xx oracle.
- Acceptance: failing-first test proving client B cannot revoke client A's token.

**WP07 · §2.8 — llms.txt injection** (`seo`, MAPPED→seo.md)
- Evidence: `seo/src/Llms/LlmsTxtGenerator.php` (L54-64) emits entity-derived
  summary/title/URL raw; `escape()` only strips `[`/`]`. Agent-facing
  `text/plain` → link/content spoofing.
- Fix: validate URL scheme (http/https only), escape newlines in
  summary/title/URL.
- Acceptance: failing-first test proving newline/scheme injection in entity
  fields cannot forge llms.txt link/section structure.

### Priority 4 — capability footgun

**WP08 · §3.4 — `#[GateAttribute]` false `@api` promise** (`routing`, MAPPED→api-layer.md)
- Evidence: `_gate` enforcement works (`AccessChecker`→`checkGate`→`GateInterface`,
  `GateAccessTest` passes), but `AccessChecker::applyGateToRoute()` (the only
  `#[GateAttribute]`→`_gate` transfer) has ZERO production callers and there is no
  `RouteBuilder::gate()` setter → the attribute's `@api` docblock is FALSE.
- Decision on grounding: if wiring is contained, add `RouteBuilder::gate()`
  and/or call `applyGateToRoute()` in the route-build path and prove a
  `#[GateAttribute]` route now enforces (denied post-fix, inert pre-fix). If it
  needs substantial new attribute-scan infra, instead deprecate the inert
  attribute and correct the false `@api` enforcement promise. Either way the
  published security claim must stop being false.
- Acceptance: either a failing-first test proving a `#[GateAttribute]` route now
  denies (was inert), OR the docblock corrected + attribute marked `@internal`/
  deprecated with a test asserting the corrected contract.

## Out of scope (wait for Russell)

§3.1–3.3 are not listed for action here. The §4 design decisions (relationship
`group:'content'`; node `status`/`promote`/`sticky` gating; admin `SurfaceQuery`
oracle; billing pre-activation) and the held items (C-22, OCAP-at-DB, media
substrate #1762) are explicitly deferred. No release cut; no beta declaration.

## Definition of done

Every reachable WP above is either merged to `main` as a failing-first PR or
recorded in the ledger as not-reachable with evidence. When the recorded
reachable list is genuinely worked down, report that the reachable surface is
exhausted and what (if anything) remains for decision.
