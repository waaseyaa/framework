# FW-MCP-BODY-LIMIT-01 — reachable MCP request-size default

Status: candidate  
Anchor mirror: waaseyaa/framework#2637  
Parent candidate: `origin/main`

## Intent

Stop advertising a 10 MiB MCP request cap that the kernel body-size
middleware refuses at 1 MiB.

## Decisions

1. `StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES` is 1 MiB, the
   same default as `BodySizeLimitMiddleware`.
2. While `http_security.body_size_limit` is enabled, wiring fails closed if
   `mcp.transport.max_request_bytes` exceeds that kernel ceiling.
3. Disabling the kernel control leaves the MCP setting as the effective
   ceiling (still bounded 1024–104857600).
4. This slice does not add a route-scoped kernel exemption for `/mcp`.

## Verification

`StreamableHttpTransportGuardTest::advertisedDefaultMatchesTheKernelBodyLimit`
and `McpServiceProviderTest` transport-size cases.
