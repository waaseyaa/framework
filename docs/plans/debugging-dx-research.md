# PHP Framework Debugging & Developer Experience Research

Research conducted 2026-03-28 to inform Waaseyaa's debugging/DX design.

---

## 1. Laravel

### Environment/Debug Toggle
- **`APP_DEBUG`** env var in `.env` (bool). Read via `config('app.debug')`.
- `APP_ENV` controls environment (`local`, `production`, `testing`).
- Simple, single toggle. Debug=true shows detailed errors; false shows generic error page.

### Error Page Rendering
- **Ignition** (by Spatie) — the default error page since Laravel 6.
  - Beautiful, interactive single-page layout with scroll navigation.
  - **Executable solutions**: can auto-fix common problems (run missing migrations, generate APP_KEY, fix typos in variable names).
  - Custom solution providers via `HasSolutionsForThrowable` interface.
  - Links to editor (click-to-open file at line number).
  - Shareable error reports via Flare (paid service).
- Production: generic branded error pages (404, 500, 503 etc.), customizable via Blade templates in `resources/views/errors/`.

### Logging Architecture
- **Monolog-based** with a channel/stack abstraction in `config/logging.php`.
- **Channels**: named log destinations (file, syslog, Slack, Papertrail, etc.).
- **Stacks**: aggregate multiple channels — e.g., log to both file AND Slack.
- Built-in drivers: `single`, `daily`, `slack`, `syslog`, `errorlog`, `monolog`, `custom`, `stack`, `null`.
- Per-channel minimum log level filtering.
- `tap` option for advanced Monolog handler customization.
- Context-aware: `Log::info('msg', ['user_id' => $id])`.

### Debug Toolbar/Profiler
- **Laravel Debugbar** (barryvdh) — injects toolbar into HTML responses:
  - Query log with timing, duplicate detection, EXPLAIN support, copy-to-clipboard.
  - Route, view, event, mail, cache, auth collectors.
  - Custom measure points for profiling code sections.
  - Timeline visualization.
- **Laravel Telescope** — separate debug assistant (web UI dashboard):
  - Monitors requests, exceptions, queries, jobs, mail, notifications, cache, scheduled tasks, dumps.
  - N+1 query detection.
  - Failed job replay.
  - Full stack traces for exceptions.
  - Stored in database, browsable via `/telescope` route.

### What Developers Love
- Ignition's executable solutions (auto-fix problems).
- Click-to-open-in-editor from error pages.
- Channel/stack logging is intuitive and flexible.
- Telescope provides deep insight without external tools.
- Debugbar's query analysis catches performance issues early.

### What's Annoying/Missing
- Telescope uses database storage — can slow down dev on large apps.
- Debugbar and Telescope are separate packages with overlapping features (no unified tool).
- Ignition's Flare integration pushes toward paid service.
- No built-in profiler for memory/CPU — need Xdebug or Blackfire externally.
- `APP_DEBUG=true` in production is a common security mistake (exposes env vars, DB credentials).

---

## 2. Symfony

### Environment/Debug Toggle
- **`APP_ENV`** (`dev`, `prod`, `test`) and **`APP_DEBUG`** (bool) in `.env`.
- Two separate concepts: environment selects config bundles; debug enables toolbar + detailed errors.
- Can run `prod` with debug on, or `dev` with debug off (flexible but confusing for newcomers).

### Error Page Rendering
- **Dev**: Full exception pages with stack traces, request/response details, related variables, error chain. Rendered by `symfony/error-handler`.
- **Prod**: Clean, customizable error pages via Twig templates (`error404.html.twig`, `error500.html.twig`).
- **Preview route**: Can preview production error pages in dev via `/_error/{statusCode}`.
- Customization tiers: override templates → custom normalizer → custom error controller → `kernel.exception` event listener.

### Logging Architecture
- **MonologBundle** — same Monolog underneath, but more granular configuration.
- **Channels**: `app`, `doctrine`, `event`, `security`, `request`, `router`, plus custom channels.
- **Handlers per channel**: each channel can route to different handlers (files, email, Slack, Loggly).
- **Handler stacking**: `group`, `buffer`, `filter`, `fingers_crossed` (accumulate logs, flush on error).
- `fingers_crossed` is particularly clever — buffers all debug/info logs and only writes them if an error-level message occurs. Keeps prod logs clean but gives full context when errors happen.
- Autowired logger channels via service tags.

### Debug Toolbar/Profiler
- **Web Profiler Toolbar** — injected into HTML responses in dev:
  - Request/response info, route matching, Twig templates rendered, translations.
  - Database queries with timing.
  - Event listeners fired.
  - Log messages for the request.
  - Memory usage, response time.
- **Profiler** (separate full-page UI at `/_profiler`):
  - Deep drill-down into any recent request.
  - 10x more detail than toolbar.
  - Performance panel with timeline.
  - Form debugging (validation errors, submitted data).
  - Security panel (authentication, authorization decisions).
  - Configurable data retention (probabilistic cleanup after 2 days).
