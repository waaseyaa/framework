# Alpha.309 consumer matrix with metadata workaround

Status: **all seven workaround profiles passed; standard Packagist qualification remains blocked**.

The exact qualification harness is `34ad0d1e80ea8930ed7df820202f96b697871d4e`.
The immutable release source is `76afb3f84491476e381d9d3f17df7bf71147a131`,
public tag `v0.1.0-alpha.309`. Historical alpha.308 source is
`baead2150a78883b82536282d1181cf5b7a92a10`. The native Ubuntu VM ran PHP 8.5.4
and Composer with a fresh isolated home/cache and no authentication overrides.
No app adoption, browser/login journey, deployment or production change occurred.

The two-package public metadata snapshot bypasses stale P2 discovery only.
Both metadata records use official GitHub archives at references checked against
the public tags and gated split provenance. The 41-member engagement closure
installs publishing; wayfinding is outside this closure and was not added as an
optional dependency. All installed Framework package bytes, manifests and
versions were verified against the corresponding tagged export before and after
fresh runtime probes. `split-reference-verification.json` additionally checks
all current-cohort installed references against the gated producer evidence.

Passed profiles:

- Fresh dev/no-dev in sql-blob and sql-column: 46 assertions across 11 routes
  each, four two-process race phases, default quota of 60 admitted then 429,
  and exact counts in the 50-root / 1,000-comment / ten-sample performance probe.
- Both real alpha.308-to-alpha.309 upgrade journeys: ten assertions each and
  zero-change replay. The historical column schema is explicitly synthetic;
  stock alpha.308 uses blob storage. Historical installs have no overrides.
- Fresh no-dev API without engagement: kernel boot, package absence and route
  omission checks passed.

The full runner exited zero. `qualification.json` retains the seven-profile
results, performance SQL and query plans, fixture identity, complete solved
package/third-party closure, and mode/snapshot identity for every provenance
record. Nine lock inputs deduplicate to three content-addressed Composer locks.
`assemble.py` is the exact evidence assembler; it requires explicit
`--allow-metadata-workaround` and refuses incomplete profiles. `runner.out`
retains the final exit-success marker. Raw per-step logs remain at
`/tmp/engagement-alpha309-workaround-matrix1`.

To reproduce, run the committed harness with a new external evidence directory:

```bash
COMPOSER_MAX_PARALLEL_HTTP=1 PYTHONDONTWRITEBYTECODE=1 \
TMPDIR=/home/russell/.cache/waaseyaa-engagement-qualification \
timeout 3600 bash tests/PackagedForm/check-engagement-consumer \
  --candidate=34ad0d1e80ea8930ed7df820202f96b697871d4e \
  --published-tag=v0.1.0-alpha.309 \
  --metadata-workaround=docs/change-records/evidence/engagement-alpha309-workaround/metadata-snapshots/8abed76e2294c0df58472231d91a6e0aa607d1d8702d33e85d3c95bc43fb86a5.json \
  --evidence-dir=/tmp/engagement-alpha309-workaround-reproduction --keep-work
```

This is **not ordinary Packagist publication proof**. The post-matrix standard
P2 recheck still lacked alpha.309 for both affected packages. The guarded release
announcement and ordinary published matrix remain pending. Local timing results
characterize this synthetic VM fixture and are not a production SLO. Retire this
bounded workaround after standard P2 qualification succeeds, as required by the
owning spec.

Independent review: Raman returned **PASS, no blocking findings**, binding the
exact harness and release source to all seven raw profiles. The review checked
13 current-cohort provenance records and 532 repeated package references,
before/after bytes, upgrade/replay and absence results, and explicit workaround
status. Main feedback `43633234047` passed on the landed harness. Focused
teardown tests passed 5 tests / 101 assertions, all source preflight gates
passed, and the final evidence-only preflight passed 41 applicable gates.
