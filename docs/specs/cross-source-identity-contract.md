# Cross-Source Identity Contract (v1.6)

## Scope
- Issue: `#175`
- Surface: deterministic canonical identity binding across normalized source rows.
- Goal: resolve cross-source collisions into a replay-safe canonical identifier with auditable diagnostics.

## Inputs
- Normalized connector rows that include:
  - `source_id`
  - `source_uri`
  - `ownership`

## Resolution Rules
1. Build deterministic identity fingerprint from normalized URI host+path.
2. Group rows by fingerprint.
3. For each group, choose canonical row by ownership priority:
   - `first_party`
   - `federated`
   - `third_party`
4. Tie-break with lexicographic `source_id`.
5. Derive `canonical_id = sha256(fingerprint)`.
6. Attach `canonical_id` to every member row.

## Diagnostics
- `identity.canonical_binding`: emitted for single-row groups.
- `identity.collision_resolved`: emitted for multi-row groups.
- Context fields:
  - `canonical_id`
  - `canonical_source_id`
  - `member_source_ids` (sorted lexicographically)

## Determinism
- Group keys sorted lexicographically.
- Member ordering within a group is deterministic.
- Final row order sorted by `canonical_id`, then `source_id`.
- Diagnostics are emitted once per canonical group in deterministic order.
