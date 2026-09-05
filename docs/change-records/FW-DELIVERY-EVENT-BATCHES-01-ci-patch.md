# Codex CI / preflight integration decision — FW-DELIVERY-EVENT-BATCHES-01

**Decision:** no command-line wiring change is required for the batch-aware
validator. The existing CLI already loads the freeze manifest, batch schema,
and batch files in each governed mode; the roster and workflow commands remain
the shared integration surface.

## Existing command paths

- Local preflight: `bin/check-pr-preflight --base=<ref>` expands the roster's
  `tools/preflight-gates.json` entry to
  `php bin/check-delivery-agent-events --branch-base=<ref>`.
- Pull-request CI: `.github/workflows/ci.yml` runs
  `php bin/check-delivery-agent-events --base=<event base> --candidate=<exact
  SHA> --head=<PR head SHA>`.
- Push CI: the same workflow runs
  `php bin/check-delivery-agent-events --base=<event before>
  --candidate=<exact SHA>`.
- Dispatched exact-candidate CI: it derives the candidate's first parent and
  runs the same `--base=<parent> --candidate=<exact SHA>` form.

These paths preserve the existing append-only, ancestry, and ordered-parent
checks. No second batch gate, per-commit job, or CLI flag is authorized by
this handoff.

## Qualification boundary

The validator and batch authority files are candidate implementation. Operator
batch publication remains pending until the accepted freeze, complete-set
validator, and the #2869 batch-aware projector are qualified together. See
`docs/change-records/FW-DELIVERY-BATCH-PROJECTION-01.md` for the projector
handoff; this record does not claim that cutover has occurred.

## Ownership

Codex owns this shared-file decision and roster/workflow parity. #2902 owns the
validator, freeze manifest, batch schema, and batch files. #2869 owns projector
and projection-identity qualification. Any later command or roster change
requires a reviewed CLI-surface change and an explicit handoff.
