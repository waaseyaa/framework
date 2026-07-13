# waaseyaa/ai-observability

Cost tracking, budget enforcement, outcome logging, and anomaly detection for the Waaseyaa agentic framework, plus an explicit trace-recorder API.

Layer 5 (AI). Zero dependencies on other AI packages.

The **cost / outcome / metrics** path is wired and live: `AgentRunTelemetryListener` (subscribed in `AgentTelemetryServiceProvider::boot()`) consumes the `AgentRun*` lifecycle events that the production `AgentExecutor` and `RunAgentHandler` factories dispatch, then feeds metrics + Telescope.

`TraceRecorderInterface` remains available for callers that explicitly open and close traces. The former automatic `LlmCallCompleted` / `ToolCallStarted` / `ToolCallCompleted` listener chain was removed because no production producer ever constructed those events; automatic run telemetry uses the canonical `AgentRun*` events instead.

See the design at `docs/history/superpowers/specs/2026-04-14-ai-observability-design.md`.
