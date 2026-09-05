# MCP Endpoint

<!-- Spec reviewed 2026-09-05 - #2637: StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES is 1 MiB, matching BodySizeLimitMiddleware. mcp.transport.max_request_bytes above an enabled kernel body_size_limit.max_bytes fails closed at McpServiceProvider wiring so the advertised cap is reachable. Disabling the kernel control leaves the MCP setting as the effective ceiling. security-defaults.md records the same interaction. Acceptance: StreamableHttpTransportGuardTest::advertisedDefaultMatchesTheKernelBodyLimit and McpServiceProviderTest transport-size cases. -->
<!-- Spec reviewed 2026-09-05 - #2636 resources/list opaque AEAD pagination:
ApplicationMaster sealing plus ContentResourceListCursorCodec claim expiry;
Search catalogue resumes via SearchCatalogueScanPosition. #2638 MCP Registry
manifest remains a separate track. -->
<!-- Spec reviewed 2026-08-29 - #2636/#2638 stale deferrals (historical): two
live-prose constraints deferred current capability to issues that had already
closed. #2220's AEAD primitive landed as Foundation\Security\ApplicationMaster*
without built-in expiry; #2636 now registers purpose
waaseyaa.mcp.content-resource-list-cursor.v1 and enforces expiry in sealed
claims. #2207 landed
Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface, which
PackageManifestCompiler detects by string constant so a provider contributes
commands without importing Layer 6 — the layer obstacle that blocked the
Registry server.json console adapter is therefore gone, and what remains is
that nothing exposes McpRegistryManifest at all (#2638). The 2026-08-04 #1641
note below still reads "blocked on console-boundary issue #2207"; it is
superseded by this entry and left in place, as this log is append-only.
Documentation only; no behaviour change. -->

<!-- Spec reviewed 2026-08-27 - #2561 error-code allocation: eight codes the
advertised MCP 2026-07-28 revision forbids are renumbered. `-32020..-32099` is
reserved for the MCP specification (only -32020/-32021/-32022 are defined) and
`-32002`/`-32042` are retired; transport, rate-limit and infrastructure
refusals therefore move to a `-31xxx` band outside the JSON-RPC reserved range,
each keeping the last two digits of the code it replaced. `-32002` had carried
TWO meanings from one server - resource-not-found on resources/read and an
infrastructure outage on tools/call - so a client mapping it to
resource-not-found rendered an audit outage as a missing resource; audit-trail
(-31001) and approval-store (-31002) outages are now distinct, and
resources/read answers the specification-named -32602 in EVERY era, superseding
the 2026-08-05 note's "legacy -32002 bytes remain unchanged". New
`Waaseyaa\Mcp\McpErrorCode` is the single allocation point and states the
policy; `McpErrorCodeAllocationTest` tokenizes packages/mcp/src so a new
literal in a forbidden band fails in CI. `-32001`/`-32003`/`-32004` stay: the
legacy sub-range is SHOULD NOT, and those three are wire contracts clients
implement - recorded with rationale in `McpErrorCode::LEGACY_IN_USE`, and a NEW
legacy allocation still fails. Wire change for clients branching on the numeric
code; statuses, messages and data members are unchanged. Migration table in
docs/upgrade-notes/mcp-error-code-allocation.md. -->

<!-- Spec reviewed 2026-08-25 - #2520 FieldReadDenied mapping: `entity.read`
and `entity.search` omit a WP4 `FieldReadDenied` / `MissingFieldReadContext`
on a view-authorized entity, matching
`ResourceSerializer::attributesFromEntity()`. The call is a well-formed
success, never `INTERNAL_ERROR` and never a distinguishable field-forbidden
envelope. Absent vs view-forbidden entities stay the identical R8-c not-found
error. Pinned by `EntityToolFieldReadDeniedMappingTest`. -->

<!-- Spec reviewed 2026-08-05 - #2225 current-protocol resources: MCP 2026-07-28 server/discover now advertises resources exactly when the strict default-off resource flag and a non-empty provider registry are both present, and that same gate serves resources/list, resources/templates/list, and resources/read. Modern reads bind the required Mcp-Name mirror to params.uri using the protocol's canonical plain-ASCII/Base64-sentinel decoding. Capability authorization precedes provider selection and URI parsing; unavailable resources use the current -32602 contract without exposing URI data, while legacy -32002 bytes remain unchanged. Modern complete resource results carry resultType, server identity, ttlMs=0, cacheScope=private, and HTTP no-store. Each accepted read closes with exactly one cause-honest terminal audit stage. -->

<!-- Spec reviewed 2026-08-05 - #2196: the enabled anonymous MCP tier contributes one deployment-neutral mcp:public artifact to the separate experimental AI Catalog seam. It points to the Waaseyaa compatibility card as application/json and is never mislabeled as an MCP Server Card. Disabling the public tier removes the contribution; write/auth/approval/admin surfaces and representative site queries are excluded. -->

<!-- Spec reviewed 2026-08-04 - #1641 MCP identity and Registry discovery: `/.well-known/mcp.json` remains a Waaseyaa compatibility card and no longer embeds the invalid nested Registry projection. Official Registry `server.json` is a separate, deployment-owned `McpRegistryManifest`, pinned to schema 2025-12-11 and constructible only from an explicit namespaced id plus public HTTPS Streamable HTTP remote; no request Host is an authority source. One injected McpImplementationInfo now feeds legacy initialize, modern server metadata, the card, and the Registry manifest. Explicit mcp.implementation config wins; the framework monorepo uses its release-managed VERSION; consuming sites use Composer's installed waaseyaa/mcp version. The stale informational 0.1.0 response bytes deliberately migrate to the honest implementation version; protocol compatibility remains negotiated only by protocolVersion. Malformed identity/card/Registry config fails closed. Registry publication stays blocked until a real public deployment, namespace authentication, release, and submission-time preview-schema revalidation exist. The framework-neutral manifest model ships here; its CLI adapter is blocked on console-boundary issue #2207. -->

<!-- Spec reviewed 2026-08-30 - #2657 transport-neutral dispatch extraction (ADR-022 D-9.3): the dispatch behaviour of AgentToolRegistryBridge moved verbatim into waaseyaa/ai-tools (Layer 5) as Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher behind the new ToolDispatcherInterface, and the bridge is now a thin facade over it. Nothing on the wire changed: the same schema enforcement, the same sanitized INTERNAL_ERROR envelope, the same name-ordered tools/list, the same audit-stage classification, and the same mcp.tool_execution_failed / mcp.tool_output_schema_violation log keys (the dispatcher takes a log prefix and the facade passes "mcp"). Waaseyaa\Mcp\CapabilityScopedToolRegistry likewise delegates to a shared Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry, and a new sibling ToolIdAllowlistRegistry narrows by exact tool id for ADR-022 D-7. A new AuditedToolDispatcher decorator carries D-5.A (construction refuses an absent ledger or NullStrictAuditLedger, mirroring McpEndpoint:130-146) and D-5.B (reserve before the tool runs, finalize after, single-shot record() for terminal refusals, safeArguments from the tool own argumentsForAudit()); it is NOT wired into McpEndpoint, whose inline audit path — including the approval consume between reserve and execute — is unchanged. The reason for the move is packaging, not logic: McpRouteProvider registers /mcp/write unconditionally on install, so a local stdio plane reusing the bridge would have bought an HTTP route to get dispatch semantics. /mcp, /mcp/write, their auth defaults, and their route registration are untouched. -->

<!-- Spec reviewed 2026-08-07 - #2295: tools/list catalogues are sorted by tool name at AgentToolRegistryBridge, making the wire response independent of Composer manifest, optimized-classmap, and provider discovery order without changing internal registry order. -->
<!-- Spec reviewed 2026-08-04 - #2205 dual-era MCP 2026-07-28: request era is selected only from params._meta["io.modelcontextprotocol/protocolVersion"], never from HTTP headers. McpProtocolRequestValidator requires object-valued per-request client capabilities, validates optional client identity, and checks the required version/method/name mirrors after authentication, rate limiting, JSON parsing, and request acceptance; mismatches close the audit pair with invalid_params_refused and expose no raw header values. Modern routing implements server/discover, tools/list, and tools/call; adds resultType and server identity metadata; uses private/ttlMs=0 plus Cache-Control: no-store for principal-varying discovery, tool catalogues, and pre-route protocol refusals; rejects unsupported modern methods with HTTP 404; and accepts no modern core notifications. The legacy initialize/ping/notification lifecycle and successful-result bytes remain unchanged. Deliberate malformed-traffic change: legacy unknown version headers now return -32022 after authentication (or 401 before it), and stray modern mirrors fail -32020. StreamableHttpTransportGuard remains era-neutral while preserving Origin, size, content type, dual Accept, POST-only, and stateless JSON-response enforcement. -->

<!-- Spec reviewed 2026-08-04 - #2191 contract-truth reconciliation: the live endpoint methods, stage-aware dispatch event, conditional route set, protocol-header behavior, protected admin diagnostics, and deliberate resources/prompts absence were checked against current source. Removed legacy McpController claims are no longer presented as live behavior. -->

<!-- Spec reviewed 2026-08-04 - #2199: every admitted or infrastructure-refused MCP request now ends in an honest audit stage. A rate-limiter exception emits the sanitized terminal `rate_limiter_unavailable` stage before returning -32030/503; it is an infrastructure `error`, not a policy denial, and never carries the exception message. Protocol handlers that return a JSON-RPC error are no longer misclassified as `execution_succeeded`: -32602 closes as `invalid_params_refused`, while any other returned protocol error closes as `execution_failed`. -->

<!-- Spec reviewed 2026-08-04 - #2177 boundary correction: bearer-token storage and validation remain in waaseyaa/auth (L1), while the Symfony Console `bearer-token:*` presentation now lives in `Waaseyaa\CLI\Provider\BearerTokenServiceProvider` and `Waaseyaa\CLI\Command\BearerTokenConsoleCommands` (L6). This supersedes the command-ownership sentence in the 2026-08-03 F3 review note below. -->

<!-- Spec reviewed 2026-08-03 - #2177 F3 (enterprise bearer-token lifecycle): the write tier's production credential path is now DURABLE. New Waaseyaa\Auth\Token\Bearer\* (waaseyaa/auth, L1): BearerTokenStoreInterface + DatabaseBearerTokenStore own the auth_bearer_token table (hashed SHA-256 verifier of the full `mbt_<16hex>.<64hex>` wire token, never plaintext at rest; non-secret id + 16-hex fingerprint; mandatory expiry 60s..90d via injected EntityClockInterface, inclusive boundary; durable idempotent revocation; transactionally atomic rotation whose partial failure can never leave two usable credentials; audience + canonicalized bounded scopes persisted per token; verify() is constant-time over the verifier with a dummy compare on unknown ids and answers null fail-closed on outage/malformed records). One-time secret reveal is IssuedBearerToken (virtual-hook secret in a WeakMap: print_r/var_dump/json_encode redact, serialize() throws). Lifecycle operator commands: bearer-token:issue/list/rotate/revoke (owned by the Layer-6 CLI BearerTokenServiceProvider). MCP side: DurableBearerTokenAuth (WriteTierAuthInterface + new ScopedMcpAuthInterface→ScopedPrincipal) verifies audience `mcp:write`, resolves the owner by ACTIVE-owner query (uid + status=1 — a Protected `status` field read pre-auth has no read context) and snapshots it through AccountPrincipalFactoryInterface, so the principal id IS the owner uid (F1 separation-of-duties preserved; token ids never become identities). McpEndpoint intersects the tier registry with the token's scopes per request (CapabilityScopedToolRegistry; empty scopes ⇒ nothing; scopes narrow, never broaden — per-tool account capability enforcement unchanged). resolveWriteTierAuth() default: app override > DurableBearerTokenAuth (when store + user repo + principal factory resolve; no tokens issued ⇒ still 401) > BearerTokenAuth([]) fail-closed. Static BearerTokenAuth is quarantined to the empty fail-closed default and test fixtures. -->
<!-- Spec reviewed 2026-08-06 - public durable bearer elevation: PublicAnonymousAuth implements ScopedMcpAuthInterface, preserving a scoped delegate's exact token scopes and constraining legacy unscoped delegates to configured public capabilities. With no application override, McpServiceProvider composes the durable real-account path under the separate mcp:public audience and retains anonymous fallback. Missing, invalid, expired, revoked, and wrong-audience tokens cannot elevate. Scopes narrow but never grant permissions: the owner independently needs the called tool capability. -->
<!-- Spec reviewed 2026-08-03 - #2177 F4 (mcp-durable-audit): MCP write-tier auditing is now durable and outcome-aware. New fail-closed reserve/finalize ledger (Foundation\Audit\StrictAuditLedgerInterface port, Audit\Writer\DatabaseStrictAuditLedger implementation over the append-only strict_audit_ledger table), modelled on StrictPrivilegedReadLedgerInterface. GUARANTEED: no write tool is invoked without a durable record of the attempt (reserve commits before execute; failure returns -32002 and the tool never runs). NOT guaranteed and explicitly not claimed: atomicity between the mutation and the outcome record — tools commit their own transactions, DBALTransaction has no savepoints, and entity storage may be on another connection. A crash between the two leaves a queryable dangling reservation, which is never blindly retried or rolled back. McpDispatchEvent now fires per STAGE rather than once per request, carries stage/outcome/tool/correlation/safeArguments, and no longer populates raw params; 401 and 429 are now audited (reversing the former clause-16 silence). Public tier behaviour is unchanged and remains best-effort. -->

<!-- Spec reviewed 2026-08-03 - #2177 F2/F6 (mcp-public-boundary): TWO behaviour changes. (F2) `McpServiceProvider` no longer binds `McpAuthInterface` locally — a local binding sits ahead of the kernel-services bus in `ServiceProvider::resolve()`, so the package default silently shadowed every downstream application override and `/mcp` stayed anonymous regardless of what an app bound. Resolution moved to `resolvePublicAuth()` at the point of use (`resolveOptional() ?? new PublicAnonymousAuth()`), mirroring the `resolveWriteTierAuth()` pattern that already fixed this for the write tier (P0-1); the anonymous default is unchanged when no app binds anything. New `mcp.public.enabled` config gate withdraws BOTH `/mcp` and `/.well-known/mcp.json` from the route collection when false — 404 rather than 401, and the card goes with the endpoint it advertises. Absent = enabled (historical default); a supplied value must parse as a boolean from an explicit allowlist or `ConfigException` is raised during route setup — a typo in a control gating a public network surface must never be guessed at in either direction, and `filter_var(FILTER_VALIDATE_BOOL)` was rejected because it silently maps `null`/`''` to false. `/mcp/write` is deliberately not gated. (F6) Unhandled tool exceptions no longer reach the caller: `AgentToolRegistryBridge` and the 11 generic `catch (\Throwable)` arms in the entity/relationship/vector tools now return a fixed `INTERNAL_ERROR` envelope plus a random correlation id via the new `Waaseyaa\AI\Tools\Error\SanitizedToolError`. The log receives safe diagnostic METADATA only (correlation id, tool, exception class, file, line, integer code) — NOT the exception message, trace, bearer token, call arguments, or the Throwable object, because a log store is an indexed, widely-read egress path and relocating a credential into it is not a fix. Deliberate domain envelopes (Content Publishing, revision conflict, key refusal, per-tool `forbidden`) pass through untouched. Sanitization is independent of logger availability. -->

<!-- Spec reviewed 2026-07-30 - #2145: `tools/call` now enforces each tool's declared JSON Schema (draft 2020-12) server-side before the handler runs. Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator (ai-tools, L5) validates AgentTool::$inputSchema — the exact object tools/list advertises — inside AgentToolRegistryBridge::execute(), the single choke point both MCP tiers share. Violations short-circuit pre-dispatch with the established structured envelope {code: VALIDATION_FAILED, message, errors: [{field, message}]} + isError: true. Auth and rate-limit ordering unchanged (401/-32029 still precede validation). handleToolsCall also rejects non-string `name`, non-object `arguments`, and non-object `params` as -32602. See "Input-schema enforcement (`tools/call`)". -->
<!-- Spec reviewed 2026-07-29 - #2141: the account resolved from MCP bearer authentication now scopes both AccountContextInterface and AccountFieldReadScopeInterface. FieldReadGuard intentionally follows the latter, so a write-tier request must not inherit the unrelated HTTP-session principal. JSON-RPC routing runs inside AccountFieldReadScopeInterface::run($principal, ...) and restores the prior scope on every exit. Regression coverage uses a bearer principal with no session fallback. -->

<!-- Spec reviewed 2026-07-14 - R22/R24 (#2020): server-card authentication is now honest about the shipped opaque-bearer model (`none` or `bearer`; legacy `oauth2` config normalizes to `bearer`, while real OAuth 2.1 remains a separate product decision). Opaque string account ids map deterministically to a non-zero 60-bit audit actor id instead of PHP-casting to the anonymous sentinel. Five unused runtime dependencies and the two unconsumed MCP-local bridge interfaces were removed; AgentToolRegistryBridge remains the direct per-request adapter over Waaseyaa\AI\Tools\ToolRegistryInterface. The `/mcp/write` public-router + CSRF-exempt wiring is now regression-pinned. -->

<!-- Spec reviewed 2026-07-14 - R21 WP4 (#2010): the public-vs-destructive catalogue invariant is now pinned through a real AttributeToolRegistry hydrated from PackageManifest, then wrapped by the production ReadOnlyToolRegistry and CapabilityScopedToolRegistry boundaries; the regression no longer proves the invariant only against a handwritten registry double. No runtime contract change. -->

<!-- Spec reviewed 2026-06-22 - framework-cleanup-alpha245-01KVQSN8 WP17/WP18: the legacy McpController stack (McpController + Tools/ + Rpc/ + Cache/) was removed (#1738, closing #1642) — it was never HTTP-routed (live /mcp = McpEndpoint over the Bridge\ + ai-tools #[AsAgentTool] registry, unchanged). This spec's dead-stack documentation is retracted: the McpController Class / Tool Classes / RPC Support / Read-Path Cache sections, the first-party "Discovery Blend Tool Contract" (ai_discover/editorial_*/traverse_* — those tools went away with the stack), the Source-Files + File-Reference dead entries, and the "Legacy … remain" note. SEPARATELY (audit claim-mismatch, WP18): the "Serializer redaction shape" section (FR-006/FR-007/C-003) advertised an MCP field-redaction marker (McpEntityFieldFilter + {accessRestricted,…} + EntityTools::setFieldFilter() wiring + McpJsonApiFieldParityTest guard) that exists NOWHERE in code (the filter file was already absent pre-WP17). Probed against live code: the only missing piece is the marker SHAPE — field access IS enforced (EntityReadTool drops FieldAccessPolicyInterface-forbidden field values via EntityAccessHandler::filterFields, fail-closed via AttributeToolRegistry::markAccessEnforced, proven by EntityReadToolFieldFilterTest), so /mcp omits forbidden fields exactly like JSON:API. No data leak; the marker is post-beta polish, not a security fix. -->
<!-- Spec reviewed 2026-06-22b - field-redaction finding settled with a probe (WP18): confirmed /mcp does NOT return field values a caller may not see (EntityReadToolFieldFilterTest::never_leaks_field_access_forbidden_fields); retraction stands, marker shape deferred to post-beta. -->
<!-- Spec reviewed 2026-06-19 - Wayfinding Phase 5 (wayfinding-01KVGH5X): the authenticated MCP WRITE TIER. New SEPARATE route `/mcp/write` → `AuthenticatedMcpEndpoint::serve` (a thin controller composing an inner McpEndpoint configured for writes), so the public read-only `/mcp` is byte-identical and untouched (C-001). The inner endpoint uses (a) `WriteTierAuthInterface` — a marker auth bound by default to `BearerTokenAuth([])` (empty token map ⇒ every request fails closed 401 until an app re-binds it with its token→account map; NFR-002), distinct from the public `McpAuthInterface`=PublicAnonymousAuth binding so the two surfaces configure independently; and (b) `CapabilityScopedToolRegistry(fullRegistry, capabilities)` — the dual of `ReadOnlyToolRegistry`: it exposes ONLY tools whose capability is on an allowlist, destructive INCLUDED (default `['present guided content']`, override via `mcp.write_tier.capabilities`), so the tier surfaces exactly its own tools, never the whole destructive catalogue. Per-tool `AbstractAgentTool::requireCapability` remains the authorization layer. The wayfinding write tools themselves live in ai-agent (see ai-integration.md / wayfinding.md). See the "Authenticated write tier" section at the end of this spec. -->
<!-- Spec reviewed 2026-06-12 - mission request-surface-hardening-01KTX7F2 WP03 (#1652): BearerTokenAuth::authenticate() hardened. (1) Constant-time comparison — the array lookup is replaced by a full hash_equals() scan over EVERY token-map entry with no early exit (whole-call timing independent of which entry matches); map keys are (string)-cast before comparison because PHP coerces purely numeric token strings to int array keys. (2) Blocked-account fail-closed check — a matched account exposing isActive() (duck-typed method_exists; AccountInterface has no status member, no mcp→user manifest edge) that returns false is rejected with null, byte-indistinguishable from an unknown token (same 401 JSON-RPC envelope; no blocked-vs-invalid oracle). Zero added queries (NFR-003): kernels and token maps are per-request, so the in-memory isActive() read reflects persisted state as of this request's boot. Accounts without isActive() authenticate as before — custom McpAuthInterface implementations own their account objects' liveness semantics. Prefix handling and getTokens() (admin fingerprinting) unchanged. Pinned by BearerTokenAuthHardeningTest; the pre-existing 7-test BearerTokenAuthTest matrix passes unchanged. -->
<!-- Spec reviewed 2026-06-12 - mission revision-audit-provenance-01KTWY5V WP05: McpEndpoint gains two optional ctor params (?EventDispatcherInterface, ?AccountContextInterface, container-injected via McpServiceProvider's explicit binding); dispatch() scopes the acting-account context to the bearer-auth account post-auth/pre-parse with finally-restore, and fires Waaseyaa\Mcp\Event\McpDispatchEvent ('waaseyaa.mcp.dispatch': method, raw params, ?accountUid) exactly once per authenticated well-formed JSON-RPC request, post-parse pre-routing, best-effort; 401/parse-error fire nothing; event name pinned cross-package to McpDispatchAuditListener::EVENT_NAME (mcp does not require audit at runtime; new require-dev edge for the pin test). McpEndpoint Class section also updated to the real post-M3 two-required-dep signature. Independent of #1635/#1636. Refs #1645. -->
<!-- Spec reviewed 2026-05-25 - per-record-ai-access-flagship-01KSEFT5 WP02: McpEntityFieldFilter wired into McpController and EntityTools.getEntity(). Forbidden fields are now replaced by the canonical redaction marker {accessRestricted: true, reason: "field_forbidden_for_account"} rather than omitted. JSON:API omits; MCP redacts — both compliant with open-by-default; MCP redacts to preserve audit lineage. FR-005, FR-006, FR-007 satisfied. McpJsonApiFieldParityTest guards this contract. -->
<!-- Spec reviewed 2026-06-04 - PR #1614 incidental (clearing #1593 drift): the read-only admin surface gained `Waaseyaa\Mcp\Admin\RecentInvocationsQueryInterface` (M5C WP01 T003) — a narrow optional port (`recentForTool(string $toolName, int $limit): list<RecentInvocation>`) implemented by an ai-observability adapter when installed; `ToolRegistryReadModel` degrades to an empty recentInvocations list when absent, so packages/mcp keeps no hard compile-time dependency on waaseyaa/ai-observability. #1592/#1593 also Nuxt-prefixed the admin table component names so the tool tables render. No change to the /mcp JSON-RPC endpoint contract. -->
<!-- Spec reviewed 2026-05-25 - mcp-endpoint-admin-m5c-01KSEFTB: read-only admin surface (tool registry, per-tool detail, server config) -->
<!-- Spec reviewed 2026-05-23 - M3 WP04 (bimaaji-mcp-bridge-01KS5VS8): doctrine spec edits. Added supersession callout to the 2026-05-20 "Bimaaji MCP positioning (PHP-only)" section + new "Bimaaji MCP bridge" section at end of spec documenting the shipped surface (5 ai-agent tools, account-permission capability model, HTTP Streamable transport via /mcp, per-request bridge architecture, disk-write invariant, M-G → M3 transition rationale, post-WP01..WP03 file reference). Notes the divergence from the original AD-02 ten-tool inventory (collapsed to five by re-using IntrospectSection's section enumeration). -->
<!-- Spec reviewed 2026-05-23 - M3 WP03 (bimaaji-mcp-bridge-01KS5VS8): closed the WP02 placeholder-account caveat. McpEndpoint::__construct signature changed from (McpAuthInterface, Mcp\Bridge\ToolRegistryInterface, Mcp\Bridge\ToolExecutorInterface) to (McpAuthInterface, Waaseyaa\AI\Tools\ToolRegistryInterface). McpEndpoint::dispatch() now constructs the per-request AgentToolRegistryBridge with the account McpAuthInterface::authenticate() resolved from the Authorization header — so per-tool capability gating (AbstractAgentTool::requireCapability) runs against the auth-resolved identity rather than the boot-time placeholder. McpServiceProvider::register() dropped the three placeholder bridge bindings; only McpAuthInterface remains. Mcp\Bridge\ToolRegistryInterface + ToolExecutorInterface still @api as bridge contracts but no longer container-bound. New end-to-end BimaajiMcpCapabilityTest pins both positive (read account → success) and negative (mutation tool with read-only account → forbidden envelope) paths. -->
<!-- Spec reviewed 2026-05-23 - M3 WP02 (bimaaji-mcp-bridge-01KS5VS8): McpServiceProvider::register() now wires the bridge architecture documented in the Overview. Three new bindings — Mcp\Auth\McpAuthInterface → BearerTokenAuth(tokens: []), Mcp\Bridge\AgentToolRegistryBridge (singleton wrapping Waaseyaa\AI\Tools\ToolRegistryInterface from the kernel-services bus), and both Mcp\Bridge\ToolRegistryInterface + Mcp\Bridge\ToolExecutorInterface bound to the bridge singleton. Bridge account is a no-permission placeholder until WP03 lands per-request account passthrough (auth-resolved account from McpEndpoint::handle's typed injection). tools/list works through the bridge; tools/call returns the documented `forbidden` envelope. New end-to-end test tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php pins both behaviours. Also added: bimaaji_search_specs ai-agent tool (in packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php) + SpecIndexProvider container binding in BimaajiServiceProvider. -->
<!-- Spec reviewed 2026-05-22 - M3 WP01 (bimaaji-mcp-bridge-01KS5VS8): retired dead foundation McpRouter intercept (deleted packages/foundation/src/Http/Router/McpRouter.php + HttpKernel:411 entry + McpRouterTest); /mcp dispatch now flows exclusively through SSR AppControllerRouter → McpEndpoint::handle as already documented at line 6's note. Legacy McpController + Tools/ + Cache/ + Rpc/ classes remain in-place but unreachable from HTTP routing (still test-covered via direct instantiation in tests/Integration/Phase14/AiMcpIntegrationTest.php); a future cleanup mission may retire them. WP01 also pinned the SC-004 bimaaji surface in tests/Integration/PhaseN/Mcp/BimaajiMcpBootSmokeTest.php so M3's subsequent WPs cannot regress the four M2 tool contracts. -->
<!-- Spec reviewed 2026-05-18 - #1498 cleanup: packages/mcp/README.md key-classes line updated to point at McpServerCard/McpRouteProvider/EditorialTools (replacing stale McpServer/McpToolHandler reference); spec body already documents McpServerCard as the route controller and is unchanged. -->
<!-- Spec reviewed 2026-05-10 - WP05 php-8.5 upgrade: @PHP8x5Migration cs-fixer pass — McpServiceProvider touched by new_expression_parentheses rule only; no semantic change to MCP endpoint contract. -->
<!-- Spec reviewed 2026-05-01c - McpServiceProvider::routes() 2nd argument widened from concrete EntityTypeManager to EntityTypeManagerInterface (PHP 7.4+ contravariant parameter override of ServiceProvider abstract base, since EntityTypeManager implements EntityTypeManagerInterface); integration test caller (tests/Integration/Phase11/McpEndpointSmokeTest.php:116) now passes the in-scope $entityTypeManager mock; routes() body still ignores the argument (only registers MCP routes); argument retained for ServiceProvider contract compliance — interface-typing follows WP04 surface C precedent for admin-surface (mission #824 WP03 surface A + CI fixup) -->
<!-- Spec reviewed 2026-04-25 - McpEndpoint::handle typed injection (AccountInterface, Request) via AppControllerRouter; see docs/specs/app-controller-invocation.md -->
<!-- Spec reviewed 2026-04-21 - Overview: kernel boot JSON-first policy cross-link to infrastructure.md -->
<!-- Spec reviewed 2026-04-01 - post-M10 McpServiceProvider registration and provider-owned MCP routes, C18 drift remediation (#1017) -->
<!-- Spec reviewed 2026-04-08 - composer manifest policy normalization for packages/mcp; no MCP runtime behavior change -->
<!-- Spec reviewed 2026-04-09k - `McpTool` / `DiscoveryTools` relationship and visibility paths use `EntityValues` for cast-aware reads (#1181 ST-8) -->

## Overview

The `waaseyaa/mcp` package exposes Waaseyaa's entity system as a remote MCP (Model Context Protocol) server over Streamable HTTP. In the post-M10 baseline, package discovery loads `Waaseyaa\Mcp\McpServiceProvider` from `packages/mcp/composer.json`, and that provider owns MCP route registration. External AI assistants (Claude Desktop, Cursor, etc.) and custom AI agents connect to a single `/mcp` endpoint to discover and invoke CRUD tools for all registered entity types. The package sits in Layer 6 (Interfaces) alongside CLI, SSR, and Admin.

Kernel-level failures before MCP dispatch are governed by the JSON-first HTTP error policy in `docs/specs/infrastructure.md` ("HTTP error surface (JSON-first)"); MCP JSON-RPC responses apply only after the app boots successfully.

## Package

- **Location:** `packages/mcp/`
- **Namespace:** `Waaseyaa\Mcp\`
- **Dependencies:** `waaseyaa/access`, `waaseyaa/ai-tools`, `waaseyaa/api`,
  `waaseyaa/entity`, `waaseyaa/foundation`, `waaseyaa/routing`, and
  `waaseyaa/user`.

### Source Files

| File | Purpose |
|------|---------|
| `src/McpEndpoint.php` | Thin HTTP handler: auth and JSON-RPC dispatch for `initialize`/`ping`/`tools/list`/`tools/call` via a per-request bridge |
| `src/AuthenticatedMcpEndpoint.php` | Write-tier controller composing an inner `McpEndpoint` (see "Authenticated write tier") |
| `src/McpResponse.php` | Value object wrapping response body, status code, content type |
| `src/McpServiceProvider.php` | Package-owned service provider that registers MCP routes via `McpRouteProvider` |
| `src/McpRouteProvider.php` | Registers `/mcp` and `/.well-known/mcp.json` routes |
| `src/McpServerCard.php` | Generates the `/.well-known/mcp.json` server card |
| `src/McpImplementationInfo.php` | Shared implementation name/version projected by every MCP discovery response |
| `src/Registry/McpRegistryManifest.php` | Generates the official, schema-pinned deployment `server.json` artifact |
| `src/Auth/McpAuthInterface.php` | Pluggable authentication contract |
| `src/Auth/ScopedMcpAuthInterface.php` / `src/Auth/ScopedPrincipal.php` | Scope-aware auth contract: account + explicit token scopes (#2177 F3) |
| `src/Auth/DurableBearerTokenAuth.php` | Production write-tier auth over the durable `Waaseyaa\Auth\Token\Bearer` store (#2177 F3) |
| `src/Auth/BearerTokenAuth.php` | STATIC in-memory token map — quarantined to the empty fail-closed default and test fixtures (#2177 F3); constant-time full-scan comparison + blocked-account fail-closed check (#1652) |
| `src/Bridge/AgentToolRegistryBridge.php` | Façade over `Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher`; adapts the framework-wide registry to MCP descriptors and calls (#2657) |
| `src/ReadOnlyToolRegistry.php` / `src/CapabilityScopedToolRegistry.php` | Tool-visibility wrappers for the public read-only `/mcp` and the `/mcp/write` tier. The capability-scoped one delegates to `Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry` (#2657) |

### Optional content search tool

Installing `waaseyaa/search` is the explicit composition opt-in for
`content.search`. The Layer 5 ai-tools package owns the adapter; MCP has no
runtime or development dependency on Search. Catalogue construction checks
class/interface availability without resolving services, so boot and
`tools/list` do not open the database. The provider is resolved only for an
authorized call. If Search is absent the tool is absent; if an installed
binding is broken the listed tool returns a sanitized correlated error.
The error uses the stable `TOOL_UNAVAILABLE` code so clients can avoid blind
retries without learning anything about the binding failure.

The tool is non-destructive, idempotent, closed-world, and uses the distinct
`tool.content.search` capability. Anonymous exposure is default-off even when
Search is installed and requires the strict boolean
`mcp.public.content_search_enabled`; malformed values fail boot. It passes the
exact immutable request principal to Search, whose safe-read boundary owns
entity, field, workflow, tenant, and community visibility and computes
counts/facets only after authorization. The adapter copies bounded properties
into a closed output schema and rejects malformed provider output. Pagination
is limited to Search's raw 1,000-candidate relevance window plus one truncation
sentinel. The closed result exposes `is_complete: false` when that window is
exhausted; totals, pages, and facets are then lower bounds, and filters or
non-relevance sorts apply only inside the window. An all-denied or fully
filtered window can therefore be empty and incomplete without exposing a
denied identifier, value, count, facet, or score. Dispatch auditing retains
sort/pagination shape but replaces query and free-text filters with lengths or
counts.

### Principal-safe content resources

With an installed resource provider and strict
`mcp.public.content_resources_enabled = true`, the public endpoint implements
MCP 2025-11-25 `resources/list`, `resources/templates/list`, and
`resources/read`. Initialize advertises
`resources: {subscribe: false, listChanged: false}` and the server card reports
resources true in the same composition. Disabled or providerless surfaces do
not advertise the capability and treat the methods as unknown; an explicitly
enabled providerless composition fails closed.

The Layer 5 `ContentResourceRegistry` is MCP-neutral and bounded. Its optional
Search adapter resolves the database-backed catalogue only during a resource
call and passes the exact immutable principal. Search scans no more than 500 raw
pointers to return at most 50 safe projections per page. When
`ApplicationMasterKeyring` is composed, `resources/list` accepts an opaque
`cursor` and may emit `nextCursor` — a purpose-bound AEAD envelope
(`waaseyaa.mcp.content-resource-list-cursor.v1`) whose sealed claims carry the
provider-local resume token, principal binding, and an application-enforced
expiry (`ContentResourceListCursorCodec::DEFAULT_TTL_SECONDS`). Without the
keyring, cursors are refused and `nextCursor` is omitted (first-page discovery
only). Responses never include a raw count or hidden-position signal; an empty
page with a `nextCursor` is valid when a scan window yields no visible
projections. The window is discovery, not a complete inventory; directly
addressed visible content remains readable even when it is not listed. The
endpoint's existing per-principal rate limiter runs before every resource
method.

Canonical resource URIs are
`waaseyaa://content/<unpadded-base64url-public-path>`. Decoding rejects aliases,
padding, percent encoding, dot segments, repeated slashes, controls, query,
fragment, backslash, invalid UTF-8, and paths over 1,024 bytes. An indexed URL
is a candidate only; the canonical access-checked projection must byte-match
the requested public path. Malformed URIs return `-32602`; denied and missing
well-formed reads return the same `-32602` body (#2561 retired `-32002`). Successful reads contain one
bounded UTF-8 `text/plain` entry and no document id, protected field, draft,
absolute path, or raw-index metadata. CMS-authored text remains untrusted input.

Denied and missing reads intentionally share `AuthorizationRefused` in the
operator audit grammar: it means the resource was not resolvable under this
principal, without claiming whether an inaccessible canonical resource exists.
Response structure is identical, but latency remains a weak residual channel:
a raw path miss can finish before a colliding pointer that requires canonical
resolution. The implementation bounds that work instead of claiming
constant-time database/entity access.

> The legacy `McpController` + `Tools/` + `Rpc/` + `Cache/` files were removed in WP17 — see "Legacy `McpController` stack — REMOVED" below.

## Package Discovery and Route Ownership

`packages/mcp/composer.json` declares `Waaseyaa\Mcp\McpServiceProvider` in `extra.waaseyaa.providers`. During kernel boot, manifest discovery instantiates that provider and `McpServiceProvider::routes()` delegates directly to `McpRouteProvider`.

This means MCP route ownership no longer depends on foundation fallback registration. The authoritative MCP HTTP surfaces are the provider-owned `/mcp` endpoint and `/.well-known/mcp.json` server card.

## McpEndpoint Class

`McpEndpoint` is the main HTTP handler. It is a `final readonly class` with two required dependencies and optional dispatch-audit, acting-account, rate-limit, and guarded-field-read collaborators:

- `McpAuthInterface $auth` -- authenticates the request.
- `Waaseyaa\AI\Tools\ToolRegistryInterface $agentRegistry` -- the framework-wide agent tool registry, wrapped per-request by `AgentToolRegistryBridge` with the auth-resolved account.
- `?EventDispatcherInterface $dispatcher = null` (Symfony contracts) -- optional; fires the `waaseyaa.mcp.dispatch` event (see "Dispatch event seam" below). When absent, the event is silently not fired (best-effort audit semantics).
- `?AccountContextInterface $accountContext = null` -- optional acting-account holder (`Waaseyaa\Access\Context\`); when absent, no context scoping happens (behavior identical to before the context existed).
- `?RateLimiterInterface $rateLimiter = null` plus its numeric configuration -- optional per-principal rate limiting; disabled when absent or configured with a non-positive maximum.
- `?AccountFieldReadScopeInterface $fieldReadScope = null` -- optional guarded-read scope. When present, JSON-RPC routing runs as the bearer principal rather than the unrelated HTTP-session principal.

`McpServiceProvider` binds `McpEndpoint` explicitly so `AppControllerRouter`'s controller resolution injects the kernel-services event dispatcher, acting-account context, rate limiter, and field-read scope. Optional collaborators degrade to null when the kernel bus cannot supply them.

### handle() Method

```php
public function handle(
    AccountInterface $account,
    HttpRequest $request,
): McpResponse
```

This follows the typed `AppControllerRouter` contract (see **`docs/specs/app-controller-invocation.md`**): only framework services and explicit route bags are injected; `McpEndpoint` takes the account and request. It extracts the HTTP method, body, and `Authorization` header from the request and delegates to a private `dispatch()` method.

### dispatch() (private)

The internal dispatch method processes requests in this order:

0. **Guard Streamable HTTP in `serve()`** -- before dispatch, validate Origin,
   method, request size, and media types. A transport
   refusal never reaches authentication, rate limiting, JSON parsing, or tools.

   The kernel can refuse ahead of this guard, and used to do so in the wrong
   vocabulary: `BodySizeLimitMiddleware`'s 413 and `HttpKernel::parseJsonBody()`'s
   400 both answered in JSON:API, making the guard's own oversize refusal
   (`McpErrorCode::REQUEST_TOO_LARGE`, `-31043`) and the endpoint's `-32700`
   unreachable (#2594). Both MCP routes therefore declare their kernel-refusal
   codes through `RouteBuilder::refusalTransport()`
   (`McpRouteProvider::REFUSAL_CODES`), so a kernel-level refusal on `/mcp` or
   `/mcp/write` arrives as a JSON-RPC error object with the same code, message,
   and `id: null` the guard would have produced. See
   `docs/specs/middleware-pipeline.md` "Route-declared refusal envelopes".
   Note that the effective size cap on these routes is the *lower* of
   `http_security.body_size_limit.max_bytes` (1 MiB default) and
   `mcp.transport.max_request_bytes` (`StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES`,
   also 1 MiB). Raising the MCP setting above an enabled kernel cap is refused
   at provider wiring. The kernel's refusal reports its own cap in
   `error.data.max_request_bytes`.
   The kernel fast path also requires a digit-only `Content-Length`
   (`/^\d+$/D`); a garbage header is not rewritten as `-31043`, so the
   guard can still answer `-32600` Invalid Content-Length.
1. **Authenticate** -- calls `$this->auth->authenticate($authorizationHeader)`. If null is returned, responds with HTTP 401 and a JSON-RPC error (code `-32001`, message "Unauthorized"). The 401 envelope is identical for every `null` cause — missing/malformed header, unknown token, or a token whose account is blocked (#1652) — so callers cannot distinguish a blocked token from an invalid one.
2. **Scope the acting-account context** -- immediately after successful auth (before body parsing), the endpoint captures the prior `AccountContextInterface` value and sets the bearer-auth-resolved account. The prior value is restored in `finally` -- including when a routed handler throws -- because the MCP account deliberately differs from any session account. No-op when no context was injected.
3. **Parse one JSON-RPC message** -- decodes the body with `json_decode()`. On `JsonException`, returns HTTP 400 parse error (`-32700`). Batch arrays, a non-`2.0` envelope, an invalid request id, or a malformed request return HTTP 400 (`-32600`). Valid client response messages are accepted with HTTP 202 and no body.
4. **Accept and audit the request** -- see "Dispatch event seam" below. Every authenticated, parsed request with a valid method and id emits `request_accepted` before parameter, protocol, or route validation, and then exactly one terminal stage.
5. **Classify and validate the protocol era** -- body metadata is authoritative. A request carrying `params._meta["io.modelcontextprotocol/protocolVersion"] = "2026-07-28"` is modern, must also carry object-valued `io.modelcontextprotocol/clientCapabilities`, and must carry matching `MCP-Protocol-Version` and `Mcp-Method` headers, plus a matching `Mcp-Name` on `tools/call` (including the protocol's Base64 sentinel form). Optional client identity must have string `name` and `version`. Missing, malformed, or mismatched mirrors return HTTP 400 / `-32020`; unsupported body or legacy header versions return HTTP 400 / `-32022`; malformed modern metadata returns HTTP 400 / `-32602`. Headers can never reclassify a legacy body. Validation failures close the accepted audit pair without recording raw header values.
6. **Dispatch inside the bearer field-read scope** -- `AccountFieldReadScopeInterface::run()` scopes the guarded entity-read principal to the bearer identity for the complete routed call and restores the prior scope afterward. This is separate from `AccountContextInterface`: `FieldReadGuard` deliberately consults the immutable field-read scope, not the HTTP session or acting-account holder. The routed call then uses the selected era:
   - Legacy `initialize` validates lifecycle params and negotiates `2025-11-25`, `2025-06-18`, or `2025-03-26` (preferring the latest legacy revision), then returns capabilities and server info.
   - `notifications/initialized` and `notifications/cancelled` -- accepted as notifications with HTTP 202 and no response body. Cancellation is advisory for this synchronous, task-free server profile.
   - Legacy `ping` returns an empty result.
   - Modern `server/discover` reports every supported revision, the principal-scoped tool capability, server identity, and private zero-TTL caching.
   - Both eras support `tools/list` and `tools/call` through the same per-request access-checked bridge. The protocol-visible catalogue is sorted by tool name at that bridge boundary, so identical tool sets produce identical discovery responses regardless of Composer manifest, classmap, or provider discovery order; the underlying registry retains its internal order. Modern successful results add `resultType: complete` and server identity metadata; modern tool catalogues add `ttlMs: 0`, `cacheScope: private`, and `Cache-Control: no-store`.
   - A legacy unknown method returns `-32601` with the historical HTTP 200. A modern unsupported method returns the same JSON-RPC code with HTTP 404. Modern core notifications are not defined by the current protocol and are refused without a JSON-RPC response.

   A `params` member that is not a JSON object is rejected with `-32602`
   before routing rather than substituting an empty parameter bag.

### Dispatch event seam (`waaseyaa.mcp.dispatch`)

Added by mission `revision-audit-provenance-01KTWY5V` (FR-007, #1645) and
expanded by the enterprise hardening in #2177. `McpEndpoint` emits stage-aware
events so the audit projection records what happened rather than merely that a
request reached routing.
The listener projects each sanitized event into the OCAP audit log.

**Event:** `Waaseyaa\Mcp\Event\McpDispatchEvent`, dispatched under
`McpDispatchEvent::NAME = 'waaseyaa.mcp.dispatch'`.

| Field | Type | Notes |
|---|---|---|
| `method` | string | JSON-RPC method (`tools/call`, `tools/list`, `initialize`, `ping`, …) |
| `params` | array | Compatibility-only property; endpoint events always leave it empty |
| `accountUid` | `?int` | Bearer-auth-resolved account id, or null when no principal was established |
| `correlationId` | string | Joins all stages emitted for one request |
| `tier` | string | `public` or `write` |
| `stage` | `?string` | Audit stage; null only for a legacy out-of-tree construction |
| `toolName` | `?string` | Requested tool for `tools/call` |
| `safeArguments` | array | Tool-owned redacted audit projection, never raw params |
| `metadata` | array | Safe structural outcome metadata |

**Firing contract:**

- Fires once per meaningful **pipeline stage**, not once per request.
  Authentication rejection, rate-limit denial, and rate-limiter outage each
  emit one pre-acceptance terminal stage; admitted messages emit
  `request_accepted` and exactly one terminal stage.
- Parse-error and unnamed invalid-request bodies fire nothing because no
  request can be honestly identified or admitted. A 401 is recorded with a
  null actor and no credential material.
- `execution_succeeded` is emitted only for a successful JSON-RPC result.
  Returned `-32602` errors close as `invalid_params_refused`; all other returned
  protocol errors and malformed internal responses close as
  `execution_failed`.
- The event is the best-effort compatibility projection. Strict refusal and
  write-attempt evidence uses the durable ledger; projection failure never
  changes the JSON-RPC response.
- **Name pinning**: `McpDispatchEvent::NAME ===
  McpDispatchAuditListener::EVENT_NAME` is pinned by a cross-package test.
  The string literal is intentionally duplicated — mcp must not require
  audit at runtime (audit is a `require-dev` edge for the pin test only).
- **Independence**: the seam fires as the endpoint exists today; it does not
  depend on (nor fix) the #1635/#1636 transport bugs or #1640 OAuth — those
  remain separate work.

### McpResponse

A `final readonly class` value object:

```php
final readonly class McpResponse
{
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public string $contentType = 'application/json',
    ) {}
}
```

All endpoint responses are wrapped in `McpResponse`. The front controller converts this to a proper HTTP response.

## Legacy `McpController` stack — REMOVED (WP17, #1738 / closes #1642)

A second, older tool controller — `McpController` (`handleRpc()`/`manifest()`) and the helpers used only by it (`Tools\{McpTool,DiscoveryTools,EditorialTools,EntityTools,TraversalTools}`, `Rpc\{ResponseFormatter,ToolIntrospector}`, `Cache\ReadCache`) — was removed in WP17 ([#1738](https://github.com/waaseyaa/framework/pull/1738), closing #1642). It was never routed, bound, or constructed by any provider: `/mcp` has always been served by **`McpEndpoint`** (documented above), which exposes tools through `AgentToolRegistryBridge` over the framework-wide `Waaseyaa\AI\Tools` registry — not through these classes. The 12 first-party tools this stack defined (`search_entities`/`ai_discover`/`traverse_relationships`/`editorial_*`) were unique to it and went away with it; the live endpoint serves the auto-discovered `#[AsAgentTool]` catalogue described under **Tool Registry** below.

## Authentication

### McpAuthInterface

```php
interface McpAuthInterface
{
    public function authenticate(?string $authorizationHeader): ?AuthorizationPrincipalInterface;
}
```

Takes the raw `Authorization` header value. Returns the immutable authenticated `AuthorizationPrincipalInterface` or `null` on failure. This is the same actor contract used by `AgentToolInterface` and principal-aware domain services, so a transport-valid identity can invoke every tool advertised to it without a lossy runtime conversion. Legacy providers migrate explicitly through `DelegatingAuthorizationPrincipal`; the framework never guesses missing claims or discards arbitrary permission behavior.

### Public-tier auth resolution and the `mcp.public.enabled` gate

`McpServiceProvider` binds **no** default for `McpAuthInterface`. A local binding would sit in the provider's own bindings, and `ServiceProvider::resolve()` consults those *before* the cross-provider kernel-services bus (`packages/foundation/src/ServiceProvider/ServiceProvider.php`) — so a package default silently beat any application binding, and `/mcp` stayed anonymous no matter what a downstream app did. That is the same P0-1 shadowing already fixed for `WriteTierAuthInterface`; the public tier now follows the identical pattern.

Resolution happens at the point of use, in `McpServiceProvider::resolvePublicAuth()`:

```
resolveOptional(McpAuthInterface::class)   // own bindings (empty) → kernel-services bus → app binding
    ?? new PublicAnonymousAuth(            // anonymous fallback plus optional elevation
        delegate: durableBearerAuth(audience: 'mcp:public'),
    )
```

The two tiers differ only in their fallback, deliberately:

| Tier | Resolver | Fallback when no application binding | Rationale |
|---|---|---|---|
| Public `/mcp` | `resolvePublicAuth()` | scope-aware `PublicAnonymousAuth` plus durable `mcp:public` delegate when wireable | Anonymous read remains the default; credentials may elevate only to their real account and the intersection of tier capabilities, token scopes, and account permissions. |
| Write `/mcp/write` | `resolveWriteTierAuth()` | `BearerTokenAuth([])` (every request 401) | A write surface with no configured identity must be unusable; token→account mapping is inherently application-specific. |

**The gate.** `mcp.public.enabled` decides whether the public pair is routed at all.

| Value | Result |
|---|---|
| key absent | enabled (historical default) |
| `true`, `1`, `"1"`, `"true"`, `"on"`, `"yes"` (case-insensitive, trimmed) | enabled |
| `false`, `0`, `"0"`, `"false"`, `"off"`, `"no"` | disabled |
| anything else — `null`, `""`, `"flase"`, floats, out-of-range ints, arrays, objects | **`ConfigException` during provider/route setup** |

**Absent means default; present means it must parse.** A typo in a control governing a public network surface cannot be guessed at safely: reading `"flase"` as enabled silently publishes the endpoint the operator meant to close, and reading it as disabled silently withdraws a surface a deployment depends on. Refusing to boot is the only outcome that is wrong in neither direction.

The implementation is an explicit allowlist, **not** `filter_var(..., FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)` — that maps both `null` and `''` to `false`, which would have silently withdrawn the endpoint for a key an operator left blank. `McpServiceProviderTest` pins that PHP behaviour alongside the throw, so the reason the allowlist exists cannot be refactored away.

`mcp.public` must itself be a map; `mcp.public: false` throws (naming `mcp.public`) rather than being read as enabled. The exception names the key and `get_debug_type()` of the value **only** — configuration routinely holds credentials and exception messages reach logs and error pages.

When disabled, `McpRouteProvider` registers **neither** `mcp.endpoint` **nor** `mcp.server_card` — both paths 404. The routes are withdrawn rather than left answering 401 so the surface does not confirm an MCP server is present, and the discovery card goes with the endpoint because a card advertising a 404 is worse than no card. `mcp.endpoint.write` is **not** gated: an authenticated write tier with no anonymous read tier is a supported production shape.

Covered by `McpServiceProviderTest` (route registration + flag parsing) and `Integration\PublicTierAuthOverrideTest` (override honoured under either provider ordering, anonymous default preserved, and the enabled/disabled routing outcomes asserted through `WaaseyaaRouter::match()`).

### BearerTokenAuth

MVP implementation that maps opaque bearer tokens to user accounts, hardened by mission request-surface-hardening-01KTX7F2 (#1652, FR-005/FR-006):

```php
final readonly class BearerTokenAuth implements McpAuthInterface
{
    /** @param array<string|int, AuthorizationPrincipalInterface> $tokens */
    public function __construct(private array $tokens) {}
}
```

Behavior (`authenticate()` decision order):
- Returns `null` if the header is missing or empty.
- Returns `null` if the header does not start with `Bearer ` (case-insensitive check). These prefix checks run before any comparison, exactly as before #1652.
- **Constant-time full scan (FR-005):** the token (characters after `Bearer `) is compared against **every** entry of the token map with `hash_equals()` — no early exit on match, one return after the loop. Per-comparison timing is `hash_equals`' constant-time guarantee; whole-call timing does not depend on *which* entry matches. Each map key is `(string)`-cast before comparison — PHP coerces purely numeric token strings to `int` array keys, and `hash_equals()` requires strings; a numeric token authenticates correctly (pinned by test).
- **Blocked-account rejection, fail closed (FR-006):** when the matched account exposes `isActive()` (duck-typed `method_exists`) and it returns `false`, `authenticate()` returns `null`. The caller-visible outcome is identical to an unknown token — `McpEndpoint` emits the same 401 JSON-RPC envelope, so there is no blocked-vs-invalid oracle. `AccountInterface` has no status member and is deliberately not widened; the framework's `User` entity exposes `isActive(): bool` (the same liveness accessor the session login query's `status = 1` condition mirrors), and no `mcp → user` manifest edge is added (research D4). **An account object without an `isActive()` method authenticates as before** — custom `McpAuthInterface`/account implementations own the liveness semantics of their own account objects.
- **Zero added queries (NFR-003):** the status read is an in-memory method call on the already-resolved account object. Kernels — and therefore token maps and their account objects — are constructed per request in every runtime, so the read reflects persisted state as of the current request's boot. No re-load, no cache, no new I/O.
- Each token maps to a specific user account, so MCP tool calls respect entity access control.
- `getTokens()` (the admin-fingerprinting accessor, `ServerConfigReadModel` contract) keeps returning the raw token→account map, unchanged.
- No token expiry in the opaque-bearer model. For the credential model that
  does carry expiry, revocation and audience binding, see the OAuth
  resource-server section below.

### OAuth 2.1 resource server (write tier)

The package ships the **resource-server half** of the MCP authorization model.
It does not ship an authorization server, and it does not ship a token
validator — see "What does not ship" below, which is the part that decides
whether a deployment can actually turn this on.

`OAuthMcpAuth` implements both `WriteTierAuthInterface` and
`ScopedMcpAuthInterface`, so a validated token narrows the request's tool
surface as well as authenticating it:

```php
final readonly class OAuthMcpAuth implements WriteTierAuthInterface, ScopedMcpAuthInterface
{
    public function __construct(
        private OAuthAccessTokenValidatorInterface $validator,
        private OAuthProtectedResourceMetadataConfig $resource,
        private ?LoggerInterface $logger = null,
    ) {}
}
```

**Decision order in `authenticateWithScopes()`** — every branch fails closed:

- No header, or a header that does not start with `bearer ` (case-insensitive),
  or an empty token after the prefix → `null`.
- `$validator->validate($token, $this->resource->resource)` — the configured
  **resource identifier is passed on every call**, so audience binding (RFC
  8707) is a parameter of the trust boundary rather than an optional check a
  validator may forget to make.
- Any `Throwable` from the validator → logged with the exception **class only**
  (never its message, which can carry token material) and treated as `null`. A
  validator outage is an authentication failure, not a bypass.
- A principal with an **empty scope list → `null`**. An empty list means "may
  use nothing", never "may use everything".

`ScopedPrincipal` carries the **real owning account**, never a token-derived
identity — approval separation-of-duties comparisons keep their meaning — plus
the granted capability scopes. `McpEndpoint` intersects the tier's registry
with those scopes fail-closed.

#### RFC 9728 protected-resource metadata

`OAuthProtectedResourceMetadataConfig` is validated at construction, so a
malformed deployment fails at boot rather than at first challenge:

| Rule | Effect |
|---|---|
| `resource` and each `authorization_servers` entry must be absolute HTTP(S) URIs with no credentials, query, or fragment | `InvalidArgumentException` |
| HTTPS required except on `localhost` / `127.0.0.1` / `::1` | `InvalidArgumentException` |
| `authorization_servers` non-empty and duplicate-free | `InvalidArgumentException` |
| `scopes_supported` entries must be valid OAuth scope tokens (RFC 6749 `scope-token`: no space, no `"`, no `\`) and duplicate-free | `InvalidArgumentException` |

`toArray()` emits `resource`, `authorization_servers`,
`bearer_methods_supported: ["header"]`, and — only when set —
`scopes_supported` and `resource_documentation`. `OAuthProtectedResourceMetadata`
serves it with `Cache-Control: public, max-age=300`.

The same object owns **both** the discovery path and the challenge, which is
why they cannot drift apart:

- `metadataPath()` → `/.well-known/oauth-protected-resource` + the resource's
  path (path-specific per RFC 9728, so `/mcp/write` gets its own document).
- `metadataUri()` → the absolute form, IPv6 hosts bracketed.
- `challenge()` → `Bearer resource_metadata="<metadataUri>"`, plus
  `, scope="<space-joined scopes>"` when scopes are configured. This is the
  `WWW-Authenticate` value `McpEndpoint` returns on 401.

`McpRouteProvider` registers `mcp.oauth_protected_resource` at
`metadataPath()` **only when** the metadata config is present.

#### What does not ship

- **No `OAuthAccessTokenValidatorInterface` implementation.** The interface is
  the trust boundary and nothing in the tree implements it (the only
  implementations are anonymous classes in `OAuthMcpAuthTest`). A deployment
  must supply one, and it MUST, before returning a principal: verify the
  token's issuer, its integrity or introspection response, its expiry and
  revocation state, and its audience/resource binding; **map the subject to an
  active real account**; and return only the granted capability scopes. A token
  issued for another resource MUST return `null` and MUST never be passed
  through downstream. Until a validator exists `OAuthMcpAuth` cannot be
  constructed.

  The active-account duty is the validator's alone. `OAuthMcpAuth` does **not**
  re-check liveness after `validate()` returns — unlike `BearerTokenAuth`,
  which duck-types `isActive()` on the account it matched. A validator that
  returns a principal for a disabled user authenticates that user.
- **No automatic wiring.** `resolveWriteTierAuth()` has no OAuth branch: it
  resolves an application-bound `WriteTierAuthInterface`, then falls back to
  `DurableBearerTokenAuth`, then to `BearerTokenAuth([])` (always 401).
  Choosing OAuth means binding `WriteTierAuthInterface` to `OAuthMcpAuth`.
- **No authorization server.** `waaseyaa/oauth-provider` exists in the tree but
  is not wired to this surface, and PKCE is an authorization-server and client
  concern that a resource server does not participate in.
- **Scopes are capability ids, and the two vocabularies do not fully agree.**
  `scopes_supported` rejects any value containing a space, while the shipped
  default write-tier capability is the literal `present guided content`. A
  capability whose id contains a space cannot be advertised as a scope. #1640
  tracks the profile decision.

#### Three lists must agree

A tool is reachable on the OAuth write tier only when its capability appears in
**all three** of these, which are configured independently and never derived
from one another:

1. `mcp.write_tier.capabilities` — the tier allowlist. Defaults to
   `['present guided content']`.
2. `scopes_supported` — advertised in the RFC 9728 document and the
   `WWW-Authenticate` challenge.
3. the scopes actually granted on the presented token, by the authorization
   server.

`McpEndpoint` admits the intersection, fail-closed. Any mismatch produces a
caller that authenticates successfully and then sees an **empty `tools/list`**,
with no error to read — configuring `scopes_supported` while leaving
`capabilities` at its default is the common form, and intersects to nothing.

Independently of scope, `tool.entity.*` mutations remain blocked unless
`mcp.write_tier.allow_generic_entity_mutations` is `true`
(`genericEntityMutationToolBlocklist()`); the framework-supported remote
editing path is an app-registered `ContentToolSet`.

## Per-request bridge execution

`AgentToolRegistryBridge` is a concrete, request-scoped adapter over the
framework-wide `Waaseyaa\AI\Tools\ToolRegistryInterface`. It is intentionally
not a container extension seam: the AI tools registry is the canonical tool
contract, while the bridge binds the authenticated account for one request.

### Execution flow

```
McpEndpoint::handleToolsCall()
    -> AgentToolRegistryBridge::execute($toolName, $arguments)
    -> AgentToolDispatcher::dispatch()                                      <-- #2657
    -> ToolInputSchemaValidator::validate($tool->inputSchema, $arguments)   <-- #2145
    -> AgentToolInterface::execute($arguments, $authenticatedAccount)
    -> Result as {content: [{type: "text", text: "..."}]}
```

### The bridge is a façade over a transport-neutral dispatcher (#2657)

**Nothing on the wire changed.** As of #2657 (ADR-022 D-9.3) the bridge's
behaviour lives in `Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher`
(`waaseyaa/ai-tools`, Layer 5) behind `ToolDispatcherInterface`, and
`AgentToolRegistryBridge` delegates to it. The same schema enforcement, the
same sanitized `INTERNAL_ERROR` envelope, the same name-ordered `tools/list`,
the same audit-stage classification, and the same `mcp.tool_execution_failed` /
`mcp.tool_output_schema_violation` log keys — the dispatcher takes a log prefix
and the façade passes `mcp`.

**The reason is packaging, not logic.** `McpRouteProvider` registers
`/mcp/write` unconditionally the moment `waaseyaa/mcp` is installed, so a local
stdio plane that wanted these dispatch semantics would have had to buy an HTTP
route to get them (ADR-022 C-4, D-1.4). Dispatch never needed HTTP; only its
address did. `Waaseyaa\Mcp\CapabilityScopedToolRegistry` delegates to a shared
`Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry` for the same reason,
and a sibling `ToolIdAllowlistRegistry` narrows by exact tool id for ADR-022
D-7's closed-list default profile.

`tests/Architecture/TransportNeutralToolDispatchTest.php` proves the neutrality
by running a complete dispatch in a child process with no Composer autoloader —
only `ai-tools`, `access`, and `foundation` are loadable, and any class
requested outside them is recorded rather than loaded.

**`AuditedToolDispatcher` is not wired into `McpEndpoint`.** The new decorator
carries ADR-022 D-5.A (construction refuses an absent ledger or a
`NullStrictAuditLedger`) and D-5.B (reserve before the tool runs, finalize
after, single-shot `record()` for terminal refusals, `safeArguments` from the
tool's own `argumentsForAudit()`) for the transports that will consume it.

Its terminal-refusal semantics are deliberately **stricter than this
endpoint's**. `McpEndpoint::auditTerminal()` is best-effort: a refusal it cannot
record is still returned, and the gap is logged. `AuditedToolDispatcher` instead
answers `AUDIT_TRAIL_UNAVAILABLE`, the same envelope a failed reservation
produces, because on that surface every executable call is already refused when
the ledger is down — so letting terminal refusals through unrecorded buys no
availability and makes "recorded" and "lost" indistinguishable. It also projects
the caller-supplied tool name through `auditOperation()` before building any
reservation, since `StrictAuditReservation` rejects an empty `operation` and the
tool name is caller-controlled; a blank name becomes the fixed operation
`tool_name_unusable` and the raw value travels in `metadata`. The HTTP tiers are
unaffected: `McpEndpoint` builds its own reservations and is unchanged.
`McpEndpoint` keeps its own inline audit path unchanged, because that path
interleaves the once-only approval `consume()` between `reserve()` and
execution — a sequencing the generic decorator does not model, and one whose
correctness is not worth re-deriving for no behavioural gain.

## Input-schema enforcement (`tools/call`)

Added by **#2145** (follow-up to #2136), found during the alpha.278 rhtcircle
production-shaped acceptance re-run.

Every `#[AsAgentTool]` publishes a JSON Schema draft 2020-12 `inputSchema`
through `tools/list` — that schema is the contract an agent reads, so it is
also the contract the server holds callers to. Previously `tools/call`
dispatched `params.arguments` straight to the handler: an `article.rollback`
call missing its required `target_revision_id` reached `ContentToolSet`'s
handler, raised `Undefined array key`, and reached the publisher with a
zero-ish default. It failed safely ("Revision 0 does not exist",
`isError: true`, no mutation) only because of an incidental downstream guard.

### Where enforcement lives

`Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge::execute()` — the **single choke
point both MCP tiers share** (public `/mcp` and authenticated `/mcp/write`
each construct their own bridge per request, so one insertion covers both).
The schema enforced is `AgentTool::$inputSchema`, the **exact object
`toMcpDescriptor()` advertises**: advertised and enforced cannot diverge, and
there is no second source of truth to drift (the dual-state bug pattern).

The validator itself is `Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator` in
**`waaseyaa/ai-tools` (Layer 5)** — the package that owns the `inputSchema`
contract — not in `mcp` (Layer 6). This keeps it reusable by any same-or-higher
layer executor (e.g. a future `AgentExecutor` enforcement pass) without an
upward import. `mcp` already requires `ai-tools`, so no new manifest edge.

### Validated keyword subset

Dependency-free, no `justinrainbow/json-schema` or `opis/json-schema` added:

| Supported | Notes |
|---|---|
| `type` | Single or list. `object` accepts any array that is not a non-empty list; `array` accepts any list; `integer` accepts integral floats (JSON has one number type, so `2.0` is a valid integer); `boolean` never satisfies `string`/`number` |
| `properties`, `required`, `additionalProperties` | `additionalProperties` honours both `false` (unexpected argument → violation) and a subschema (each extra member validated against it, e.g. `EntityListTool`'s `sort` → `{enum: [ASC, DESC]}`) |
| `enum`, `const` | Strict comparison |
| `items`, `minItems`, `maxItems`, `uniqueItems` | Item violations carry the index (`tags.1`); `uniqueItems` compares decoded-JSON items with strict equality like `enum`/`const` (`1` ≠ `"1"`, `1` ≠ `1.0`) and reports the later duplicate's index |
| `minLength`, `maxLength`, `pattern` | Length is `mb_strlen`; an invalid schema regex yields no verdict rather than failing the caller's input |
| `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum` | |
| `allOf`, `anyOf`, `oneOf` | In-place applicators (#2737). `allOf` applies every subschema and reports their violations as-is; `anyOf` passes when at least one alternative accepts the value; `oneOf` passes when exactly one does (more than one match is a violation too). When no alternative accepts the value and exactly one alternative fits the value's *type*, that alternative's own violations are reported at their real path (`values.related.0` → `Must be >= 1`); otherwise a single `Does not match any of the N allowed alternatives.` violation lands on the field. Applicators run after the value's own keywords, so a `type` mismatch beside `anyOf` still yields one violation |

**Unrecognised keywords are ignored** (`default`, `description`, `format`,
`$schema`, `x-*`) — a schema is never *rejected* for vocabulary the validator
does not police, so `inputSchema` stays declarative documentation first. The
table above is the **complete enforced vocabulary**; it is a documented subset,
not a claim of full draft 2020-12 conformance, and `$ref` is not implemented.
The applicators exist because first-party tools advertise them: #2737 measured
`ContentToolSet`'s nullable `anyOf`, reference-list `items.oneOf` and
`uniqueItems` being advertised by `tools/list` yet ignored at admission, so
schema-invalid nullable/reference input reached the handler and was refused
only by publishing's domain validator (`execution_failed`, not
`input_validation_refused`). Every keyword a descriptor advertises must be
enforced by this validator — never by a transport- or tool-local guard — so a
new keyword in a first-party schema is added here first.

**Decoded-JSON value model:** arguments arrive as `json_decode($body, true)`
produces them, so a JSON object is an associative PHP array and the empty
array satisfies both `object` and `array` — `{}` and `[]` are indistinguishable
after an associative decode, and rejecting either would break legitimate
empty-argument calls. An **empty schema (`[]`) validates nothing**, so a tool
that declares no schema behaves exactly as before.

### Error envelope

A violation short-circuits **before** `$tool->impl->execute()` — handlers never
see malformed input — and returns the established structured envelope inside
the MCP `isError` result, reusing the machine code and `{field, message}` shape
Content Publishing already emits so an agent parses a schema rejection exactly
like a domain rejection:

```json
{
    "code": "VALIDATION_FAILED",
    "message": "Arguments do not satisfy the declared input schema for \"article.rollback\".",
    "errors": [{"field": "target_revision_id", "message": "This argument is required."}]
}
```

Nested paths are dotted (`values.title`), array items indexed (`tags.1`), and a
root-level mismatch reports `(arguments)`. Violations are emitted in a
deterministic order — declared `required` first, then declared `properties`,
then unexpected keys — so a retrying agent always sees the same first error.

### Ordering invariants

Full `tools/call` order, with the new step marked:

```
authenticate  ->  rate limit  ->  registry scoping (tool lookup)
              ->  SCHEMA VALIDATION (#2145)  ->  per-tool requireCapability  ->  handler
```

Authentication, rate limiting, and **registry scoping** are unchanged and all
precede validation. Registry scoping is the tier-level authorization boundary:
`getTool()` consults the tier's own registry wrapper, so a tool the tier does
not expose returns `-32602` "Unknown tool" before any validation runs — which
is what keeps **C-001** intact (a destructive tool is never merely
"invalid-arguments" on the public surface, it does not exist there).

Schema validation runs **before** each tool's own
`AbstractAgentTool::requireCapability()`, because that check lives inside
`execute()` — the very call this change must not reach with malformed input.
The consequence is that an authenticated caller who both lacks the capability
*and* sends schema-invalid arguments now sees `VALIDATION_FAILED` rather than
`forbidden`. This discloses nothing new: the tier's `tools/list` already
publishes that tool's full schema to any caller the tier admits (registry
scoping is tier-wide; capability is per-account), so the validation message
only restates what the caller was already told. Capability enforcement itself
is unweakened — schema-valid input from a caller lacking the capability still
returns `forbidden`, unchanged.

| Caller state | Outcome |
|---|---|
| Unauthenticated (write tier), schema-invalid payload | HTTP 401, `-32001` — **never** a validation envelope (no oracle for whether the payload would have been valid) |
| Authenticated, over rate budget, schema-invalid payload | HTTP 429, `-31029` |
| Authenticated, within budget, schema-invalid payload | HTTP 200, `isError: true`, `VALIDATION_FAILED` |
| Authenticated, schema-valid, lacking the capability | HTTP 200, `isError: true`, per-tool `forbidden` (unchanged) |
| Public `/mcp`, destructive tool | `-32602` "Unknown tool" — structurally absent, never reached (C-001 intact) |

### Relationship to Content Publishing validation

Schema enforcement **does not weaken or replace** the publishing layer's own
field-level validation. `ContentToolSet::valuesSchema()` deliberately declares
**no `required`** on the writable-values object (partial `updateDraft` payloads
are legitimate), so publishing keeps ownership of required-field semantics: a
`createDraft` whose `values` omits a required field is schema-valid and still
returns publishing's own `VALIDATION_FAILED` with its `{field: "slug"}` error.
The two layers stack — schema shape first, editorial rules second.

### Coverage

| Test | Level |
|---|---|
| `packages/ai-tools/tests/Unit/Schema/ToolInputSchemaValidatorTest.php` | Unit — the keyword matrix |
| `packages/mcp/tests/Unit/Bridge/AgentToolRegistryBridgeValidationTest.php` | Unit — handler never invoked, envelope shape |
| `packages/mcp/tests/Unit/McpEndpointSchemaOrderingTest.php` | Unit — auth/rate-limit ordering, malformed `params` shapes |
| `tests/Integration/PhaseN/Mcp/McpToolsCallSchemaEnforcementTest.php` | Production-shaped — the real `ContentToolSet` over revisionable SQLite through the real `/mcp/write` tier: the reported payload, wrong types, unexpected properties, the full draft→publish→rollback→unpublish lifecycle, idempotent replays, and both authenticated and unauthenticated surfaces |

## Tool result content blocks

Every tool result — success and failure alike — carries MCP content blocks, and
MCP defines exactly five block types: `text`, `image`, `audio`, `resource` and
`resource_link`. A block of any other type is unreadable to a schema-validating
client, which is the whole population this endpoint exists to serve.

A tool returning structured data emits **both** halves:

```php
return AgentToolResult::success(
    content: [['type' => 'text', 'text' => $json]],
    structuredContent: $data,
);
```

The text block is what a client without schema support renders; the
`structuredContent` is what a client with schema support consumes. They carry
the same payload, and `json_decode($text)` must equal `structuredContent`.

Before #2520 the entity, vector and relationship tools emitted `['type' =>
'json', 'data' => …]` with no `structuredContent` and no text mirror. `type:
"json"` is not an MCP content type, and `AgentToolRegistryBridge` forwards
`$result->content` to the wire verbatim, so that is precisely what reached the
client: four of the five tools on the anonymous tier returned a block a
conforming client had to discard. `ContentSearchTool` was already conformant,
which is why `content.search` was the one anonymous tool that worked.

`packages/ai-tools/tests/Unit/AnonymousTierMcpContentBlockTest.php` and
`packages/ai-agent/tests/Contract/Tool/` pin this for both tiers by executing
the tools and asserting on real emitted results.

### FieldReadDenied on anonymous `entity.read` / `entity.search`

These two tools project stored fields through the WP4 accessor after the
legacy open-by-default `FieldAccessPolicyInterface` filter. A Neutral policy
still lets a Protected field name through; `FieldReadGuard` then denies the
`get()`. That denial is mapped **per field** by
`EntityFieldRedaction::toReadableCastAwareMap()`:

- On a **view-authorized** entity the denied field is omitted from
  `entity.read` `values` and from the `entity.search` haystack. The tool
  returns success. This is the same mapping
  `ResourceSerializer::attributesFromEntity()` already applies on JSON:API
  (`FieldReadDenied | MissingFieldReadContext` → skip).
- The field is **never named** in an error envelope. A distinguishable
  `FIELD_FORBIDDEN` would let an anonymous caller tell "exists but a field is
  fenced" from "absent" — the existence oracle `EntityReadTool` closed (R8-c).
- Absent and view-forbidden **entities** still collapse to the identical
  `"%s/%s not found"` envelope. Field-level omission does not reuse that
  envelope, so a published page with Protected `status`/`uid` remains readable.

Pinned by `EntityToolFieldReadDeniedMappingTest` (anonymous published-content
read/search plus the not-found oracle byte-identity).

### JSON-RPC results are objects

A JSON-RPC `Result` is an object. PHP encodes an empty array as `[]`, so a
handler returning `[]` puts `"result":[]` on the wire and the official
TypeScript SDK rejects it on schema — `ping` did exactly this until #2520.
`McpEndpoint::jsonRpcResult()` normalises an empty result to `new \stdClass()`
at the single point where every handler crosses into JSON, so no future handler
can reintroduce the array form by returning nothing.

### Conformance runs over a real socket

`packages/mcp/tests/Support/Http/` boots a `php -S` server and drives `/mcp`
with a zero-dependency JSON-RPC client, asserting on response bytes and on a
re-decode that preserves the `{}` versus `[]` distinction. This exists because
every other MCP test is in-process: real wiring and real routing, but nothing is
ever serialised, and the #2520 encoding defects lived in that step. The
harness skips rather than fails when the environment cannot host a server.

## Tool error envelope and exception sanitization

Every tool failure returns inside the MCP result envelope with `isError: true` and a `text` content block holding a JSON object with a machine-readable `code`. There is one shape for all of them, so an agent parses a schema rejection, a missing tool and a domain refusal identically.

| `code` | Source | Notes |
|---|---|---|
| `TOOL_NOT_FOUND` | `AgentToolRegistryBridge` | Built from the caller's own tool name, never echoed from the exception. An off-tier tool is hidden behind this same response by the tier registries — "not registered" and "not yours" are indistinguishable. |
| `VALIDATION_FAILED` | `AgentToolRegistryBridge` (#2145) | `errors` lists `{field, message}`. |
| `INTERNAL_ERROR` | `AgentToolRegistryBridge` / `AbstractAgentTool::internalError()` | An unhandled exception. See below. |
| Domain codes | The tool itself | `REVISION_CONFLICT`, `ASSET_REJECTED`, Content Publishing field errors, per-tool `forbidden` refusals. **Passed through unchanged** — these are authored, machine-readable results an agent acts on. |

### `INTERNAL_ERROR` — what the caller is not told

A thrown exception's message is operator-facing: it routinely carries DSN fragments, credentials, absolute filesystem paths and internal class names. Returning it verbatim handed all of that to the caller, including an anonymous one on the public tier.

`Waaseyaa\AI\Tools\Error\SanitizedToolError` is the single source of truth for the replacement. The caller receives only:

```json
{
  "code": "INTERNAL_ERROR",
  "message": "<fixed literal — interpolates nothing>",
  "meta": {"correlation_id": "<16 hex chars>"}
}
```

**The log receives safe diagnostic metadata, not exception detail.** `Waaseyaa\Foundation\Log\LoggerInterface` gets a fixed key set under `mcp.tool_execution_failed` (bridge) or `agent_tool.execution_failed` (a tool's own catch):

| Key | Value |
|---|---|
| `correlation_id` | identical to the caller's — the only join between the two sides |
| `tool` | tool name |
| `exception` | exception class |
| `file` / `line` | throw site |
| `code` | only when `getCode()` is an **integer** |

Deliberately excluded: **the exception message, the stack trace, the bearer token, the call arguments, and the `Throwable` object itself.**

The message is not merely kept out of the response — it is kept out of the log too. A log store is not a private channel: it is shipped to aggregators, indexed, retained, and read by people with far broader access than the operator debugging one failure. Copying a DSN or credential from the response into the log store relocates a disclosure rather than fixing one. The trace is excluded more strongly still, since it carries argument *values* frame by frame. The `Throwable` is never attached because a logger that serializes context objects (JSON, `var_export`, an error tracker's payload builder) would walk straight into the message and trace this design excludes.

A non-integer `getCode()` — PDO's SQLSTATE string, or anything a custom exception interpolated — is dropped rather than inspected. An int cannot carry a credential; a "does this string look sensitive?" test would be exactly the guesswork this design avoids.

Diagnosis path: take the correlation id from the caller's response, find the log line, reproduce under a debugger — under an access decision someone actually made.

**Sanitization does not depend on a logger.** Both paths default to `NullLogger`; without one the caller-visible bytes are identical and the metadata is simply discarded. A logging gap can cost diagnosability, never open a leak.

Two enforcement points, because there are two ways a failure reaches a caller:

- **Thrown** — an exception escaping `execute()` is caught by `AgentToolRegistryBridge::execute()`, the transport boundary.
- **Returned** — the entity/relationship/vector tools each wrap their storage work in a generic `catch (\Throwable)` so one failure cannot take down an agent run, and used to embed `$e->getMessage()` in the returned `AgentToolResult`. Those 11 arms now call `AbstractAgentTool::internalError()`. The logger is attached at hydration by `AttributeToolRegistry`, the same way the access handler is. Typed domain catches (`EntityValidationException`, `RevisionConflictException`, the `LogicException` "not revisionable" arms) are untouched.

`AgentToolResult::summary` — the audit/transcript line — is a separate egress path and previously defaulted to the raw message; it now carries only the code and correlation id.

### Coverage (sanitization)

| Test | Level |
|---|---|
| `packages/ai-tools/tests/Unit/Error/SanitizedToolErrorTest.php` | Unit — tool-level arm, summary, with/without logger, fixed-literal message |
| `packages/mcp/tests/Unit/Bridge/AgentToolRegistryBridgeSanitizationTest.php` | Unit — bridge arm, correlation-id uniqueness, domain envelopes passing through untouched |
| `packages/mcp/tests/Unit/McpEndpointErrorSanitizationTest.php` | End-to-end — assertions on the **raw** HTTP response body, plus the bearer token and raw arguments being absent from the log |

## Durable, outcome-aware write auditing (#2177 F4)

Before F4 the MCP audit trail could not answer the questions an operator actually asks. One `mcp.dispatch` row was written per request, **before routing**, inside a `catch (\Throwable) {}`; its `outcome` was the literal `'allowed'`; it named no tool; and it carried only `sha256(params)`. Authentication rejections and rate-limit refusals returned *before* the event fired, so credential probing left no trace at all.

### The event model

A request now emits one record per meaningful **stage** (`Waaseyaa\Foundation\Audit\AuditStage`), and `outcome` is *derived* from the stage — never hardcoded:

| Stage | Outcome | When |
|---|---|---|
| `authentication_rejected` | `denied` | absent / unknown / malformed token, or inactive principal |
| `rate_limited` | `denied` | the limiter refused the request |
| `rate_limiter_unavailable` | `error` | the limiter could not make a durable admission decision; the request failed closed |
| `request_accepted` | `allowed` | authenticated, parsed, admitted for routing |
| `invalid_params_refused` | `denied` | malformed JSON-RPC envelope or protocol params: non-object `params`, missing/non-string `name`, non-object `arguments`, or a handler-returned `-32602` |
| `method_lookup_refused` | `denied` | no handler for the requested JSON-RPC method on this endpoint |
| `tool_lookup_refused` | `denied` | no tool of that name is visible on this tier |
| `input_validation_refused` | `denied` | arguments violate the declared `inputSchema` |
| `audit_unavailable_refused` | `error` | the durable ledger refused the pre-execution reservation, so the call was refused unexecuted |
| `authorization_refused` | `denied` | the tool's own capability / entity / field guard refused |
| `approval_required` | `denied` | F1 gate: a destructive call was durably challenged (or re-challenged while pending) and is waiting on a human decision |
| `approval_refused` | `denied` | F1 gate: a supplied approval id was unknown / tuple-mismatched / denied / expired / consumed, or the once-only consume was lost to a race |
| `execution_succeeded` | `allowed` | the routed operation — a tool call **or a protocol method** — ran and returned a success result |
| `execution_failed` | `error` | a tool or protocol handler returned or threw a failure |

**F1 approval-controller prerequisite (landed, #2177):** the CSRF machinery the approval HTTP endpoint needs is in place. The approval controller's route must be declared with `RouteBuilder::requireCsrf()` (`_csrf = true`), which makes `CsrfMiddleware` validate the token on state-changing methods **even for `application/json` / `application/vnd.api+json`** — the default content-type exemption does not protect a cookie-authenticated JSON endpoint, and an approval decision is exactly such an endpoint (the admin operator approves from a session-cookie-authenticated SPA, unlike the bearer-authenticated `/mcp/write` tier itself, which stays `csrfExempt()`). The admin SPA receives the `XSRF-TOKEN` cookie on API responses carrying both an authenticated account and the `waaseyaa_uid` login-session marker (`CsrfMiddleware::attachCookieIfAuthenticated()`, seeded at boot by `GET /api/user/me`); bearer-only requests do not receive it. `useApi().apiFetch` forwards the token as `X-XSRF-TOKEN` on non-safe methods to same-origin destinations only. **Origin check (landed with the C1b controller):** an exact-match `Origin` check against the deployment's allowed origins. It is deliberately *not* in `CsrfMiddleware` — the CORS allowlist lives at the kernel (`CorsHandler`), and a blanket middleware same-origin guard would break the supported cross-port Nuxt-dev deployment — so `McpApprovalController::decide()` performs it as defense-in-depth: the `Origin` header must be present and strictly identical to the request's own `scheme://host[:port]` or to one `cors_origins` entry (no substring, suffix, wildcard, regex, or host-only matching; the `CorsHandler` dev-localhost regex mode is deliberately NOT reused).

`request_accepted` is legitimately `allowed` — the request *was* admitted. The load-bearing property is that the **terminal** stage states what actually happened.

**The pair invariant:** every authenticated, parsed, accepted request emits `request_accepted` and then **exactly one honest terminal stage** — no request is left as a bare admission. Mutation-free protocol methods (`initialize`, `ping`, `tools/list`) close with `execution_succeeded` only when their returned JSON-RPC envelope is a valid success result. A returned `-32602` closes with `invalid_params_refused`; another returned protocol error, a malformed internal protocol response, or a thrown handler failure closes with `execution_failed`. Thrown failures and malformed internal responses answer with a sanitized JSON-RPC `-32603` whose `error.data.correlation_id` matches the pair and log safe metadata only: correlation id, method, and exception class when one exists — never an internal response body, exception message, trace, or params. An unknown method closes with `method_lookup_refused`; a non-object top-level `params` and every early malformed `tools/call` envelope shape close with `invalid_params_refused`. Only malformed members' *type* is recorded — a malformed value is raw caller input and never rides into any record. A rate-limiter outage happens before acceptance and emits the single `rate_limiter_unavailable` terminal. Two refusals emit nothing: a JSON parse error (`-32700`) and an Invalid Request whose `method` is missing or not a string (`-32600`) — a request that cannot be honestly named cannot be audited or admitted. Successful protocol reads are **projection-only** (best-effort `audit_event`, no durable row): the strict ledger evidences refusals and write attempts, and a durable row per `ping` would be amplification with no durability guarantee behind it. Terminal *refusals* of accepted requests are durably `record()`ed on the write tier.

### The durability guarantee, and its exact limit

The authenticated write tier uses a **fail-closed reserve/finalize ledger** (`Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface`, implemented by `Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger` over the append-only `strict_audit_ledger` table). It is modelled directly on `StrictPrivilegedReadLedgerInterface`, which established this pattern for privileged reads.

**Guaranteed:** *no write tool is invoked without a durable record of the attempt.* `reserve()` commits before `execute()`; if it cannot, the tool is never called and the caller receives JSON-RPC `-31001` whose `error.data.correlation_id` carries the request's correlation id — the join between the caller's refusal and the operator's `mcp.audit_reservation_failed` critical log line. No exception detail leaves (F6).

**Where fail-closed starts and stops — the exact refusal semantics when the ledger itself fails:**

- **Pre-execution reservation (`reserve()`), write tier:** fail-closed. A `StrictAuditLedgerException` refuses the call unexecuted (`-31001` + `audit_unavailable_refused` projection). A ledger that breaks its exception contract and throws anything else propagates out of the endpoint — still fail-closed (the tool is never invoked; no mutation without evidence), just without the polished `-31001` envelope.
- **Terminal refusal records (auth rejections, 429s, method/params/tool/schema refusals):** durable only when the ledger accepts the write. If recording fails — for *any* throwable, contract-conforming or not — the already-safe refusal response is **still returned** and the gap is logged at `critical` (`mcp.audit_terminal_record_failed`). Refusals perform no side effect, so the fail-closed rule ("no mutation without durable evidence") is not weakened; failing an already-safe refusal on ledger availability would convert an audit outage into a wider denial of service.
- **Post-execution `finalize()`:** never alters the response for any throwable — the side effect has already happened, so the dangling reservation is logged at `critical` (`mcp.audit_finalize_failed`) and the caller gets the real result (see crash-window semantics below).

**A broken logger cannot defeat any of the above (#2780).** Every one of these reports is made *after* the caller-visible outcome is already decided — a refusal that stands, or a completed `tools/call` that cannot be un-run — so `McpEndpoint` routes all of them through a private `reportAuditFailure()` helper that wraps the `critical()` call in `try`/`catch (\Throwable)`. Without it an injected logger that throws would escape `handleToolsCall()` — which `handle()` dispatches directly, with nothing downstream to catch for it — and reach the HTTP client as a transport failure for a write that had *already committed*, inviting the caller to retry an action that already happened. The sites covered: `mcp.audit_reservation_failed`, both `mcp.audit_finalize_failed` reports (post-execution and the refusal path's `finalizeQuietly()`), `mcp.audit_terminal_record_failed`, `mcp.approval_store_unavailable`, and `mcp.approval_consume_failed`. The logging failure is deliberately not re-logged: there is no `LoggerInterface` convention for logging a logging failure, recursing into a broken sink risks looping, and `error_log()` is reserved for the logging infrastructure itself. `waaseyaa/ai-tools`' `AuditedToolDispatcher` holds the same property through its own private helper of the same name — deliberately duplicated rather than shared, since `mcp` is Layer 6 and `ai-tools` is Layer 5 and a containment detail is not worth a new cross-package public symbol.

**The sibling `error()` family is contained the same way (#2886).** Six further `logger?->error(...)` sites — the rate-limiter durability check, `protocolExecute()`'s handler-threw and malformed-response branches, `resourceProtocolExecute()`'s same two branches, and `executeResourceRead()`'s handler-threw branch — report protocol/resource dispatch failures with no committed side effect behind them, so they are a different family from the `critical()` one above. They route through a private sibling, `reportOperationalFailure()` (same try/catch shape, `error` instead of `critical`), rather than through `reportAuditFailure()` itself, so the two families' logged severities stay distinct. Four of the six report *before* their own `auditTerminal()` call (the rate-limiter check, both handler-threw branches, and `executeResourceRead()`'s); for those, an unguarded throw would have both crashed the request *and* suppressed the terminal audit record entirely — worse than any `critical()`-family gap, which always reports after the outcome is already durable or already decided. The other two (`protocolExecute()` and `resourceProtocolExecute()`'s malformed-response branches) report after their `auditTerminal()` call and are unreachable through any conforming handler — every handler produces a well-formed JSON-RPC body — so `packages/mcp/tests/Unit/McpEndpointAuditLoggerContainmentTest.php` drives them directly on the private method via reflection, the same technique `McpEndpointDispatchEventTest` already uses for that branch.

**NOT guaranteed — and deliberately not claimed — is atomicity** between the tool's mutation and the outcome record. They are separate commits, and in the general case cannot be joined:

1. Tools commit internally — `IdempotencyStore::execute()` opens and commits its own transaction so the mutation and its replay record are atomic. The bridge holds no handle on that boundary.
2. `DBALTransaction` has no savepoint support, so a legitimate inner domain rollback under DBAL's default nesting would poison an enclosing audit write.
3. Entity storage resolves through `ConnectionResolverInterface`, so a multi-connection deployment puts entity writes on a different connection from the audit log entirely.
4. Pre-durability and atomicity are mutually exclusive: a record committing *with* the mutation is by definition not durable *before* it.

### Crash-window semantics

A crash (or a `finalize()` failure) after the tool commits but before the outcome is written leaves a **dangling reservation** — a `reserved` row with no matching `finalized` row:

```sql
SELECT r.receipt_id, r.correlation_id, r.operation, r.actor_uid, r.created_at
FROM strict_audit_ledger r
LEFT JOIN strict_audit_ledger f
  ON f.receipt_id = r.receipt_id AND f.event_type = 'finalized'
WHERE r.event_type = 'reserved' AND f.id IS NULL;
```

Read it as **"outcome unknown; the side effect may have committed."** It must **never** trigger a blind retry or rollback — the mutation already happened, and repeating it would duplicate it. `McpEndpoint` logs `mcp.audit_finalize_failed` at `critical` and returns the caller's real result; a completed write is not failed retroactively because its outcome row was lost.

A double-finalize is rejected in two places: the application guard in `finalize()`, and a `UNIQUE(receipt_id, event_type)` index so it is impossible at the storage layer even under a race.

### Redaction contract

Recorded: tool name, acting principal (three-state `null` / `0` / N), tier, correlation id, stage, real outcome, and **`argumentsForAudit()` output** — the tool's own redaction transform, never the raw JSON-RPC params.

Never recorded: Authorization headers, bearer tokens, raw secrets, unredacted content, uploaded binary/base64 payloads, exception messages or traces. An **unknown** tool cannot supply `argumentsForAudit()`, so only the requested name and `argument_count` are stored — argument *values* for an unresolvable tool are entirely caller-controlled.

Authentication failures record a `null` actor and no credential material. Absent, unknown, malformed and inactive-principal tokens are recorded identically, so the audit trail does not become the account-existence oracle the 401 response deliberately is not.

### Tiers and configuration

| | Public `/mcp` | Write `/mcp/write` |
|---|---|---|
| Strict ledger | no | **yes** |
| Durability | best-effort (`audit_event` only) | fail-closed pre-record + outcome |
| Human-approval gate (F1, below) | never | **yes** for destructive tools |
| Config | — | `mcp.write_tier.durable_audit` (default **true**), `mcp.write_tier.approval.{enabled, ttl_seconds}` (default **true**, 900) |

The public tier keeps its documented best-effort behaviour: it mutates nothing, so a durable pre-record buys no safety, and making a read-only surface fail-closed on audit availability would be a self-inflicted outage.

`mcp.write_tier.durable_audit` **fails closed on a wiring gap**: if it is on and no `StrictAuditLedgerInterface` is bound (typically because `waaseyaa/audit` is absent), `McpServiceProvider` throws at setup rather than substituting `NullStrictAuditLedger`. A write tier that *looks* durably audited and records nothing is worse than one that refuses to boot. It parses with the same strict boolean allowlist as `mcp.public.enabled`.

The same contract is enforced by **`McpEndpoint` itself, independent of provider wiring**: constructing it with `durableAudit: true` and an absent ledger — or the record-nothing `NullStrictAuditLedger` — throws `LogicException` at construction. (The former internal escape, `$this->auditLedger ?? new NullStrictAuditLedger()`, silently downgraded a direct construction to unaudited mutation; it is removed.) `NullStrictAuditLedger` remains the legitimate default only for surfaces that never opted into durability, i.e. the public read-only tier.

## Human-approval gate for destructive write-tier calls (#2177 F1, slice B)

The server-enforced half of the F1 approval gate: on a gated endpoint, a tool declared `destructive: true` executes **only** against a matching, approved, unexpired, unconsumed durable approval (`Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface`; storage semantics in `docs/specs/ocap-audit-log.md` §"Operation approval event log"). Enforcement reads the tool's declared `AgentTool::$destructive` — never the advisory descriptor hints.

> **Decision routes landed (C1b); UI still pending.** The admin decision HTTP surface exists (see §"Admin decision surface" below); the admin-SPA UI is the remaining #2177 slice. Until the UI lands, operators decide via the JSON API directly.

### Admin decision surface (#2177 F1, slice C1b)

The secured JSON admin API for operator decisions, in `packages/api` (family: `/api/mcp/*` admin endpoints; router `McpApprovalApiRouter`, controller `McpApprovalController`, routes in `ApiServiceProvider::routes()` gated on `mcpInstalled()`):

- **`GET /api/mcp/approvals`** (`api.mcp.approvals.index`) — one bounded pending page via `OperationApprovalStoreInterface::listPending()`. Query params `limit` (integer-shaped, store-validated 1..100, default 50) and `cursor` (the previous page's opaque `nextCursor`); an invalid limit or malformed/tampered cursor is 400. Response: `data` = serialized requests (id, status, principal key, surface, operation, arguments fingerprint, correlation id, `safeArguments` only — raw arguments never leave the fingerprint), `meta.limit` + `meta.nextCursor`.
- **`POST /api/mcp/approvals/{id}/decision`** (`api.mcp.approvals.decision`) — body exactly `{"decision": "approve"|"deny"}` plus optional `"reason"` (normalized via `ApprovalRequest::normalizeDecisionReason()`); any other member — including any client-supplied operator identity — is 400, not silently ignored.

**Access model (both routes):** `requireAuthentication()` + `requireSession(['waaseyaa_uid'])` — a REAL login session, so a bearer-only identity (the principal class being supervised) can never reach the surface — plus the capabilities `mcp.approval.view` (GET) / `mcp.approval.decide` (POST), seeded by `Waaseyaa\Access\Capability\McpApprovalCapabilities` (the `AgentCapabilities` pattern). The decision route is the first production `requireCsrf()` consumer (CSRF validated despite the JSON content type, per the prerequisite above). The controller adds the two gates the route layer cannot express: the exact-origin check (documented above) and **separation of duties** — the server-derived operator (via `DecisionAccountResolver` from `_authorization_principal`/`_account`; positive-int uid or fail-closed 403; request JSON never consulted) must not equal the request tuple's `principalKey` (exact string identity, `(string) $uid === $principalKey` — the same semantics the MCP endpoint used to build the tuple, `principalKey = (string) $principal->id()`). Self-approval is 403 unless `mcp.write_tier.approval.allow_self_approval` (default **false**, parsed fail-closed in `ApiServiceProvider`) says otherwise — a STRICT boolean in the literal sense: only PHP `true`/`false` are accepted, deliberately narrower than the sibling keys' coercing allowlist, because this key weakens a security control and its intent must be stated, not inferred from a string/integer shape; any non-bool throws a type-only `ConfigException`.

**Status mapping (deterministic, non-secret-bearing):** 204 on a durable decision; 400 malformed input; 401/403 from the middleware pipeline (auth/session/permission/CSRF) and controller gates (origin, identity, self-approval); 404 for a malformed-shape OR unknown id (byte-identical — not a store oracle; malformed shapes skip the store roundtrip); 409 for any not-pending state (already decided / expired / consumed — one body) including the `ApprovalAlreadyDecidedException` race, with a post-failure re-`find()` classifying a lost race apart from an outage; 503 for the store unavailable — same sanitized body whether the port is unbound or a bound store threw (the two are distinguished ONLY in the log by the container's exact "No binding registered for" sentinel, so a bound store's runtime failure is never misreported as "not bound"; `resolveOptional()` is deliberately avoided because it swallows `ApprovalStoreException`, a `RuntimeException`). Responses never carry exception detail or database messages; the store is resolved lazily per request so a deployment that never uses the write tier pays nothing at boot.

**Audit:** after a durable decision the controller dispatches `waaseyaa.mcp.approval_decision` (`McpApprovalDecisionRecorded`, safe join fields only) and `McpApprovalDecisionAuditListener` projects a best-effort `AuditEventKind::McpApprovalDecision` row; a projection failure never unwinds or misreports the decision. Coverage: `tests/Integration/PhaseN/Mcp/McpApprovalDecisionSurfaceTest.php` (full pipeline: Session → Csrf → FieldReadContext → Authorization → `ControllerDispatcher`), `packages/api/tests/Unit/Controller/McpApprovalControllerTest.php`, `packages/api/tests/Unit/ApiServiceProviderApprovalRoutesTest.php`.

### Descriptor metadata (advisory)

- `AgentTool::toMcpDescriptor()` now always emits the spec-standard `annotations.destructiveHint` (from `$destructive`), on every tier.
- On a **gated** endpoint, `tools/list` additionally marks each destructive tool's descriptor with the namespaced `_meta["ai.waaseyaa.mcp/approval"] = "required"` (`McpEndpoint::APPROVAL_LIST_META_KEY`). Non-destructive tools and ungated endpoints carry no such marker.
- Both are hints for agents; neither replaces enforcement.

### The `params._meta` envelope contract

`tools/call` accepts an optional `params._meta` (MCP request-metadata member). It is validated as envelope and consumed by the endpoint: a **present** `_meta` that is not a JSON object — including an explicit `"_meta": null` and a non-empty list; presence is checked with `array_key_exists`, so present-null differs from absent — or a non-string `_meta["waaseyaa/approval_request_id"]` (`McpEndpoint::APPROVAL_REQUEST_ID_META_KEY`), is an `invalid_params_refused` `-32602` recording only the offending *type*. `{}` (decoded empty array) is a valid empty envelope. `_meta` **never** reaches schema validation, `argumentsForAudit()`, or the tool — `arguments` is the only member a tool ever sees (pinned by a fixture whose schema sets `additionalProperties: false`).

### The gate flow (destructive tools, gate on)

After schema validation and redaction, the endpoint derives the `ApprovalTuple` from the **exact string account id** of the bearer principal, the ledger surface (`mcp.write`), the tool name, and the canonical fingerprint of the raw validated arguments. Then:

| Situation | Response | Durable record |
|---|---|---|
| No approval id supplied | `-32003` with `approval_request_id`, `expires_at` (ISO-8601 UTC), `correlation_id` | `store.open()` (reuses the pending request for an identical retry) + one `approval_required` terminal record carrying safe args, id, expiry |
| Id supplied, request pending, exact tuple match | same `-32003` (same id) | `approval_required` again |
| Id unknown / malformed shape, tuple mismatch, denied, expired, consumed | **one identical `-32004` body** — `Approval refused.` + `correlation_id` only | `approval_refused` with the axis + id operator-side |
| Id approved, exact tuple match | continue to reserve/consume below | — |

The `-32004` body is byte-identical across every axis (pinned by test), so the response is not an approval-state oracle; the axis (`unknown` / `tuple_mismatch` / `denied` / `expired` / `consumed` / `not_consumable`) lives only in the durable record's metadata. A malformed id shape is refused without a store roundtrip — the `apr_` + 32-hex shape is public in every challenge, so this reveals nothing.

### Ordering and joins: reserve → consume → execute → finalize

The existing fail-closed strict reserve happens first, with reservation metadata carrying `approval_request_id` and `approval_decided_by_uid`. Then the approval is **consumed atomically before execution** — `consume(requestId, receiptId, retryCorrelationId)` joins the approval to the executing reservation's receipt and the retry's correlation id; the storage-level `UNIQUE(request_id, event_type)` makes a second consumption impossible, so **a consumed approval is never reusable**. Finalization (success or failure) carries the same approval join metadata.

Failure semantics, each pinned by test:

- **Reserve fails** → the established `-31001` refusal; consume was never called, so the approval stays spendable by a later retry.
- **Consume returns false** (race lost / state changed since the gate's read) → the reservation is finalized `approval_refused` (a pair, never a dangling reservation, never a second single-record terminal), exactly one terminal projection fires, the caller gets the same `-32004` body, and the tool never runs.
- **Any `Throwable` from a store call** — before reserve (open/find) or during consume — fails closed: safe log metadata only (`mcp.approval_store_unavailable` / `mcp.approval_consume_failed`: exception class, correlation id, tool — never message or trace), a sanitized `-31002` (`Request refused: the approval store is unavailable.`) with the correlation id, and one honest `audit_unavailable_refused` record — a single terminal before reserve, a reservation finalization after it. No double terminals; the tool never runs; a consume-time failure leaves the approval unconsumed. The interface promises typed `ApprovalStoreException`s, but a nonconforming third-party adapter throwing anything else gets the same fail-closed treatment (pinned by test); each try wraps exactly one store call, so a tuple-construction failure is never misreported as a store outage. The stored-vs-computed `requestKey` match uses `hash_equals()` — the caller controls the computed key via arguments, so an early-exit comparison would be a timing side channel on approval identity.

Non-destructive tools, and destructive tools on an ungated endpoint, keep their exact pre-gate behaviour.

### Wiring and configuration

- `AuditServiceProvider` binds `OperationApprovalStoreInterface` → `DatabaseOperationApprovalStore`, ensuring `ApprovalEventSchema` **lazily on first resolution** (a deployment that never uses the write tier pays nothing at boot). TTL from `mcp.write_tier.approval.ttl_seconds` — a strict positive integer (integer-shaped strings accepted), default `DatabaseOperationApprovalStore::DEFAULT_TTL_SECONDS` (900); anything else is a `ConfigException` naming the key and the value's type only.
- `McpServiceProvider`: `mcp.write_tier.approval.enabled` defaults **true** (same strict boolean allowlist as its siblings). Enabled-but-unwireable (no store bound) throws at setup; enabled while `mcp.write_tier.durable_audit` is off is a contradiction (consume joins strict-ledger receipts) and is refused rather than silently resolved — turning durable audit off now requires stating `approval.enabled: false` too. The **public tier never gets the gate**.
- `McpEndpoint` enforces the same contract independent of provider wiring: `approvalGate: true` without a store, or without `durableAudit`, throws `LogicException` at construction.

### Coverage (approval gate)

| Test | Level |
|---|---|
| `packages/mcp/tests/Integration/Approval/McpApprovalGateLifecycleTest.php` | End-to-end over real SQLite store + ledger: challenge / pending-reuse / approve / consume / execute / row joins, no-oracle refusal axes, replay, race, store failure, reserve failure, ordering, `_meta` stripping, `tools/list` markers, no secrets in any table |
| `packages/mcp/tests/Unit/McpEndpointApprovalGateContractTest.php` | Constructor guards |
| `packages/mcp/tests/Unit/McpServiceProviderTest.php` | Provider defaults, fail-closed wiring, explicit off, public-tier invariant |
| `packages/audit/tests/Unit/AuditServiceProviderApprovalStoreTest.php` | Store binding, lazy schema, TTL config |
| `packages/ai-tools/tests/Unit/AgentToolDescriptorTest.php` | `annotations.destructiveHint` |

### Compatibility

- **Event cadence changed.** `McpDispatchEvent` fires once per *stage*, not once per request. A listener counting events per request sees more; one reading `stage` gets the truth.
- **`params` is no longer populated.** The property remains for source compatibility. A SHA-256 of raw params could not be correlated, reversed, or acted on; `safeArguments` replaces it. A stage-less (legacy) event still receives the old `params_hash` attribute, so an out-of-tree dispatcher is not silently downgraded.
- **401 is now audited.** This reverses the former "clause 16" rule that 401s fire nothing — that silence was the defect.
- `waaseyaa/mcp` still does **not** require `waaseyaa/audit` at runtime. The port lives in foundation precisely to preserve that.

## JSON-RPC Protocol

All communication uses JSON-RPC 2.0 over HTTP.

### Supported Methods

| Method | Description |
|--------|-------------|
| `initialize` | Returns protocol version, capabilities, server info |
| `ping` | Health check, returns empty result |
| `tools/list` | Returns all registered tool definitions |
| `tools/call` | Executes a tool by name with arguments |

### First-party tool contract (`ai_discover`, `editorial_*`, …) — REMOVED (WP17)

The 12 first-party tools (`search_entities`/`search_teachings`/`ai_discover`, `get_entity`/`list_entity_types`, `traverse_relationships`/`get_related_entities`/`get_knowledge_graph`, `editorial_*`) and the MCP read-path cache were implemented **only** by the legacy `McpController` stack removed in WP17 (see the retraction note above) and are no longer served. The live `/mcp` endpoint exposes the auto-discovered `#[AsAgentTool]` catalogue — per-entity CRUD tools (`create_*`, `get_*`, `update_*`, `delete_*`, `list_*`) routed through `AgentExecutor::executeTool()` (see **Tool Registry** and **Bridge Adapters** above) — whose request/response/error shapes are documented below.

### Request Format

```json
{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
        "name": "create_node",
        "arguments": {"title": "Hello", "body": "World"}
    },
    "id": 1
}
```

### Success Response

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "content": [{"type": "text", "text": "{\"id\": 42}"}]
    }
}
```

### Error Response

```json
{
    "jsonrpc": "2.0",
    "error": {"code": -32601, "message": "Method not found: resources/list"},
    "id": 1
}
```

### Error Codes

`Waaseyaa\Mcp\McpErrorCode` is the single allocation point, and states the
policy MCP 2026-07-28 binds this server to (#2561): `-32020..-32099` is
reserved for the MCP specification and an implementation MUST NOT emit an
undefined code from it; `-32002` and `-32042` are retired and MUST NOT be
emitted at all; new codes for purposes the specification does not define SHOULD
sit outside `-32768..-32000`.

**JSON-RPC standard and MCP-defined** — used with their specified meanings:

| Code | Meaning |
|------|---------|
| `-32700` | Parse error (invalid JSON) |
| `-32600` | Invalid request (missing `method` field) |
| `-32601` | Method not found |
| `-32602` | Invalid params (malformed modern metadata or tool-call envelope), and `resources/read` not-found in every era |
| `-32020` | Modern HTTP header/body mismatch (MCP-defined) |
| `-32022` | Unsupported protocol version (MCP-defined) |

**This server's own**, outside the JSON-RPC reserved range. Each keeps the last
two digits of the code it replaced, so an old log line is still findable:

| Code | Constant | Meaning | Was |
|------|----------|---------|-----|
| `-31040` | `FORBIDDEN_ORIGIN` | Origin neither same-origin nor configured | `-32040` |
| `-31041` | `UNACCEPTABLE_ACCEPT` | `Accept` lacks a required media type | `-32041` |
| `-31042` | `UNSUPPORTED_CONTENT_TYPE` | `Content-Type` is not `application/json` | `-32042` |
| `-31043` | `REQUEST_TOO_LARGE` | Body over the transport size cap | `-32043` |
| `-31029` | `RATE_LIMIT_EXCEEDED` | Request budget exhausted (`data.retry_after_seconds`, mirrored onto a `Retry-After` HTTP header) | `-32029` |
| `-31030` | `RATE_LIMITER_UNAVAILABLE` | No durable limiter decision; fails closed (no `Retry-After` -- retry semantics undefined for this refusal) | `-32030` |
| `-31001` | `AUDIT_TRAIL_UNAVAILABLE` | Audit reservation not durable; refused pre-execution | `-32002` |
| `-31002` | `APPROVAL_STORE_UNAVAILABLE` | Approval store unreachable; refused | `-32002` |

The last two were one code. `-32002` meant resource-not-found on
`resources/read` **and** an infrastructure outage on `tools/call`, separable
only by reading the message, so a client that mapped it to resource-not-found
rendered an audit outage as a missing resource and retried a different URI
instead of backing off. They are now three distinct codes.

**Legacy sub-range, retained.** `-32001` Unauthorized, `-32003` Approval
required and `-32004` Approval refused sit in `-32000..-32019`, where the
specification says SHOULD NOT rather than MUST NOT. All three are wire
contracts clients already implement — `-32003`/`-32004` are the two halves of
the write-tier approval handshake — so renumbering them is a separate decision,
not a rider on the conformance fix. They are recorded with rationale in
`McpErrorCode::LEGACY_IN_USE`; a *new* legacy allocation still fails.

Enforcement: `packages/mcp/tests/Architecture/McpErrorCodeAllocationTest.php`
tokenizes `packages/mcp/src` and fails on any integer literal in a forbidden
band, so allocation drift is caught in CI rather than on a consumer's wire.
Migration table: `docs/upgrade-notes/mcp-error-code-allocation.md`.

### Transport

The endpoint implements a dual-era stateless JSON-response profile of
Streamable HTTP: current per-request MCP `2026-07-28`, plus legacy lifecycle
compatibility for `2025-11-25`, `2025-06-18`, and `2025-03-26`:

- POST carries exactly one JSON-RPC request, notification, or response.
- POST requires `Content-Type: application/json` and `Accept` listing both
  `application/json` and `text/event-stream`.
- Notifications and client response messages return HTTP 202 with no body.
- GET with `Accept: text/event-stream` returns 405: SSE, server-initiated
  requests, sessions, and resumability are not implemented or advertised.
- A present Origin is validated against the request origin plus the explicit
  `mcp.transport.allowed_origins` list; invalid origins return 403. Native
  clients may omit Origin.
- Legacy clients may send a supported `MCP-Protocol-Version`; absence retains
  the `2025-03-26` fallback. Modern clients declare `2026-07-28` in body
  metadata and mirror the version, method, and applicable name in HTTP headers.

## Routes

`McpRouteProvider` registers one always-present write route, a public pair when
`mcp.public.enabled` is enabled, and an RFC 9728 protected-resource metadata
route when OAuth metadata is configured.

| Route Name | Path | Methods | Registered when | Auth |
|------------|------|---------|-----------------|------|
| `mcp.endpoint` | `/mcp` | POST, GET | `mcp.public.enabled` (default true) | `McpAuthInterface` — anonymous by default |
| `mcp.server_card` | `/.well-known/mcp.json` | GET | `mcp.public.enabled` (default true) | Public (`allowAll()`) |
| `mcp.endpoint.write` | `/mcp/write` | POST, GET | Always | `WriteTierAuthInterface` — 401 without an application binding |
| `mcp.oauth_protected_resource` | Configured metadata path | GET | OAuth protected-resource metadata configured | Public discovery metadata |

With `mcp.public.enabled = false`, `/mcp` and `/.well-known/mcp.json` are absent from the route collection and resolve to HTTP 404.

When the deployment also enables the RFC 9727 API Catalog, the MCP provider
contributes exactly one API item: the public `/mcp` endpoint, with
`/.well-known/mcp.json` as `service-meta`. The contribution is empty when
`mcp.public.enabled` is false. The authenticated `/mcp/write` tier, its OAuth
protected-resource metadata, approval routes, and admin introspection are never
advertised by this contribution.

When the deployment explicitly enables the experimental AI Catalog, MCP also
contributes one `mcp:public` artifact pointing to the same compatibility card.
Its type is honestly `application/json`: the Waaseyaa compatibility card is not
mislabelled as the separate draft MCP Server Card format. The contribution has
only generic `ContentDiscovery` and `ReadOnlyTools` capabilities; deployment
representative queries belong to application config. Disabling the anonymous
MCP tier removes both its RFC 9727 and AI Catalog contributions. The write tier,
OAuth metadata, approvals, tokens, tools, and admin routes are never included.

### Server Card

`McpServerCard` generates the Waaseyaa compatibility response at
`/.well-known/mcp.json`. It is not an official Registry `server.json` and never
contains Registry fields. The route controller is `McpServerCard::serve()`,
which returns an `HttpResponse` wrapping the `toJson()` output:
With the opt-in resource surface enabled and a provider installed, its
`capabilities.resources` member is `true`; otherwise it is `false`.

```json
{
    "name": "Waaseyaa",
    "version": "0.1.0-alpha.286",
    "description": "AI-native content management system",
    "endpoint": "/mcp",
    "transport": "streamable-http",
    "protocolVersions": ["2026-07-28", "2025-11-25", "2025-06-18", "2025-03-26"],
    "transportCapabilities": {
        "jsonResponse": true,
        "sse": false,
        "sessions": false,
        "resumability": false
    },
    "capabilities": {
        "tools": true,
        "resources": true,
        "prompts": false
    },
    "authentication": {
        "type": "none"
    }
}
```

Name and version come from the same `McpImplementationInfo` instance used by
legacy `initialize` and modern server metadata. `mcp.implementation.name` and
`mcp.implementation.version` are strict explicit overrides. Without a version
override, a framework checkout reads its release-managed `VERSION`; an
installed application reports Composer's installed `waaseyaa/mcp` version.
The version is implementation provenance, not a protocol-compatibility signal.

### Official Registry `server.json`

`McpRegistryManifest` is a separate, non-routed publication artifact pinned to
`https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json`.
It emits only the official remote-server shape: namespaced `name`, title,
bounded description, the shared implementation version, optional repository
and website metadata, and `remotes: [{type: "streamable-http", url: ...}]`.

The deployment must explicitly configure `mcp.registry.name`, `description`,
and `remote_url`. The remote must be an absolute public HTTPS URL; the framework
does not derive it from the request Host, invent a default deployment, or
publish a Composer package as an unsupported Registry package type. Official
submission remains blocked until the URL is publicly reachable, namespace
ownership is authenticated, the framework release exists, and the preview
schema is revalidated at submission time. A framework-neutral manifest service
is available to applications, but nothing exposes its output: the emitter is
container-bound with no route and no command, which #2638 tracks. The layer
obstacle that blocked the adapter is gone — #2207 landed
`Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface`,
which `PackageManifestCompiler` detects by string constant precisely so a
provider can contribute commands without importing the Layer 6 CLI package.

## MCP Feature Scope

| Feature | MVP | Future |
|---------|-----|--------|
| `tools/list` | Yes | -- |
| `tools/call` | Yes | -- |
| `resources/list` | Yes, bounded and opt-in | Opaque AEAD `nextCursor` when application-master keyring is composed (#2636) |
| `resources/templates/list` | Yes, opt-in | -- |
| `resources/read` | Yes, access-checked and opt-in | -- |
| `prompts/list` | No | Deferred; not advertised |
| Server card | Yes | Evolves with spec |
| SSE streaming | No, honestly returns 405 | Optional future profile |
| Session management | No, not advertised | Optional future profile |

## File Reference

```
packages/mcp/
  src/
    McpEndpoint.php
    AuthenticatedMcpEndpoint.php
    McpResponse.php
    McpRouteProvider.php
    McpServiceProvider.php
    McpServerCard.php
    McpServerCardConfig.php
    ReadOnlyToolRegistry.php
    CapabilityScopedToolRegistry.php
    Admin/
      RecentInvocationsQueryInterface.php
      ServerConfigReadModel.php
      ToolRegistryReadModel.php
    Auth/
      McpAuthInterface.php
      BearerTokenAuth.php
      PublicAnonymousAuth.php
      WriteTierAuthInterface.php
    Bridge/
      AgentToolRegistryBridge.php
    Event/
      McpDispatchEvent.php
  composer.json
```
<!-- The legacy McpController.php + Tools/ + Rpc/ + Cache/ files were removed in WP17 (#1738). -->


<!-- Last reviewed: 2026-03-30 — test file reorganization only, no spec changes needed -->

<!-- Spec reviewed 2026-05-17 - dead-code baseline reduction (#1493 / PR TBD): @api PHPDoc sweep on extension-point classes + WaaseyaaEntrypointProvider extended to recognize EntityBase/ContentEntityBase subclasses and their traits. No behavioural change. -->

<!-- Spec reviewed 2026-05-17 - dead-code Phase 3 Bucket 4: @api PHPDoc sweep on additional public-API classes. No behavioural change. -->

<!-- Spec reviewed 2026-05-18 - WP07 (agent-executor mission) rebase + rewire: no behavioural change to this subsystem; touch refreshes drift-detector timestamp. -->

<!-- Spec reviewed 2026-05-20 - M-G (bimaaji-mcp-strategic-direction-01KS3SZB) WP06: decision published — bimaaji stays PHP-only; #1463 closed as not-planned. Bimaaji positioning section added below. -->

## Bimaaji MCP positioning (2026-05-20)

> **Superseded 2026-05-23 by mission `bimaaji-mcp-bridge-01KS5VS8`.** The
> 2026-05-20 PHP-only deferral was correct for the inherited broken Node
> scaffolding but is no longer the framework's posture. See the new
> "Bimaaji MCP bridge" section below for the active doctrine. This
> section is preserved as the audit trail of the M-G decision and its
> reversal (C-005).

`packages/bimaaji/` ships PHP-only. Bimaaji's graph-introspection surface is intentionally NOT exposed via an MCP server in the current alpha range.

If a consumer extends Bimaaji-via-MCP, it registers an `#[AsAgentTool]`
implementation through the canonical `Waaseyaa\AI\Tools` registry. No Node
sidecar and no MCP-local registry contract are involved.

The prior Node-based MCP server attempt (April 2026, removed in commit `46f4c41af`) failed at Packagist's non-PHP-artifact distribution boundary; do not restore that approach.

Decision artifacts: `kitty-specs/archive/bimaaji-mcp-strategic-direction-01KS3SZB/decision.md`.

## Bimaaji MCP bridge

Active doctrine (M3 `bimaaji-mcp-bridge-01KS5VS8`, shipped 2026-05-23).
Exposes bimaaji over MCP via `packages/mcp/`. Reverses the
2026-05-20 M-G "PHP-only" deferral above.

### Architecture

External MCP clients (Claude Code, Cursor, Claude Desktop, etc.)
authenticate against `/mcp` over Streamable HTTP (the MCP-side
transport this project already ships — note this differs from the M3
spec's original "stdio only" assumption; HTTP is the canonical
delivery). `McpEndpoint::handle()`:

1. Calls `McpAuthInterface::authenticate($authorizationHeader)` to
   resolve an `AccountInterface` from the Authorization bearer token.
2. Constructs a per-request `AgentToolRegistryBridge` with the raw
   framework-wide `Waaseyaa\AI\Tools\ToolRegistryInterface` plus the
   auth-resolved account.
3. Dispatches the JSON-RPC payload (`tools/list` / `tools/call` /
   `initialize` / `ping`) against the bridge.

The bridge forwards the auth-resolved account into every
`AgentToolInterface::execute()` call. Each `#[AsAgentTool]` tool runs
`AbstractAgentTool::requireCapability($capability, $account)` first
and short-circuits with the `forbidden` envelope if the account lacks
the required permission. There is no separate `SessionCapabilities`
class — account permissions ARE the capability gate.

### Bimaaji tool inventory (shipped)

The bimaaji surface over MCP is five `#[AsAgentTool]` adapters living
in `packages/ai-agent/src/Tool/Bimaaji/`. The bridge wraps them
automatically; no per-tool MCP code exists.

| Tool name | Capability | Delegates to |
|---|---|---|
| `bimaaji_introspect_graph` | `bimaaji.read` | `ApplicationGraphGenerator::generate()->toArray()` (full graph: 6 sections + version) |
| `bimaaji_introspect_section` | `bimaaji.read` | `ApplicationGraphGenerator::generate()->getSection($key)` — `$key` ∈ {admin, entities, jsonapi, public_surface, routing, sovereignty} |
| `bimaaji_propose_mutation` | `bimaaji.mutate` | `MutationValidator::validate()` — returns `MutationResult::toArray()` |
| `bimaaji_generate_patch` | `bimaaji.mutate` | `PatchGenerator::generate()` — returns `PatchSet::toArray()`, never writes to disk |
| `bimaaji_search_specs` | `bimaaji.read` | `SpecIndexProvider` + substring search over `docs/specs/*.md` |

The original M3 plan AD-02 inventoried eight read tools
(`application_info`, `list_*`, `sovereignty_profile`, `public_surface`,
`search_specs`); the WP01 audit collapsed six of those to
`bimaaji_introspect_section` (already parameterised by the same six
section keys) and merged `application_info` into
`bimaaji_introspect_graph` (full-graph entry point). Only
`bimaaji_search_specs` was genuinely new ai-agent work.

### Capability model

- **Default capabilities** come from the integrating application's
  session/role/policy stack — `$account->hasPermission($cap)` is the
  source of truth.
- **Read access** (`bimaaji.read`) is intended to be broadly granted
  to authenticated accounts that operate MCP clients.
- **Mutation access** (`bimaaji.mutate`) is opt-in per role/account;
  the framework does not grant it by default.

The M3 plan's env-var-driven `WAASEYAA_MCP_CAPABILITIES` mechanism was
dropped during re-scope — adding a parallel capability source would
create two competing decision points. The integrating app's
permission model owns the answer.

### Disk-write invariant (SC-003, C-003)

`bimaaji_generate_patch` returns a `PatchSet` value object — content,
diff text, target path, all in memory. The MCP server **never** writes
to disk. The calling MCP client is responsible for any persistence
(`fs/write_text_file` on the client side, a human-reviewed PR, etc.).
This is asserted by
`packages/ai-agent/tests/Contract/Bimaaji/GeneratePatchToolTest::doesNotWriteToFilesystem`.

### Tool-name convention (NFR-005)

All bimaaji-surfaced tools use the `bimaaji_` prefix. The
framework-wide `AttributeToolRegistry` enforces name uniqueness
(first-registered wins; `if (!isset($this->tools[$tool->name]))`
guards the hydration loop). New bimaaji-adjacent tools MUST extend
the prefix.

### M-G → M3 transition rationale

The 2026-05-20 M-G "PHP-only" deferral was tied to the inherited
broken Node scaffolding (April 2026, removed in commit
`46f4c41af`), not to a "no external transport" principle. Boost's
shipped success and the M-G mission's own "Option 2" (extend
`packages/mcp/`) framed PHP-hosted MCP as the right path. M3
implements that path. #1463 is the audit trail and remains closed.

### File reference (post-WP01..WP03)

```
packages/mcp/
  src/
    McpEndpoint.php           — JSON-RPC dispatcher; per-request bridge
    McpResponse.php           — value object
    McpRouteProvider.php      — registers /mcp + /.well-known/mcp.json
    McpServerCard.php         — server-card route
    McpServiceProvider.php    — binds McpAuthInterface default
    Auth/
      McpAuthInterface.php    — auth contract
      BearerTokenAuth.php     — default (empty-token) impl
    Bridge/
      AgentToolRegistryBridge.php   — per-request bridge

packages/ai-agent/src/Tool/Bimaaji/
  IntrospectGraphTool.php
  IntrospectSectionTool.php
  ProposeMutationTool.php
  GeneratePatchTool.php
  SearchSpecsTool.php

tests/Integration/PhaseN/Mcp/
  BimaajiMcpBootSmokeTest.php    — SC-004 reflection pins (WP01)
  BimaajiMcpReadTest.php         — closed-loop semantics (WP02+WP03)
  BimaajiMcpCapabilityTest.php   — capability gating (WP03)
```

The legacy `McpController` + `Tools/*` + `Cache/` + `Rpc/*` files were
**removed in WP17** ([#1738](https://github.com/waaseyaa/framework/pull/1738),
closing #1642) — see the "Legacy `McpController` stack — REMOVED" note earlier
in this spec. `/mcp` is served by `McpEndpoint` over the `Bridge\` + ai-tools
`#[AsAgentTool]` registry.

## Serializer redaction shape (M-A5, FR-006, C-003) — marker shape NOT IMPLEMENTED (field access IS enforced)

> **Settled (WP18, probed against live code).** **The flagship `/mcp` surface does
> not leak field values a caller may not see.** Field-level access *is* enforced:
> `EntityReadTool` (the live `entity.read` tool the `/mcp` bridge dispatches to)
> drops every field a `FieldAccessPolicyInterface` forbids via
> `EntityAccessHandler::filterFields($entity, …, 'view', $account)`
> (`applyFieldAccessFilter()` → `array_intersect_key`), after first dropping
> credential keys and `internal` fields. Enforcement is **fail-closed**:
> `AttributeToolRegistry` (the registry the live endpoint uses) stamps
> `markAccessEnforced()` on **every** hydrated tool, so even if the kernel handler
> transiently resolves to null the read is **denied** rather than served unfiltered.
> Proven by `EntityReadToolFieldFilterTest::never_leaks_field_access_forbidden_fields`
> (a forbidden `secret_note` value is absent from the `entity.read` result).
> Independently, a WP4 `FieldReadDenied` on a view-authorized entity is
> omitted by `EntityFieldRedaction::toReadableCastAwareMap()` — the same
> per-field catch JSON:API uses — so Protected fields such as `node.status`
> cannot turn an anonymous published-page read into `INTERNAL_ERROR`.
>
> What was **never built** is only the documented *marker shape*:
> `McpEntityFieldFilter` (`packages/mcp/src/Serializer/McpEntityFieldFilter.php`),
> the `{ "accessRestricted": true, "reason": … }` substitution, the
> `EntityTools::setFieldFilter()` wiring, and the `McpJsonApiFieldParityTest`
> guard are all **absent** (the filter file predated WP17 and was already gone;
> its only wiring was the now-removed `McpController`/`EntityTools` stack). So
> there is **no MCP-vs-JSON:API asymmetry**: `/mcp` *omits* a forbidden field
> exactly as JSON:API does, rather than substituting the marker. FR-006/FR-007/C-003
> as written (the asymmetric, audit-lineage marker contract) are therefore
> **aspirational, not guaranteed** — but this is a **cosmetic/audit-lineage gap,
> not a data leak.** Re-establishing the marker is **post-beta polish**, not a
> security fix.

### See also

- Mission: `kitty-specs/bimaaji-mcp-bridge-01KS5VS8/`
- Mission (M-A5): `kitty-specs/per-record-ai-access-flagship-01KSEFT5/`
- SC-004 anchor: `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md`
- Package README: `packages/mcp/README.md`
- Bimaaji spec (MCP exposure subsection): `docs/specs/bimaaji.md`
- Field access spec: `docs/specs/field-access.md`

## Admin surface

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` (#1415) — read-only admin UI for the MCP endpoint.

The admin SPA exposes three pages under `/mcp/`:

| Page | Route | Composable | Backend endpoint |
|------|-------|------------|-----------------|
| Tool registry browser | `/mcp/tools` | `useMcpTools` | `GET /api/mcp/tools` |
| Per-tool detail | `/mcp/tools/{name}` | `useMcpTool` | `GET /api/mcp/tools/{name}` |
| Server config | `/mcp/server-config` | `useMcpServerConfig` | `GET /api/mcp/server-config` |

### Tool registry browser (`/mcp/tools`)

Lists all tools registered in the MCP tool registry. Columns: name (linked to detail), category, required capabilities (chip badges), summary. Empty and loading states handled.

### Per-tool detail (`/mcp/tools/{name}`)

Header card shows: name, category, capability chips, summary, description. Below: collapsible input-schema viewer (JSON Schema tree using `<details>` per property node) and a recent-invocations table. Each invocation row links to `/ai/observability/runs/{traceUuid}` when the M5B page exists; falls back to plain text UUID otherwise. A "Server config →" link navigates to the config page.

Tool names are URL-encoded once via `encodeURIComponent()` before the API request to handle names containing dots (e.g. `bimaaji.search_specs`).

### Server config (`/mcp/server-config`)

Displays: transport (`streamable-http` | `sse`) and protocol version in a banner; server capabilities as chip badges; registered clients table (client ID, token fingerprint, last-seen timestamp).

**Security invariant:** The `McpRegisteredClient` TypeScript type does not include a `token` field — only `tokenFingerprint` (16-char hex). This is enforced by a compile-time type assertion in `useMcpServerConfig.test.ts`.

### Files

```
packages/admin/app/composables/useMcpTools.ts
packages/admin/app/composables/useMcpTool.ts
packages/admin/app/composables/useMcpServerConfig.ts
packages/admin/app/components/mcp/ToolRegistryTable.vue
packages/admin/app/components/mcp/InputSchemaViewer.vue
packages/admin/app/components/mcp/RecentInvocationsTable.vue
packages/admin/app/pages/mcp/tools/index.vue
packages/admin/app/pages/mcp/tools/[name].vue
packages/admin/app/pages/mcp/server-config.vue
packages/admin/tests/unit/composables/useMcpTools.test.ts
packages/admin/tests/unit/composables/useMcpTool.test.ts
packages/admin/tests/unit/composables/useMcpServerConfig.test.ts
packages/admin/e2e/mcp-admin.spec.ts
```

## Authenticated write tier (Wayfinding Phase 5)

The public `/mcp` endpoint is read-only: `PublicAnonymousAuth` resolves every
request to an anonymous account, and `ReadOnlyToolRegistry` hides every
`destructive: true` tool (the alpha.221 trio, constraint **C-001**). Wayfinding
Phase 5 adds a **separate authenticated write tier** for write tools, without
touching that surface (**FR-004**).

### Route + controller

`McpRouteProvider` registers a second route `POST /mcp/write` →
`AuthenticatedMcpEndpoint::serve`. Like `/mcp` it is `allowAll()` + `csrfExempt()`
at the HTTP layer; authentication is enforced *inside* the endpoint (fail-closed),
not by the router. `AuthenticatedMcpEndpoint` is a thin, routable controller that
composes an inner `McpEndpoint` — a route controller resolves to a single
container binding, so a second differently-wired endpoint needs its own class.
All JSON-RPC dispatch (auth, per-request bridge, capability gating, the
`waaseyaa.mcp.dispatch` event) is the unchanged inner `McpEndpoint`.

### Auth: `WriteTierAuthInterface`

`WriteTierAuthInterface` (a marker extending `McpAuthInterface`) is the
**application override point** for write-tier credentials. An app binds it in its
OWN service provider to a `BearerTokenAuth` mapping `token → AccountInterface`
(accounts holding the write capability); token→account mapping is
application-specific. The marker keeps the write-tier credential binding
**distinct** from the public `McpAuthInterface` (=`PublicAnonymousAuth`) binding,
so the two surfaces configure independently and the public read tier is never
affected.

**Resolution precedence (alpha.234, mission `wayfinding-stress-remediation-01KVGK4Q`).**
`McpServiceProvider` deliberately does **not** bind a package default for
`WriteTierAuthInterface`. Instead, the `AuthenticatedMcpEndpoint` binding resolves
it per-request via `resolveWriteTierAuth()`, which goes through the **cross-provider
kernel-services bus** (`resolveOptional`) and falls back to a fail-closed
`BearerTokenAuth([])` (empty token map → HTTP 401) only when no provider supplies
one. This is the fix for the alpha.233 stress-test blocker: when the package bound
its own default, `ServiceProvider::resolve()`'s own-bindings-first lookup made that
default **shadow** an app override, so `/mcp/write` always 401'd regardless of what
the app bound. Removing the package binding makes the app's binding the one the bus
returns. The blast radius is confined to the write-tier auth path — no global
binding-precedence change (the public read tier and every other binding are
untouched). When no app binds it, the behaviour is unchanged: every `/mcp/write`
request fails closed with HTTP 401.

**Durable default (#2177 F3).** With no app override, `resolveWriteTierAuth()`
now prefers the durable path before the empty map: when the kernel-services bus
supplies `Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface` (bound by
`AuthServiceProvider` over the kernel database), the `user` entity repository
(via `EntityTypeManagerInterface`), and the audited
`AccountPrincipalFactoryInterface` (bound by `AuditServiceProvider`), the tier
authenticates through `DurableBearerTokenAuth`: hashed-at-rest, expiring,
revocable, rotatable credentials with audience `mcp:write` and explicit
per-token scopes, issued via the `bearer-token:*` operator commands. A fresh
deployment has no tokens, so the observable posture is identical (every request
401s) — but production becomes usable without application auth code. The owner
resolves by an ACTIVE-owner query (`uid` + `status = 1`, mirroring
`findActiveByLogin` — a direct Protected `status` read pre-auth has no field
read context) and is snapshotted into a decision principal whose `id()` is the
owner uid, preserving the F1 separation-of-duties comparison. Token scopes are
enforced by the endpoint as a per-request `CapabilityScopedToolRegistry`
intersection: scopes narrow the tier surface and never broaden account
capabilities; a scopeless credential exposes nothing.

### Registry: `CapabilityScopedToolRegistry`

The structural dual of `ReadOnlyToolRegistry`. Given a capability allowlist, it
exposes only tools whose `capability` is listed — **destructive included** — and
hides everything else (`get`/`has` behave as "unregistered", `all`/`tools/list`
omit them). The write tier is wired with the allowlist from
`mcp.write_tier.capabilities` (default `['present guided content']`), so it
surfaces exactly the Wayfinding write tools and **not** the whole destructive
catalogue (e.g. editorial write tools, whose capability is not on the allowlist,
stay absent). Per-tool `AbstractAgentTool::requireCapability` is still the
authorization layer (NFR-002): a token-authenticated caller lacking the
capability gets a `forbidden` envelope.

### Three-layer guarantee

| Layer | Public `/mcp` (read-only) | `/mcp/write` (authenticated write tier) |
|---|---|---|
| Auth | `PublicAnonymousAuth` → anonymous, never 401 | `WriteTierAuthInterface`=`BearerTokenAuth` → 401 without a valid token |
| Registry | `ReadOnlyToolRegistry` (hides all destructive) | `CapabilityScopedToolRegistry` (allowlisted capabilities, destructive incl.) |
| Per-tool | `requireCapability` vs anonymous read caps | `requireCapability` vs the bearer-resolved account |

The wayfinding write tools (`wayfinding_record_trail`, `wayfinding_rerecord_trail`,
`wayfinding_get_trail`, `wayfinding_emit_beacon`) are `#[AsAgentTool]` adapters in
`packages/ai-agent/src/Tool/Wayfinding/` — see `ai-integration.md` and
`wayfinding.md`. Acceptance: `AuthenticatedMcpEndpointTest` +
`CapabilityScopedToolRegistryTest` (mcp), and the tool tests (ai-agent).

### Files (Phase 5)

```
packages/mcp/src/AuthenticatedMcpEndpoint.php
packages/mcp/src/CapabilityScopedToolRegistry.php
packages/mcp/src/Auth/WriteTierAuthInterface.php
packages/mcp/src/McpRouteProvider.php          (adds /mcp/write)
packages/mcp/src/McpServiceProvider.php         (write-tier wiring; resolveWriteTierAuth app-override seam)
packages/mcp/src/Auth/BearerTokenAuth.php       (implements WriteTierAuthInterface)
```