- Both are **first-party** (part of the framework), not third-party packages.

### What Developers Love
- Web Profiler is best-in-class — integrated, comprehensive, always there in dev.
- `fingers_crossed` handler is brilliant for production logging.
- Error page preview route eliminates "deploy to see error page" cycle.
- Profiler's form debugging panel saves hours.
- First-party integration means everything works together seamlessly.

### What's Annoying/Missing
- Two env vars (`APP_ENV` + `APP_DEBUG`) confuse beginners.
- MonologBundle configuration is verbose YAML — steep learning curve.
- Profiler stores data on disk, can accumulate.
- No equivalent to Ignition's "executable solutions" — errors are informational only.
- Channel configuration has a gotcha: nested handlers ignore channel filters.

---

## 3. WordPress

### Environment/Debug Toggle
- **`WP_DEBUG`** — PHP constant in `wp-config.php`. Boolean. Default `false`.
- **`WP_DEBUG_LOG`** — when `true`, writes to `wp-content/debug.log`. Can be set to custom file path.
- **`WP_DEBUG_DISPLAY`** — when `true`, shows errors inline in HTML. Default `true` (bad for production).
- All three are independent constants — must be set manually, no environment abstraction.

### Error Page Rendering
- No framework-provided error page. `WP_DEBUG_DISPLAY=true` shows raw PHP errors/notices inline.
- Fatal errors show WordPress's generic "There has been a critical error" page.
- No dev vs prod distinction — just on/off for raw PHP output.
- Plugins like Query Monitor add richer error display.

### Logging Architecture
- Minimal: `WP_DEBUG_LOG` writes PHP errors to a flat file using PHP's `error_log()`.
- No log levels, channels, or structured logging built-in.
- No Monolog or PSR-3 integration in core.
- Plugin ecosystem fills the gap (WP Log Viewer, Query Monitor, Debug Bar).

### Debug Toolbar/Profiler
- **Query Monitor** (plugin) — the de facto debug toolbar:
  - Database queries with caller, timing, duplicates.
  - HTTP API calls.
  - Hooks/actions fired.
  - PHP errors/warnings.
  - Conditional checks, transients.
  - Environment info.
- **Debug Bar** (plugin) — older alternative, less maintained.
- Nothing built-in to WordPress core.

### What Developers Love
- Query Monitor is excellent despite being third-party.
- Simple mental model — three constants, easy to understand.
- `WP_DEBUG_LOG` with `WP_DEBUG_DISPLAY=false` is a clean pattern for staging.

### What's Annoying/Missing
- No environment abstraction whatsoever — constants in a PHP file.
- `WP_DEBUG_DISPLAY` defaults to `true` — shows errors to users by default when debug is on.
- No structured logging — just a flat text file.
- No error page design — raw PHP errors or generic white page.
- No PSR-3 compatibility in core.
- Completely reliant on plugins for any real debugging tooling.
- `debug.log` grows unbounded with no rotation.

---

## 4. Drupal

### Environment/Debug Toggle
- **`$config['system.logging']['error_level']`** in `settings.php` or `settings.local.php`.
- Values: `hide`, `some`, `all`, `verbose` (verbose includes backtrace).
- Also settable via admin UI: `/admin/config/development/logging`.
- Also via Drush: `drush config:set system.logging error_level verbose`.
- **`settings.local.php`** pattern: `settings.php` includes `settings.local.php` if it exists (gitignored for dev overrides).
- Twig debug: separate toggle `$settings['twig_debug'] = TRUE` for template suggestions.

### Error Page Rendering
- Verbose mode shows full backtraces in the message area of the Drupal page.
- Production shows "The website encountered an unexpected error" with no details.
- Custom error pages via Twig templates in the theme.
- Less polished than Laravel/Symfony — errors appear as Drupal status messages, not dedicated error pages.

### Logging Architecture
- **Watchdog** — the core logging concept, now abstracted as `LoggerInterface` (PSR-3 in Drupal 8+).
- **dblog module**: stores logs in database, viewable at `/admin/reports/dblog`.
- **syslog module**: writes to OS syslog (better for production performance).
- Only one can be active at a time (or use contrib modules for both).
- dblog has performance issues at scale — writes to DB on every log call.
- Custom loggers registered as tagged services in `*.services.yml`.
- No channel/stack concept like Laravel/Symfony — single destination.

### Debug Toolbar/Profiler
- **Webprofiler** (contrib module, Devel suite) — port of Symfony's Web Profiler:
  - Database queries, cache hits/misses, events, routing.
  - Timeline visualization.
  - Less polished than Symfony's original.
- **Devel module** — `dpm()`, `kint()` for variable inspection.
- Nothing in core.

