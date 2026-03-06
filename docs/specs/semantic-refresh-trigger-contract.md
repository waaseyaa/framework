# Semantic Refresh Trigger Contract (v1.6)

## Scope
- Issue: `#168`, `#177`
- Surface: deterministic semantic refresh trigger classification from ingestion events.
- Goal: emit stable `refresh.*` diagnostics and summary metadata with precedence-based trigger selection.

## Canonical Categories
1. `provenance_change`
2. `relationship_change`
3. `policy_change`
4. `structural_drift`

Lower-precedence categories are evaluated only when no higher-precedence category mandates refresh.

## Trigger Codes
- `refresh.provenance_change`
- `refresh.relationship_change`
- `refresh.policy_change`
- `refresh.structural_drift`

## Diagnostic Envelope
Each trigger diagnostic uses:
- `code`
- `message`
- `location`
- `item_index`
- `context`

## Context Payload Shapes
### `refresh.provenance_change`
- `changed_fields` (list<string>)
- `old_values` (list<scalar|null>)
- `new_values` (list<scalar|null>)
- `item_index` (int|null)
- `reason` (string)

### `refresh.relationship_change`
- `edge_type` (string)
- `source` (string)
- `target` (string)
- `change` (`added|removed|confidence_shift`)
- `confidence_before` (float)
- `confidence_after` (float)
- Note: v1.6 includes federated binding deltas under `edge_type=source_binding` with deterministic source/member projections.

### `refresh.policy_change`
- `policy_before` (string)
- `policy_after` (string)
- `reason` (string)
- Note: v1.6 includes merge policy deltas in `policy_before` / `policy_after`.

### `refresh.structural_drift`
- `field` (string)
- `drift_type` (`added|removed|type_changed|count_changed`)
- `details` (string)
- `item_index` (int|null)

## Summary Contract
- `diagnostics.refresh_summary`:
  - `needs_refresh` (bool)
  - `primary_category` (string|null)
  - `trigger_count` (int)
  - `categories` (list<string>)

## Baseline + Snapshot Contract
- `ingest:run --refresh-baseline=<path>` loads prior snapshot for diffing.
- `ingest:run --refresh-snapshot-output=<path>` writes canonical current snapshot.
- Snapshot sections:
  - `envelope` (batch + source_set + items provenance)
  - `policy` (ingestion policy + infer toggle)
  - `merge` (source-priority policy + conflict count)
  - `identity` (canonical bindings + member source IDs)
  - `relationships` (sorted canonical rows)
  - `structure` (item/node/relationship counts + field type contract)

## Stability Rules
- Category precedence and payload shapes are fixed for v1.6.
- Changes are additive only; no renames/removals during v1.6.
