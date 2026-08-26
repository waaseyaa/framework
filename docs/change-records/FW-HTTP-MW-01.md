# FW-HTTP-MW-01 — HTTP middleware security-default convergence

Status: candidate  
Anchor mirror: waaseyaa/framework#2490  
Parent candidate: `557ae68df4e09d727ec3a591945e8fb3f24425a4`

## Intent

Make the runtime HTTP middleware stack match the documented security posture
without turning attribute discovery into implicit construction. Activate the
body-size and global request-rate controls through durable framework-owned
dependencies, prove exactly-once deterministic composition, and record an
explicit disposition for the three performance/observability middleware.

## Decisions

1. `HttpKernel`'s explicit built-in factories are the authoritative runtime
   registration model. `#[AsMiddleware]` and the compiled manifest remain
   metadata/inventory, not a dependency-injection recipe.
2. `RateLimitMiddleware` is enabled by default at priority 80 with
   `Foundation\RateLimit\DatabaseRateLimiter` over the kernel's canonical
   `DatabaseInterface`. The existing migration-owned table is shared across
   requests and workers; `InMemoryRateLimiter` is not a production fallback.
3. `BodySizeLimitMiddleware` is enabled by default at priority 70 and checks
   both the declared and actual body size.
4. Both controls have bounded configuration switches so an operator can revert
   wiring without a data migration. Disabling a control is explicit and must
   not silently substitute an in-memory implementation.
5. Built-in and provider middleware are composed by one deterministic helper.
   Duplicate concrete classes fail closed with both registration sources in the
   diagnostic; equal priorities retain registration order.
6. `CompressionMiddleware`, `ETagMiddleware`, and
   `RequestLoggingMiddleware` remain dormant. Compression needs cache/Vary and
   streaming review; ETag needs representation/auth/cache-context review;
   request logging needs privacy/redaction and logger-volume policy. Their
   classes remain available for deliberate provider opt-in.

## Work packages

- WP1: contract/spec correction and executable composition model.
- WP2: activate durable rate limiting and body-size limiting in the real
  kernel, with exact order and duplicate-refusal tests.
- WP3: worker/shared-state boundary proof, configuration rollback proof, and
  complete local/hosted verification.

## Security invariants

- No request path may receive the same concrete middleware twice.
- Production rate-limit state is shared through the canonical database and
  survives kernel/worker reconstruction.
- Oversized bodies are rejected before controller/domain dispatch even when
  `Content-Length` is absent or understated.
- Attribute discovery never auto-instantiates arbitrary classes or bypasses
  constructor ownership.
- Performance/observability middleware is not represented as active while it
  remains dormant.

## Verification evidence

- Focused and affected cross-package suites: 76 tests, 338 assertions.
- Full local preflight: 36/36 gates, including PHPStan and dead-code analysis.
- The exact committed candidate must additionally pass the complete PHPUnit
  suite, packaged-form checks, split suites, and hosted required checks before
  governed merge.
