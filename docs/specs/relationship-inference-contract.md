# Relationship Inference Contract (v1.5)

## Scope
- Issue: `#166`
- Surface: deterministic relationship inference from ingested node text.
- Goal: infer review-safe candidate edges that map to existing relationship contract fields.

## Activation
- Inference is opt-in from `ingest:run` via `--infer-relationships`.
- Inference executes after mapping and before validation gates.

## Deterministic Inference Rules
- Compare sorted node-key pairs (`A < B`).
- Extract lowercase lexical tokens from `title + body`.
- Remove stopwords and tokens shorter than 3 chars.
- Infer edge only when overlap count >= 2 tokens.
- Skip pair when explicit relationship already exists for the pair.

## Inferred Edge Contract
Inferred edges must include:
- `key` (`<from>_to_<to>_related_to_inferred`)
- `relationship_type` (`related_to`)
- `from`
- `to`
- `status` (`0`, review-safe default)
- `start_date` (`null`)
- `end_date` (`null`)
- `source_ref` (`inference://text-overlap-v1#<key>`)
- `inference_confidence` (deterministic float)
- `inference_overlap_tokens` (sorted unique tokens)
- `inference_source` (`text_overlap_v1`)
- `inference_review_state` (`needs_review`)

## Diagnostics
- Emit `inference.relationship_inferred` diagnostics for each inferred edge.
- Diagnostics are sorted deterministically by location.

## Stability Rules
- Inferred edges are non-publishable by default (`status=0`).
- Inference must not override explicit relationships.
- Contract changes in v1.5 are additive only.
