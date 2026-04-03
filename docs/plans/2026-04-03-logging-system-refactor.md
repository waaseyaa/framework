# F7 Logging System Refactor Plan

**Issue:** #4 (waaseyaa/framework)
**Approach:** Composable pipeline
**Phases:** A (minimal) → B (channels + formatters) → C (processors + named channels)

---

## Phase A — Minimal Foundation

### Goal

Wire `LOG_LEVEL` into the default logger, introduce `LogRecord`, create `LogManager` with a default channel, and make the logger available to service providers.

### Invariants

1. `LogRecord` is an immutable value object. Once created, no property can be mutated.
2. `LogManager` always has a `default` channel. Requesting a non-existent channel returns the `default` channel (no exceptions).
3. `LogManager` implements `LoggerInterface`. Calling `log()` on it delegates to the `default` channel.
4. The kernel-constructed logger respects `config['log_level']`. Messages below the configured level are discarded.
5. Service providers can resolve `LoggerInterface` via the kernel resolver. The resolved instance is the same `LogManager` singleton.
6. Existing `CompositeLogger`, `FileLogger`, `ErrorLogHandler`, `NullLogger` continue to work unchanged. No behavioral regressions.

### Required Changes

**New classes:**

| Class | Location | Purpose |
|---|---|---|
| `LogRecord` | `packages/foundation/src/Log/LogRecord.php` | Immutable VO: `level`, `message`, `context`, `channel`, `timestamp` |
| `LogManager` | `packages/foundation/src/Log/LogManager.php` | Channel registry, implements `LoggerInterface`, delegates to default channel |

**Modified classes:**

| Class | Change |
|---|---|
| `ErrorLogHandler` | Add optional `LogLevel $minimumLevel` constructor param. Filter messages below threshold. |
| `AbstractKernel` | Construct `LogManager` instead of raw `ErrorLogHandler`. Pass `config['log_level']` to handler. |
| `ProviderRegistry` | No change — already receives `LoggerInterface`. |
| `Kernel/Bootstrap/ProviderRegistry::discoverAndRegister()` | Add `LoggerInterface` to the kernel resolver closure (alongside `EntityTypeManager`, `DatabaseInterface`, `EventDispatcherInterface`). |

**Config:**

No config schema changes. Existing `log_level` key in `config/waaseyaa.php` is sufficient.

### Migration Path

1. Create `LogRecord` (no consumers yet — prepared for Phase B).
2. Add `minimumLevel` filtering to `ErrorLogHandler`.
3. Create `LogManager` wrapping a single `ErrorLogHandler` as the default channel.
4. Replace `new ErrorLogHandler()` in `AbstractKernel` with `new LogManager(...)`.
5. Register `LoggerInterface` in the kernel resolver closure.
6. All existing `LoggerInterface` consumers (ProviderRegistry, SessionMiddleware, etc.) work unchanged.

### Test Coverage

- `LogRecord`: immutability, all properties accessible, `channel` defaults to `default`.
- `LogManager`: implements `LoggerInterface`, `channel('default')` returns itself, `channel('unknown')` returns default, `log()` delegates to handler.
- `ErrorLogHandler`: messages below `minimumLevel` are discarded, messages at or above are written.
- Integration: `AbstractKernel` boot produces a `LogManager`, service provider `resolve(LoggerInterface::class)` returns it.

---

## Phase B — Channels + Formatters

### Goal

Introduce `HandlerInterface` and `FormatterInterface`. Build channels from config. Support level-based routing.

### Invariants

1. `HandlerInterface` accepts a `LogRecord` and writes it somewhere. It owns a `FormatterInterface` for serialization.
2. `FormatterInterface` converts a `LogRecord` into a `string`. Stateless, no side effects.
3. Every handler has exactly one formatter. Default: `TextFormatter`.
4. Channels are defined in `config/waaseyaa.php` under a `logging.channels` key. Each channel specifies a handler type, optional formatter, and optional minimum level.
5. If `logging.channels` is absent, `LogManager` creates a single `default` channel using `ErrorLogHandler` at the configured `log_level` — backward compatible with Phase A.
6. `CompositeLogger` is deprecated in favor of a `stack` channel type that delegates to multiple named channels.
7. Level-based routing: each handler has its own `minimumLevel`. A channel only processes records at or above its threshold.

