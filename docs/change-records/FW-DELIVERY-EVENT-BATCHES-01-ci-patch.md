# Codex CI / preflight integration patch — FW-DELIVERY-EVENT-BATCHES-01

**Deferred.** Do not apply until the batch contract is accepted and the
implementation PR’s validator exists.

## Intended shared-file edits (exact list for later)

1. `tools/preflight-gates.json` — extend `check-delivery-agent-events` / add a
   batch-aware gate id only if the CLI surface changes; keep roster parity with CI.
2. `.github/workflows/ci.yml` — same command line as preflight for
   verify-gates / delivery-agent checks; no per-commit jobs.
3. Optional shared guidance pointer in `docs/specs/delivery-telemetry.md` once
   LIVE (Codex or implementation owner after design accept).

## Explicitly out of the design PR

No edits to the files above in the design-review candidate.

## Note

Wave rule: one integration owner for shared files. Supply the concrete diff in
the implementation follow-up once CLI flags and paths are stable.
