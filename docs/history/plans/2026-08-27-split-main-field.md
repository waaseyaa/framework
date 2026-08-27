# Split-main field projection

Issue: #2605

## Decision

Add the existing `packages/field` to `waaseyaa/field` mapping to the narrow split-main allowlist. Keep unknown and path-shaped inputs fail-closed and preserve the workflow's no-release contract.

## Verification

The focused resolver test covers the complete Framework #2603 delivery cohort and the existing refusal tests continue to cover unapproved inputs.