### Required Changes

**New interfaces:**

| Interface | Location | Purpose |
|---|---|---|
| `HandlerInterface` | `packages/foundation/src/Log/Handler/HandlerInterface.php` | `handle(LogRecord): void` |
| `FormatterInterface` | `packages/foundation/src/Log/Formatter/FormatterInterface.php` | `format(LogRecord): string` |

**New classes:**

| Class | Location | Purpose |
|---|---|---|
| `FileHandler` | `packages/foundation/src/Log/Handler/FileHandler.php` | Writes formatted record to file (replaces `FileLogger` internals) |
| `ErrorLogHandler` (refactor) | `packages/foundation/src/Log/Handler/ErrorLogHandler.php` | Writes formatted record to `error_log()` (refactored to implement `HandlerInterface`) |
| `StreamHandler` | `packages/foundation/src/Log/Handler/StreamHandler.php` | Writes to `php://stderr` or any stream |
| `NullHandler` | `packages/foundation/src/Log/Handler/NullHandler.php` | Discards records |
| `TextFormatter` | `packages/foundation/src/Log/Formatter/TextFormatter.php` | `[timestamp] [level] [channel] message {context}` |
| `JsonFormatter` | `packages/foundation/src/Log/Formatter/JsonFormatter.php` | One JSON object per line |

**Modified classes:**

| Class | Change |
|---|---|
| `LogManager` | Parse `logging.channels` config. Build handler+formatter per channel. Support `stack` channel type. |
| `FileLogger` | Deprecate. Thin wrapper delegating to `LogManager::channel('file')`. |
| `ErrorLogHandler` (old) | Deprecate the `Log/ErrorLogHandler.php` location. Redirect to `Handler/ErrorLogHandler`. |
| `CompositeLogger` | Deprecate. Document migration to `stack` channel. |

**Config schema (`config/waaseyaa.php`):**

```
logging:
  default: stack
  channels:
    stack:
      type: stack
      channels: [errorlog, file]
    errorlog:
      type: errorlog
      level: warning
      formatter: text
    file:
      type: file
      path: storage/logs/waaseyaa.log
      level: debug
      formatter: text
```

### Migration Path

1. Create `HandlerInterface` and `FormatterInterface`.
2. Create `TextFormatter` and `JsonFormatter`.
3. Create `FileHandler`, `StreamHandler`, `NullHandler`. Refactor `ErrorLogHandler` to implement `HandlerInterface`.
4. Update `LogManager` to parse config, build channels, support `stack` type.
5. Deprecate `FileLogger`, `CompositeLogger`, old `ErrorLogHandler` location with `@deprecated` + `trigger_deprecation()`.
6. Existing code using `LoggerInterface` unchanged — `LogManager` still implements it.

### Test Coverage

- `TextFormatter`: format output matches `[timestamp] [level] [channel] message {context}`.
- `JsonFormatter`: output is valid JSON, all fields present.
- `FileHandler`: writes to file, respects `minimumLevel`, uses formatter.
- `StreamHandler`: writes to `php://memory` stream.
- `ErrorLogHandler` (new): delegates to `error_log()`, applies formatter.
- `LogManager` channel building: config with 2 channels produces 2 distinct handlers. Stack channel delegates to both. Missing config falls back to Phase A default.
- Level routing: `debug` message goes to `file` channel (level: debug) but not `errorlog` channel (level: warning).

---

## Phase C — Processors + Named Channels

### Goal

Introduce `ProcessorInterface` for context enrichment. Add named channel API. Support per-channel processor and formatter configuration.

### Invariants

