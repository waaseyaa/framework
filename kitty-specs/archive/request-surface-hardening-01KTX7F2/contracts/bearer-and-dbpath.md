# Contract: Bearer-Token Hardening & Database Path Resolution

**Mission**: request-surface-hardening-01KTX7F2 | **Requirements**: FR-005..FR-008, NFR-003, C-002

Applies to `BearerTokenAuth` (the framework's `McpAuthInterface` implementation) and to database path resolution in every kernel runtime (`DatabaseBootstrapper`, mirrored by the CLI's `db:init` and display handlers).

## Bearer-token comparison (FR-005)

1. **Constant-time over candidates**: `authenticate()` compares the presented token against **every** entry of the token map using `hash_equals()`, with no early exit on match — one full scan, one return statement after the loop. Per-comparison timing is `hash_equals`' constant-time guarantee; whole-call timing does not depend on *which* entry matches.
2. **Key coercion guard**: each map key is `(string)`-cast before `hash_equals()` (PHP coerces purely numeric token strings to `int` array keys). A numeric token authenticates correctly; pinned by test.
3. **Prefix handling unchanged**: null/empty headers and non-`bearer ` (case-insensitive) prefixes return `null` before any comparison, exactly as today.
4. **`getTokens()` unchanged**: the admin-fingerprinting accessor keeps returning the raw token→account map (`ServerConfigReadModel` contract).

## Blocked-account rejection (FR-006, NFR-003)

5. **Fail closed at request time**: when the scan matches an account that exposes `isActive()` (duck-typed `method_exists`) and `isActive()` returns `false`, `authenticate()` returns `null`. The caller-visible outcome is identical to an unknown token (`McpEndpoint` emits the same 401 JSON-RPC envelope) — no blocked-vs-invalid oracle.
6. **Duck-typed seam (research D4)**: `AccountInterface` has no status member; the framework's `User` entity exposes `isActive(): bool` (the same accessor the session login query's `status = 1` condition mirrors). No `mcp → user` manifest edge, no `AccountInterface` widening. An account object without an `isActive()` method authenticates as before — custom `McpAuthInterface`/account implementations own their liveness semantics; documented in `mcp-endpoint.md`.
7. **Zero added queries (NFR-003)**: the status read is an in-memory method call on the already-resolved account object. Kernels — and therefore token maps and their account objects — are constructed per request in every runtime, so the read reflects persisted state as of the current request's boot. No re-load, no cache, no new I/O.
8. **Anonymous/sentinel accounts**: the check applies only to the matched map entry; there is no special-casing of ids. A deliberately mapped always-active account object (e.g. a service-account stub without `isActive()`) is unaffected.

## Database path resolution (FR-007)

9. **Precedence unchanged**: `config['database']` → `WAASEYAA_DB` env → `{projectRoot}/storage/waaseyaa.sqlite`.
10. **Project-root absolutization**: after precedence, a relative value — *from either source* — resolves to `{projectRoot}/{relative}` (leading `./` stripped first). The resolved path is a pure function of (configured value, projectRoot); **process CWD never participates**.
11. **Absolute passthrough**: `:memory:`, leading `/`, Windows drive-letter (`X:` followed by a separator), and UNC (`\\`) values pass through byte-identical. The unset default is already project-root-absolute — unchanged (acceptance scenario 5).
12. **Climbing relatives**: `../shared/db.sqlite` resolves against the project root (`{projectRoot}/../shared/db.sqlite`), not against CWD (spec edge case).
13. **Every runtime, one seam**: the resolution lives in `DatabaseBootstrapper::resolvePath()`, whose sole production caller is `AbstractKernel` — covering HttpKernel (dev server/FPM/FrankenPHP; projectRoot = `dirname(public/)`), ConsoleKernel (CLI; projectRoot = `getcwd()` validated against `composer.json`), and queue workers (CLI commands). With identical configuration, HTTP under a docroot CWD and the CLI under the project root open the **same file**; no stray database appears under the docroot (SC-004).
14. **CLI parity**: `DbInitHandler` adopts the same classification and absolutization rules — including absolutizing relative `config['database']` values (passed through verbatim today) and recognizing Windows absolutes (only `/`-prefixed today). `db:init`'s reported and initialized path equals the kernel's resolved path for any given configuration.
15. **Display surfaces**: `health:report` and `about` display the *resolved* database path rather than the raw env value, so operators debugging #1650-class issues see what the kernel actually opens.
16. **Production guard unchanged**: the existing missing-database production guard and non-production `@mkdir` behavior operate on the resolved path with their current semantics.

## Docroot warning (FR-008)

17. **Predicate**: warn ⇔ resolved path ≠ `:memory:` ∧ the lexically normalized resolved path (separator unification + `.`/`..` segment resolution; no `realpath()` — the file may not exist yet) is contained in the normalized `{projectRoot}/public`.
18. **Emission**: once per boot, `warning` level, via the bootstrapper's new optional `?LoggerInterface $logger = null` (NullLogger default — the framework's standard pattern; `AbstractKernel` passes its kernel logger). The message names the resolved path and the docroot and advises correcting `WAASEYAA_DB`/`config['database']`.
19. **Boot proceeds**: the warning never throws or aborts; a kernel constructed without a logger boots silently (best-effort advisory, not a security boundary).

## Verification

- Unit (mcp): existing `BearerTokenAuthTest` matrix green unchanged; new `BearerTokenAuthHardeningTest` — `hash_equals` scan (match-first, match-last, numeric-string token), blocked account with `isActive(): false` → null (SC-003), active account passes, account without `isActive()` passes, blocked rejection indistinguishable from unknown token.
- Unit (foundation): `DatabaseBootstrapperTest` extended with the full resolution matrix of data-model.md (relative env, relative config, climbing, drive-letter/UNC passthrough, `:memory:`, default), plus docroot-warning emission/non-emission via a spy logger and no-logger silence.
- Unit (cli): `DbInitHandlerTest` parity cases (relative config absolutized, Windows absolutes untouched); display handlers show resolved paths.
- Integration: `tests/Integration/DbPath/DbPathResolutionTest.php` — with `WAASEYAA_DB=./storage/<name>.sqlite` and the process CWD set to the docroot, an HTTP-shaped boot (projectRoot = front-controller-derived) and a CLI-shaped boot (projectRoot = project dir) resolve to the same file; a write through one is read through the other; no file materializes under the docroot (SC-004).