### What Developers Love
- PSR-3 logging interface is clean.
- `settings.local.php` pattern is good for per-environment config.
- Drush CLI for config changes is convenient.
- Admin UI for log viewing (dblog) is accessible to non-developers.

### What's Annoying/Missing
- dblog's database writes hurt performance.
- No structured logging channels — one destination at a time.
- Error display is crude compared to Ignition/Symfony.
- Webprofiler is contrib, not maintained as well as Symfony's.
- Twig debug toggle is separate from error display toggle — multiple knobs to turn.
- No "executable solutions" or click-to-editor.

---

## 5. Innovative / Modern Approaches

### Buggregator / Trap
- **Buggregator**: free, standalone Docker server that aggregates debug data from multiple sources.
- **Trap**: lightweight PHP package (no Docker needed) that acts as a local debug server.
  - `trap()` function replaces `dump()` — sends to local server instead of inline HTML.
  - Supports: Symfony VarDumper, Monolog, Sentry, SMTP traps, HTTP dumps, Ray compatibility.
  - Multiple apps can send to one Trap instance.
  - Includes Xhprof integration for profiling.
- **Key insight**: separates debug output from the application response. No more polluting HTML with dump output.

### Spiral Framework / RoadRunner
- Long-running PHP app server — `die()`/`exit()` breaks the worker, not just the request.
- Forces cleaner debugging discipline.
- Integrates with Buggregator for dump collection.
- RoadRunner supports Xdebug for step debugging.

### FrankenPHP
- Modern PHP app server built on Caddy.
- Early Symfony 8 integration.
- Worker mode where PHP stays in memory — similar debugging challenges to RoadRunner.
- No framework-specific debug tooling yet — relies on Xdebug + IDE.

### Ray (by Spatie)
- Desktop app for debugging — `ray($variable)` sends to standalone GUI.
- Color-coded, searchable, filterable.
- Measure execution time, count calls, pause execution.
- Works with Laravel, WordPress, plain PHP.
- **Paid product** (one-time license).

### Key Trends
1. **Debug output separation**: Moving dumps out of HTTP response into separate tools (Trap, Ray, Telescope).
2. **Executable/actionable errors**: Ignition's auto-fix solutions are unique and loved.
3. **Unified debug server**: Buggregator aggregates dumps + logs + mail + profiling in one place.
4. **First-party integration wins**: Symfony's Profiler being built-in gives the best DX.
5. **Long-running PHP**: Worker-mode servers need debug tools that don't kill the process.

---

## Comparative Summary

| Feature | Laravel | Symfony | WordPress | Drupal |
|---|---|---|---|---|
| **Debug toggle** | `APP_DEBUG` env | `APP_ENV` + `APP_DEBUG` | `WP_DEBUG` constant | Config value in settings.php |
| **Error pages (dev)** | Ignition (interactive, solutions) | Exception pages (stack traces) | Raw PHP errors | Backtrace in message area |
| **Error pages (prod)** | Blade templates | Twig templates | Generic white page | Generic message |
| **Logging** | Monolog channels+stacks | Monolog channels+handlers | Flat file via error_log() | PSR-3, dblog or syslog |
| **Clever logging** | Stack aggregation | `fingers_crossed` handler | None | None |
| **Debug toolbar** | Debugbar (3rd party) | Web Profiler (1st party) | Query Monitor (plugin) | Webprofiler (contrib) |
| **Profiler** | Telescope (1st party) | Profiler (1st party) | None built-in | Devel (contrib) |
| **Actionable errors** | Ignition solutions | No | No | No |
| **Click-to-editor** | Yes (Ignition) | Yes (error pages) | No | No |
| **Production safety** | APP_DEBUG=false hides all | APP_DEBUG=0 hides all | WP_DEBUG_DISPLAY=false | error_level=hide |

---

## Design Recommendations for a New Framework

Based on this research, the ideal debugging DX would combine:

1. **Single env var toggle** (`APP_DEBUG` or similar) — Laravel's simplicity, not Symfony's two-var approach.
2. **Beautiful error pages with actionable solutions** — Ignition's killer feature. Solution providers should be extensible.
3. **Click-to-editor** support from error pages (configurable editor protocol).
4. **First-party debug toolbar** — Symfony's approach of building it in, not relying on third-party.
5. **`fingers_crossed` log handler** — Symfony's best logging innovation. Buffer debug logs, flush on error.
6. **Structured logging with channels** — Laravel/Symfony's Monolog integration is table stakes.
7. **Separated debug output** — Buggregator/Trap pattern. A `dump()` function that sends to a sidecar server, not inline HTML.
8. **Error page preview route** — Symfony's `/_error/{code}` pattern for testing production error pages in dev.
9. **Safe defaults** — Debug OFF by default. Never show detailed errors unless explicitly enabled.
10. **Environment-aware config** — settings.local.php pattern (Drupal) or .env (Laravel/Symfony) for per-environment overrides.
