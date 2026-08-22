# FW-DEAD-CODE-CACHE-CUSTODY-v1 — exact-run dead-code result-cache custody

- Parent: `89ef71ac9822708589d0ce29cb32f1e0c7e805c5`
- Issue: `#2485`
- Authority: `check-dead-code` CI cache custody only

## Problem

The hosted `check-dead-code` job writes `tmp/phpstan-dead-code` under a key
that ends in `github.run_id`, but its broad `restore-keys` prefix omits that
run identifier. A different workflow run can therefore restore a PHPStan
result cache produced for older analyzed PHP whenever the analyzer provider,
configuration, and baseline are unchanged. Dead-code analysis is global, so
a source or PHPDoc change can invalidate the usage graph without changing any
of those configuration inputs.

This happened on commit `22ce3fc7c3dc8aa2fb1689b606872b0456ed4faa`:
hosted run evidence passed after restoring an older result cache, while a
clean local `php bin/check-dead-code` found eight new unused-member findings.

## Decision

Keep the existing analyzer-configuration hash and `github.run_id` exact key,
and remove the cross-run `restore-keys` fallback. A rerun of one workflow run
may restore only that run's immutable key; a different workflow run, including
one for a different analyzed head, must start with no dead-code result cache.

This is smaller and more fail-closed than maintaining a hand-enumerated source
hash universe. It also leaves Composer and ordinary PHPStan caches unchanged.

## Acceptance

- An Architecture test rejects any `restore-keys` entry in the
  `check-dead-code` job and requires restore/save to use the same key twice.
- The key contains the entrypoint-provider source, all three PHPStan config /
  baseline files, and `github.run_id`.
- A cold-cache run and an adversarial restored-cache reproduction agree on a
  known source change; stale cross-run evidence cannot be selected by CI.
- `php bin/check-dead-code`, full preflight, and exact-head hosted CI pass.

## Non-goals

No baseline additions, dead-code rule weakening, ordinary PHPStan cache
redesign, Composer cache changes, required-check settings, release, tag,
deployment, or downstream repository mutation.
