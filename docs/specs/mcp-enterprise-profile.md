# Enterprise MCP profile

This profile defines what "enterprise-grade MCP" means for Waaseyaa. It is a
verifiable framework contract, not a claim that every optional MCP primitive
must be enabled. A deployment may expose only tools, but every capability it
advertises must be complete, secure, interoperable, and operationally owned.

The protocol baseline is MCP 2026-07-28 with an explicit dual-era compatibility
path for legacy lifecycle clients. The official MCP specification remains
authoritative when this profile and the protocol disagree.

## Protocol and transport

- Support current `server/discover`, `tools/list`, and `tools/call` with
  per-request protocol metadata and strict HTTP mirror validation. Preserve the
  legacy `initialize`, `notifications/initialized`, `ping`, `tools/list`, and
  `tools/call` lifecycle. Informational implementation identity may change when
  correcting provenance; protocol negotiation and tool-result bytes remain
  backward compatible.
- Classify era only from body metadata. Reject header/body mismatches and
  unsupported revisions with their current protocol error and HTTP contracts.
- Treat discovery and tool catalogues as principal-varying: advertise private,
  zero-TTL caching and emit `Cache-Control: no-store`.
- Implement Streamable HTTP media negotiation and Origin validation. A
  stateless JSON-response deployment must decline SSE, sessions, termination,
  and resumability honestly rather than advertising unimplemented behavior.
- Bound request bodies before authentication and JSON decoding. Malformed size
  declarations and oversized actual bodies fail closed.
- Do not advertise resources, prompts, sampling, elicitation, logging, or
  experimental tasks until their complete lifecycle and security contracts are
  implemented. These primitives are optional for the editorial profile.

Evidence: `StreamableHttpTransportGuard`, `McpProtocol`, `McpEndpoint`,
`StreamableHttpTransportGuardTest`, and `McpEndpointTest`.

## Discovery and Registry identity

- Project one injected implementation name/version through legacy initialize,
  current server metadata, the compatibility card, and official Registry
  metadata. Never maintain independent hardcoded identity values.
- Treat `/.well-known/mcp.json` as the Waaseyaa compatibility card, not as an
  official `server.json` or an unstandardized server-card media type.
- Build official Registry metadata as a separate deployment-owned artifact,
  pinned to a reviewed schema revision. Require a namespaced id and an explicit
  public HTTPS remote; never derive canonical URLs from request Host headers.
- Do not claim Registry publication until the remote is publicly reachable,
  namespace ownership is authenticated, and the preview schema is revalidated.
  Composer is not an official MCP Registry package type.

Evidence: `McpImplementationInfo`, `McpServerCard`, `McpRegistryManifest`, and
their provider, endpoint, card, and Registry contract tests.

## Authentication and authorization

- Keep anonymous discovery optional and structurally read-only. A disabled
  public tier withdraws both endpoint and card.
- Offer durable opaque credentials for local/operator automation: hashed at
  rest, shown once, expiring, revocable, rotatable, audience-bound, scoped, and
  owned by an active real account.
- Offer standard OAuth resource-server integration for general MCP clients:
  RFC 9728 protected-resource metadata, `WWW-Authenticate` discovery and scope
  guidance, secure absolute resource identifiers, and a validator port for an
  OAuth 2.1 authorization server or enterprise IdP.
- OAuth validators must verify issuer and integrity or introspection, expiry,
  revocation, exact resource audience, active-account mapping, and granted
  scopes. Incoming access tokens are never passed through to downstream APIs.
- Token scopes narrow the tier registry and never grant an account capability.
  Every tool also performs its normal account and entity access checks.

Evidence: `DurableBearerTokenAuth`, `OAuthMcpAuth`,
`OAuthProtectedResourceMetadata`, `CapabilityScopedToolRegistry`, bearer and
OAuth auth tests, and the write-tier override integration tests.

## Tool contracts

- Tool names are unique and deterministic. Remotely reachable write tools are
  explicitly curated; generic cross-bundle entity mutations are withheld by
  default.
- Every call is validated against the exact advertised input schema before the
  handler executes.
- Tools may advertise output schemas only when successful calls return
  matching `structuredContent`. The server validates its own structured output
  before returning it. JSON text remains alongside structured data for older
  clients.
- Descriptors provide a human title and the standard read-only, destructive,
  idempotent, and open-world hints. Hints never replace server enforcement.
- Unknown tools, validation failures, domain failures, and infrastructure
  failures have stable, sanitized machine-readable behavior.

Evidence: `AgentTool`, `AgentToolResult`, `AgentToolRegistryBridge`,
`ToolInputSchemaValidator`, descriptor/result/bridge tests, and
`ContentToolSetTest`.

## Editorial mutation safety

- Canonical remote editing is bundle-scoped and draft-first.
- Writable fields are allowlisted and typed. HTML is sanitized; dates,
  references, cardinality, and application validators are enforced.
- Updates use optimistic revision tokens. Mutations use bundle-namespaced
  idempotency keys. Rollback creates a new revision and preserves history.
- Publish and unpublish traverse the configured editorial workflow with the
  authenticated actor and current working copy.
- Draft previews are short-lived, signed, non-public, and rendered by the
  application. Asset upload is content-sniffed, size-capped, access-checked,
  content-addressed, and attributed to the actor.

Evidence: `ContentPublisher`, `ContentToolSet`, `FieldSpec`, `IdempotencyStore`,
workflow transition tests, publishing tests, and content tool tests.

## Human control, audit, and operations

- Destructive tools require a separate, durable human decision by default.
  Self-approval, tuple drift, expired decisions, replay, and consume races fail
  closed. Operators have a bounded pending queue and admin decision UI.
- Every admitted write reserves a strict audit receipt before execution and
  finalizes the actual outcome. A finalization outage leaves a detectable
  dangling reservation rather than a false success record.
- Rate limiting is shared, atomic, principal-scoped, default-on, and fail-closed
  when no durable decision can be made. A limiter outage is recorded as a
  sanitized infrastructure error before the request is refused.
- Every accepted request closes with exactly one honest terminal audit stage.
  A JSON-RPC error returned by a protocol handler is a refusal or failure,
  never an execution success; malformed internal protocol output also fails
  toward `execution_failed`.
- Caller errors and logs exclude secrets, raw binary payloads, exception
  messages, and traces. Correlation identifiers join safe client and operator
  evidence.
- Security-sensitive configuration is strictly typed and fails application
  boot when malformed or when required audit/approval dependencies are absent.

Evidence: approval lifecycle integration tests, durable audit integration
tests, rate-limit tests, error sanitization tests, admin API/component tests,
and service-provider wiring tests.

## Application acceptance: Sheguiandah

The local Sheguiandah site completes this profile only when:

- pages, updates, events, jobs, and announcements each expose the full curated
  draft/read/preview/publish/unpublish/revision/rollback lifecycle;
- each bundle has a dedicated token/account capability and a schema matching
  its CMS fields;
- the same idempotency key can be reused across bundle namespaces without
  collision;
- production-shaped tests create, preview, publish, and render all five bundles
  through their real public routes using disposable data;
- public MCP and generic entity mutations remain disabled;
- the request-size cap safely accommodates the configured image limit; and
- focused MCP, hermetic CI, and full application suites are green against the
  local framework worktree.

No framework or application branch is released, pushed, or deployed as part of
this local acceptance profile.
