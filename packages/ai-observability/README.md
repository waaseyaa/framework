# waaseyaa/ai-observability

Cost tracking, budget enforcement, outcome logging, and anomaly detection for the Waaseyaa agentic framework, plus a (currently dormant) trace recorder.

Layer 5 (AI). Zero dependencies on other AI packages.

The **cost / outcome / metrics** path is wired and live: `AgentRunTelemetryListener` (subscribed in `AgentTelemetryServiceProvider::boot()`) consumes the `AgentRun*` lifecycle events that `ai-agent` fires (`AgentExecutor`, `RunAgentHandler`) and feeds metrics + Telescope; `LlmCallListener` feeds the cost accountant.

> **Trace recording is not active yet.** `TraceRecorderInterface` is bound to `TraceRecorder` (and `NullTraceRecorder` when disabled), but **no production code calls `TraceRecorder::startTrace()`** — nothing opens a trace, so the trace engine is dormant. This is a known functional gap tracked in [#1743](https://github.com/waaseyaa/framework/issues/1743) for a post-beta pass; it does not affect the cost/outcome telemetry above.

See the design at `docs/history/superpowers/specs/2026-04-14-ai-observability-design.md`.
