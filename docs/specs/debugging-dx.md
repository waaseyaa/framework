# Debugging & Developer Experience

## Overview

First-class debugging, logging, and developer experience for Waaseyaa. Strategic integration approach: use existing excellent libraries (Symfony VarDumper) where reinvention is wasteful, build Waaseyaa-native solutions for error pages, toolbar, and logging config.

## Architecture

Four capabilities shipping in priority order, distributed across the layer architecture:

| Capability | Package | Layer | New/Existing |
|---|---|---|---|
| Environment & Config | `packages/foundation/` | 0 | Existing |
| Logging Upgrade | `packages/foundation/` | 0 | Existing |
| Rich Error Pages | `packages/error-handler/` | 0 | **New** |
| Debug Toolbar & Dump | `packages/debug/` | 6 | **New** |

Dependencies flow downward only. `error-handler` depends on Foundation. `debug` depends on Foundation and reads from Telescope collectors.

## 1. Environment & Config

### New Environment Variables

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `APP_DEBUG` | bool | `false` | Controls error detail display, toolbar, debug headers |
| `LOG_LEVEL` | string | `warning` | Minimum log level for default handler |

### Interaction Matrix

| `APP_ENV` | `APP_DEBUG` | Behavior |
|---|---|---|
| `local` | `true` | Full dev experience: error pages, toolbar, dump, verbose logging |
| `local` | `false` | Local dev with clean output (testing production behavior) |
| `production` | `true` | **Refused at boot** — `AbstractKernel` throws. Safety rail. |
| `production` | `false` | Normal production |

### Implementation

- Add `isDebugMode(): bool` to `AbstractKernel` alongside existing `isDevelopmentMode()`
- `EnvLoader` picks up new vars with safe defaults
- Update `config/waaseyaa.php` skeleton with `debug` and `log_level` keys
- Update `.env.example` with new vars and documentation comments
- Boot guard: `AbstractKernel::boot()` throws `\RuntimeException` if `APP_ENV=production` and `APP_DEBUG=true`

## 2. Logging Upgrade

### LogManager

New central class in `packages/foundation/src/Log/` that holds named channel instances. Each channel is a `LoggerInterface` with its own handler(s).

**Default channels:**

| Channel | Purpose |
|---|---|
| `app` | General application logging |
| `request` | HTTP lifecycle (middleware, routing, response) |
| `query` | Database queries |
| `security` | Auth, access control decisions |

Extensible — packages register additional channels via config.

### Configuration

```php
// config/waaseyaa.php
'logging' => [
    'default' => 'app',
    'level' => env('LOG_LEVEL', 'warning'),
    'channels' => [
        'app'      => ['handler' => 'daily', 'path' => 'storage/logs/waaseyaa.log'],
        'query'    => ['handler' => 'daily', 'path' => 'storage/logs/query.log', 'level' => 'debug'],
        'security' => ['handler' => 'error_log', 'level' => 'warning'],
    ],
]
```

### New Handlers

| Handler | Behavior |
|---|---|
| `DailyFileHandler` | Rotated daily log files (e.g., `waaseyaa-2026-03-28.log`), configurable retention days |
| `FingersCrossedHandler` | Wraps another handler. Buffers all entries in memory. If a message at or above threshold (default: `error`) arrives, flushes entire buffer. Otherwise discards at request end. Production killer feature — full debug context only when something fails. |
| `StackHandler` | Sends to multiple handlers simultaneously (e.g., file + error_log) |

### Structured Context

- All log methods accept `array $context` (already on `LoggerInterface`)
- Format: `[2026-03-28T17:00:00+00:00] app.ERROR: Message {"user_id":5,"request_id":"abc"}`
- `RequestContextProcessor` — automatically adds request ID, method, URI to every log entry during HTTP requests

### Unchanged

- `LoggerInterface` — same method signatures
- `ErrorLogHandler`, `FileLogger`, `NullLogger` — kept as-is, usable as channel handlers
- `LogLevel` enum — already covers all 8 levels

## 3. Rich Error Pages (`packages/error-handler/`)

New package at Layer 0. Zero dependencies on Twig, admin SPA, or any higher-layer package. Depends only on Foundation.

### Components

**`ExceptionRenderer`** — Entry point. Checks `APP_DEBUG` and delegates:
- Debug mode → `DevExceptionRenderer`
- Production → returns structured error array for the caller (index.php / HttpKernel) to format as JSON:API or pass to `TwigErrorPageRenderer`

**`DevExceptionRenderer`** — Self-contained HTML page with inline CSS/JS. Shows:
- Exception class, message, code
- Full stack trace with collapsible frames, highlighting app frames vs vendor
- Code snippet for each frame (reads source files, highlights the relevant line)
- Request details (method, URI, headers, body)
- Environment snapshot (APP_ENV, APP_DEBUG, PHP version — **never** secrets/credentials)
- Chain of previous exceptions

### Solution Providers

Extensible registry for contextual help on exceptions. Solutions are **informational** (not executable — avoids the security surface of auto-running code).

