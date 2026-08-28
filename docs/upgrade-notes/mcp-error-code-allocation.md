# Upgrade Note: MCP JSON-RPC Error-Code Allocation

Issue #2561 renumbers eight JSON-RPC error codes the MCP endpoint emits. **This
is a wire change.** A client that branches on the numeric `error.code` of an MCP
refusal must be updated; a client that reads `error.message` or `error.data` is
unaffected, and every HTTP status, message string and `data` member is
unchanged.

## Why

`McpProtocol::CURRENT` is `2026-07-28`, and that revision partitions the
JSON-RPC implementation-defined range:

- `-32020..-32099` is reserved for the MCP specification. An implementation
  **MUST NOT** emit a code in it that the specification does not define. The
  specification defines exactly three: `-32020`, `-32021`, `-32022`.
- `-32002` (resource not found) and `-32042` (URL elicitation required) are
  named as retired and **MUST NOT** be emitted.
- New codes for purposes the specification does not define **SHOULD** be
  allocated outside `-32768..-32000`.

The endpoint emitted eight codes against those rules, and `-32002` carried two
unrelated meanings from the same server — resource-not-found on `resources/read`
and an infrastructure outage on `tools/call`. A client mapping `-32002` to
resource-not-found rendered an audit outage as a missing resource and retried a
different URI instead of backing off.

## The mapping

Transport, rate-limit and infrastructure refusals move to the `-31xxx` band,
outside the JSON-RPC reserved range. Each keeps the last two digits of the code
it replaces, so an old log line is still findable.

| Refusal | Was | Now | Constant |
|---|---|---|---|
| Forbidden origin | `-32040` | `-31040` | `McpErrorCode::FORBIDDEN_ORIGIN` |
| `Accept` violations | `-32041` | `-31041` | `McpErrorCode::UNACCEPTABLE_ACCEPT` |
| `Content-Type` not `application/json` | `-32042` | `-31042` | `McpErrorCode::UNSUPPORTED_CONTENT_TYPE` |
| Request body over the size cap | `-32043` | `-31043` | `McpErrorCode::REQUEST_TOO_LARGE` |
| Rate limit exceeded | `-32029` | `-31029` | `McpErrorCode::RATE_LIMIT_EXCEEDED` |
| Rate limiter unavailable | `-32030` | `-31030` | `McpErrorCode::RATE_LIMITER_UNAVAILABLE` |
| Audit trail unavailable | `-32002` | `-31001` | `McpErrorCode::AUDIT_TRAIL_UNAVAILABLE` |
| Approval store unavailable | `-32002` | `-31002` | `McpErrorCode::APPROVAL_STORE_UNAVAILABLE` |
| `resources/read` not found (legacy era) | `-32002` | `-32602` | — |

Two consequences worth reading twice:

- **The two `-32002` infrastructure refusals are now distinct codes.** A client
  that treated "audit trail unavailable" and "approval store unavailable" as one
  condition — it had no choice — can now tell them apart. Both are still
  sanitized and still carry `data.correlation_id`.
- **`resources/read` answers `-32602` in every protocol era.** The modern path
  already did; the legacy path used `-32002`, which `2026-07-28` names `-32602`
  as the replacement for. `-32602` is a JSON-RPC standard code a client of any
  era understands. The capability-denied and absent-resource outcomes remain
  byte-identical, so the existence oracle stays closed.

## What did not change

- `-32001` Unauthorized, `-32003` Approval required, and `-32004` Approval
  refused are unchanged. They sit in the legacy `-32000..-32019` sub-range,
  where the specification's language is **SHOULD NOT**, not MUST NOT, and all
  three are load-bearing wire contracts clients already implement — `-32003`
  and `-32004` are the two halves of the write-tier approval handshake.
  Renumbering them is a consumer-visible break with no MUST behind it, so it is
  a separate decision. They are recorded, with rationale, in
  `McpErrorCode::LEGACY_IN_USE`; a *new* allocation in that sub-range fails
  `McpErrorCodeAllocationTest`.
- `-32020` / `-32022` in `McpProtocolRequestValidator` keep their spec-defined
  meanings, and the JSON-RPC standard codes (`-32700`, `-32600`, `-32601`,
  `-32602`) are untouched.
- Every HTTP status is unchanged: 403, 406, 413, 415, 429, 503, and 200 for the
  in-band `tools/call` refusals.

## For implementors

`Waaseyaa\Mcp\McpErrorCode` is the single place codes are allocated, and
`McpErrorCode::isEmittable()` states the MUST-level policy. Use the constants
rather than literals: `McpErrorCodeAllocationTest` scans `packages/mcp/src` and
fails on any new literal in the forbidden bands.
