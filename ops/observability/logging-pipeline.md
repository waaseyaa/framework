# Logging Pipeline — Waaseyaa v1.1

Documents how logs flow from application events to operator visibility.

## Current State (v1.0)

Waaseyaa does not use `psr/log`. All logging uses `error_log()` which writes to the
web server's error log (stderr in CLI, error_log in FPM).

### Log emission points

| Location | Event | Level |
|----------|-------|-------|
| `HttpKernel::handle()` | Boot failure exception | ERROR |
| `public/index.php` (outer catch) | Unhandled top-level exception | ERROR |
| `PackageManifestCompiler::scanClasses()` | PSR-4 fallback triggered | WARNING |
| `HttpKernel::handleCors()` | Mismatched CORS origin | WARNING |
| Best-effort event listeners | Cache miss, broadcast failure | WARNING |

## Log format

Plain text via `error_log()`. No structured format in v1.0.

## v1.1 Goals

- [ ] Structured log format (JSON) for machine-readable ingestion
- [ ] Log level filtering via config
- [ ] Request ID propagation across PHP and SSR layers
- [ ] Admin SPA error reporting to server-side log endpoint
- [ ] Telescope integration for real-time log viewing
