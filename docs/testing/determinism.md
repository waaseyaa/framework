# Deterministic test policy

Waaseyaa's primary PHP suite runs in the canonical configuration order. A
second CI lane challenges order coupling on every change and prints the seed
before PHPUnit starts.

Run a fresh local order:

```bash
composer test:random
```

Replay a reported order exactly:

```bash
TEST_RANDOM_SEED=2241 composer test:random
```

The runner prepends the active `PHP_BINARY` directory to `PATH`. Subprocess
tests therefore use the same interpreter as PHPUnit and do not depend on a
developer shell or CI setup action to make `php` discoverable.

## Machine-readable classification

`composer test:inventory` emits the determinism inventory under the
`determinism` JSON key. The architecture suite fails when a sleep or
conditional skip is unclassified, or when a conditional skip represents a
framework gap. Categories can overlap because one test file can exercise more
than one boundary.

The baseline after the first random-order remediation is:

| Signal | Classification | Files |
|---|---|---:|
| randomness | cryptographic entropy | 46 |
| randomness | bounded selection | 7 |
| randomness | fixture uniqueness | 139 |
| waits | subprocess polling | 2 |
| waits | filesystem retry | 1 |
| waits | unclassified | 0 |
| conditional skips | environment capability | 20 |
| conditional skips | optional dependency | 2 |
| conditional skips | fixture or provenance availability | 10 |
| conditional skips | framework gap | 0 |
| conditional skips | unclassified | 0 |
| state boundaries | wall clock | 41 |
| state boundaries | process globals | 45 |
| state boundaries | filesystem | 220 |
| state boundaries | database | 373 |
| state boundaries | subprocess | 42 |
| state boundaries | network or port | 5 |

Random bytes used for credentials, opaque identifiers, and isolated fixture
paths are inputs, not behavioral assertions. Tests that use random selection
to vary behavior must accept a seed and print it on failure. Prefer explicit
examples when the set is small.

Wall-clock sleeps are forbidden when the state can be controlled directly.
The three retained waits poll observable subprocess completion or retry a
filesystem operation with a deadline and bounded delay. Adding another wait
requires adding its path and category to `bin/test-quality-inventory`.

Conditional skips must describe a real environment capability, optional
dependency, or unavailable external fixture/provenance input. A missing
framework capability is a blocker, not an acceptable skip. Permanently skipped
placeholder tests are removed; production-shaped coverage should live in the
suite that can execute it.

Tests that change sessions, environment variables, INI values, static caches,
temporary files, databases, subprocesses, or ports own their cleanup. Restore
process globals in `tearDown()` or `finally`, allocate filesystem and database
state per test, use operating-system-assigned ports, and enforce deadlines on
all polling loops. Parallel execution remains blocked until these boundaries
have explicit isolation evidence.
