# Local test and evidence policy

This policy governs local implementation, review, and integration testing.
It does not waive repository hooks, required hosted checks, independent review,
or explicit acceptance criteria. A green broad suite is not a substitute for
testing the boundary named by an acceptance criterion.

## Plan before execution

Each work package must name:
- the behavior and failure mode being changed;
- the focused commands and affected caller/contract tests;
- the independent review owner and final qualification owner;
- the conditions that would require broader testing;
- any shared host resources needed.

Do not start with a full-suite baseline by default. Use existing valid evidence
or a focused baseline. If broad discovery is necessary because impact is
unknown, state that uncertainty and choose the smallest suite that resolves it.

## Stages

| Stage | Default verification |
| --- | --- |
| Implementation | Reproduce the defect, then run changed-component tests and directly affected callers. |
| Independent review | Review the immutable diff and write independent failure discriminators; exercise affected contracts. Reuse valid implementation results for unchanged checks. |
| Integration | One appropriately scoped combined qualification of interacting changes, owned by the integrator. |
| Hosted CI | All required checks on the final published head. Local evidence does not replace them. |

After a repair, review the new delta and affected behavior. Do not restart the
entire review or full local suite merely because a commit ID changed.
Documentation-only changes require document/link consistency checks. Changes to
generated governance inventories require the relevant inventory validator.
Test changes require their affected tests and a failure discriminator when
they claim to detect a regression.

## When to broaden

Broaden when there is a concrete reason: shared bootstrap/autoload/dependency
changes; schema, migration, transaction, or authorization changes affecting
multiple callers; a meaningful integration/base conflict; an unexplained
failure; an explicit acceptance requirement; or evidence invalidated by changed
inputs. Select affected suites first. A full local suite is appropriate when
the impact crosses those boundaries or cannot be bounded.

Record the reason before an optional broad run. Do not rerun an unchanged broad
suite solely for reassurance, a new reviewer, or a documentation amendment.
Required hooks and hosted checks still execute normally; never skip or weaken
them to satisfy this policy.

## Evidence reuse

Record candidate SHA (or exact dirty-file manifest), base, relevant dependency
cohort/lock identity, command, runtime/environment, exit status, counts/skips,
elapsed time, and log location. For image tests, record source identity, image
digest, configuration identity, and the stages actually executed. Never record
secrets.

When carrying evidence forward, identify the original tested SHA, new SHA, and
inspected delta; explain why relevant source, tests, dependencies, configuration,
and execution conditions remain equivalent. Retained evidence is not a claim
that tests ran on the new head. Invalidate only affected evidence, but do not
infer equivalence from source filenames alone.

## Choose the boundary that answers the question

- In-process unit tests establish component behavior.
- Real SQLite transactions establish persistence/atomicity behavior.
- Separate processes establish process restart behavior.
- Real containers establish executor, mount, image, and container boundaries.
- Rendered browser tests establish cookie, navigation, and browser behavior.

Label test doubles explicitly. A test-port process restart is not a Docker kill
test; direct HTTP/PHP calls are not a rendered-browser journey. A full PHP suite
cannot fill either gap.

For image repairs, qualify the failing stage first where the harness supports
it, then execute the required complete journey on the final candidate. Rebuild
when image-baked inputs change; preserve image identity and prior evidence.
Do not normalize fingerprints or relax acceptance to avoid a rebuild.

## Shared host discipline

The integration owner coordinates one heavy local operation at a time per host:
full suites, dependency installations, and image builds/real Docker qualification.
Record the operation owner and release the slot when complete. Focused tests
may run concurrently in isolated lanes when resource use permits. Hosted jobs
are independent of this local slot.

New or changed subprocess harnesses must have a bounded deadline, safe output
draining, and reliable child cleanup; preserve the original failure if cleanup
also fails. Record existing limitations rather than silently expanding the
scope to repair every older harness.

Capture durations for heavy runs. Investigate measured slow tests, repeated
bootstrapping/installation, and fixed waits before introducing caches or
parallelism. Do not trade isolation or failure detection for speed.

## Handoff template

- Candidate/base and dependency or image identity:
- Changed behavior and affected boundaries:
- Commands, results, elapsed time, and logs:
- Reused evidence and equivalence justification:
- Independent discriminator and result:
- Broader runs performed or deferred, with reason:
- Final qualification owner and remaining acceptance:
