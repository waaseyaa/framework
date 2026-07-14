# waaseyaa/ai-pipeline

**Layer 5 — AI**

Configuration entity for describing ordered AI processing steps.

R19 removed the unused execution and queue-dispatch stack. This package does
not execute pipelines; consumers must use the live `ai-agent` and `ai-vector`
services for model invocation and embeddings.

Key classes: `Pipeline`, `PipelineStepConfig`.