```php
interface SolutionProviderInterface {
    public function canSolve(\Throwable $e): bool;
    /** @return SolutionInterface[] */
    public function getSolutions(\Throwable $e): array;
}

interface SolutionInterface {
    public function getTitle(): string;
    public function getDescription(): string;
    /** @return array<string, string> label => url */
    public function getDocumentationLinks(): array;
}
```

**Built-in solution providers:**

| Provider | Trigger | Suggestion |
|---|---|---|
| `ClassNotFoundSolution` | `Error` with "Class not found" | `composer require` or namespace typo |
| `MissingEntityTypeSolution` | Unregistered entity type ID | Registration steps |
| `DatabaseConnectionSolution` | SQLite file not found / permission denied | Check `WAASEYAA_DB` path |
| `MissingConfigSolution` | Config key not found | Check `waaseyaa.php` |

### Editor Links

`EditorLinkGenerator` — Generates protocol links from file path + line number:
- `phpstorm://open?file=/path&line=42`
- `vscode://file/path:42`
- `sublime://open?url=file:///path&line=42`

Configurable via `EDITOR` env var (default: `phpstorm`).

### Integration

- `public/index.php` catch block delegates to `ExceptionRenderer` instead of inline JSON error
- `HttpKernel` boot failures use `DevExceptionRenderer` directly (Twig not available yet)
- Post-boot exceptions go through `ExceptionRenderer`, which falls through to `TwigErrorPageRenderer` for production

## 4. Debug Toolbar & Dump (`packages/debug/`)

New package at Layer 6 (Interfaces). Depends on Foundation, reads from Telescope collectors.

### Toolbar (`DebugToolbarMiddleware`)

- Registered at lowest priority (outermost onion layer — runs last, wraps full response)
- Only active when `APP_DEBUG=true`
- Only injects into HTML responses (checks `Content-Type: text/html`)
- Injects `<div>` + inline CSS/JS before `</body>` — self-contained, no external assets
- Collapsible bar at bottom of page:

| Panel | Content |
|---|---|
| Response | Status code + timing (ms) |
| Queries | Count + total query time (from Telescope query recorder) |
| Route | Matched route name + pattern |
| Memory | Peak memory usage |
| Logs | Message count, errors highlighted |

Click any panel to expand details.

### Debug Headers (`DebugHeaderMiddleware`)

Active when `APP_DEBUG=true`. Applies to **all** responses (HTML and JSON:API).

| Header | Example | Purpose |
|---|---|---|
| `X-Debug-Time` | `42ms` | Request duration |
| `X-Debug-Queries` | `7` | Query count |
| `X-Debug-Memory` | `4.2MB` | Peak memory |
| `X-Debug-Request-Id` | `abc123` | Links to Telescope entry |

SPA devtools and `curl` users get useful signals without a toolbar.

### Dump & dd()

Global helper functions integrating `symfony/var-dumper` (Composer dependency of this package).

**Output routing by context:**

| Context | Output destination |
|---|---|
| CLI | Styled terminal output (VarDumper CLI formatter) |
| HTML response with toolbar | Captured in "Dumps" toolbar panel |
| API/JSON response | `error_log()` + Telescope entry (no response pollution) |

`dd()` dumps then exits. `dump()` continues execution.

Future: `ServerDumpHandler` for Trap/Buggregator integration. Not in v1 scope but handler architecture supports it.

### Error Page Preview

`/_error/{statusCode}` — dev-only route that renders the production error page for a given status code. Returns 404 when `APP_DEBUG=false`.

## Package Dependencies

```
packages/foundation/  (existing, Layer 0)
  └── Log/LogManager, DailyFileHandler, FingersCrossedHandler, StackHandler
  └── Log/RequestContextProcessor
  └── Kernel/ — isDebugMode(), boot guard, LOG_LEVEL support

packages/error-handler/  (new, Layer 0)
  └── depends on: foundation
  └── ExceptionRenderer, DevExceptionRenderer
  └── SolutionProviderRegistry, SolutionProviderInterface, SolutionInterface
  └── EditorLinkGenerator

packages/debug/  (new, Layer 6)
  └── depends on: foundation, telescope (optional/reads from)
  └── composer requires: symfony/var-dumper
  └── DebugToolbarMiddleware, DebugHeaderMiddleware
  └── dump(), dd() helpers
  └── Error preview route
```

## External Dependencies

| Package | Used by | Purpose |
|---|---|---|
| `symfony/var-dumper` | `packages/debug/` | `dump()` / `dd()` formatting and output routing |

No other new external dependencies. Error pages, toolbar, and logging are all built in-house.

## What This Does NOT Include

- **Executable solutions** — Solutions are informational only. No auto-running code from error pages.
- **Monolog** — Waaseyaa's existing `LoggerInterface` is extended, not replaced. Monolog is unnecessary overhead for the channel/handler model.
- **Vue debug panel** — SPA debugging uses `X-Debug-*` headers + Telescope. A dedicated Vue component is future scope.
- **Dump server** — Trap/Buggregator integration is architecturally supported but not in v1.
- **Xdebug/Blackfire integration** — Those work independently via PHP extensions. No framework coupling needed.