1. `ProcessorInterface` accepts a `LogRecord` and returns a new `LogRecord` with enriched context. Must not mutate the input.
2. Processors run in order before the handler. A handler's processor stack is a FIFO pipeline: `$record = $processor1($record)` → `$processor2($record)` → `$handler->handle($record)`.
3. `LogManager::channel(string $name)` returns a `LoggerInterface` scoped to that channel. The returned logger sets `channel` on every `LogRecord` it creates.
4. Global processors apply to all channels. Per-channel processors apply only to their channel. Execution order: global first, then per-channel.
5. Processor failures are best-effort: catch, log via `error_log()`, continue pipeline. A broken processor must not prevent log delivery.

### Required Changes

**New interface:**

| Interface | Location | Purpose |
|---|---|---|
| `ProcessorInterface` | `packages/foundation/src/Log/Processor/ProcessorInterface.php` | `process(LogRecord): LogRecord` |

**New classes:**

| Class | Location | Purpose |
|---|---|---|
| `RequestIdProcessor` | `packages/foundation/src/Log/Processor/RequestIdProcessor.php` | Adds `request_id` to context (UUID per request) |
| `HostnameProcessor` | `packages/foundation/src/Log/Processor/HostnameProcessor.php` | Adds `hostname` to context |
| `MemoryUsageProcessor` | `packages/foundation/src/Log/Processor/MemoryUsageProcessor.php` | Adds `memory_peak_mb` to context |
| `ChannelLogger` | `packages/foundation/src/Log/ChannelLogger.php` | `LoggerInterface` impl scoped to a single channel. Created by `LogManager::channel()`. |

**Modified classes:**

| Class | Change |
|---|---|
| `LogManager` | Add `channel(string): LoggerInterface` returning `ChannelLogger`. Add global processor stack. |
| `HandlerInterface` | No change — processors are composed at the `LogManager`/`ChannelLogger` level, not inside handlers. |

**Config schema extension:**

```
logging:
  default: stack
  processors:                    # global processors
    - request_id
    - hostname
  channels:
    stack:
      type: stack
      channels: [errorlog, file]
    errorlog:
      type: errorlog
      level: warning
      formatter: text
    file:
      type: file
      path: storage/logs/waaseyaa.log
      level: debug
      formatter: json
      processors:                # per-channel processors
        - memory_usage
    security:
      type: file
      path: storage/logs/security.log
      level: info
      formatter: json
      processors:
        - request_id
```

### Migration Path

1. Create `ProcessorInterface`.
2. Create `RequestIdProcessor`, `HostnameProcessor`, `MemoryUsageProcessor`.
3. Create `ChannelLogger` — wraps handler + processor stack, sets channel name on records.
4. Update `LogManager::channel()` to return `ChannelLogger` instances (lazy-created, cached).
5. Update `LogManager` config parsing to read `processors` arrays (global and per-channel).
6. Remove deprecations from Phase B: delete `FileLogger`, `CompositeLogger`, old `ErrorLogHandler` location.

### Test Coverage

- `RequestIdProcessor`: adds `request_id` to context, value is non-empty string, same ID within a request.
- `HostnameProcessor`: adds `hostname` matching `gethostname()`.
- `MemoryUsageProcessor`: adds `memory_peak_mb` as a float.
- Processor pipeline: 2 processors run in order, both enrich context independently.
- Processor failure: broken processor logged via `error_log()`, record still delivered.
- `ChannelLogger`: sets `channel` on every `LogRecord`, delegates to handler after processors.
- `LogManager::channel('security')`: returns scoped logger, `channel('unknown')` returns default.
- Global + per-channel: global processor output is input to per-channel processor.
- Config: `processors` key parsed, processor instances resolved by name.

---

## Cross-Phase Notes

**Backward compatibility:** Each phase is independently shippable. Phase A works without B. Phase B works without C. No consumer code breaks between phases.

**Deprecation timeline:** Classes deprecated in Phase B (`FileLogger`, `CompositeLogger`, old `ErrorLogHandler`) are removed in Phase C.

**Package boundary:** All logging code stays in `packages/foundation`. No new package needed.

**Layer discipline:** Logging is Layer 0 (Foundation). No upward imports. Processors that need request context receive it via constructor injection, not by importing HTTP-layer code.
