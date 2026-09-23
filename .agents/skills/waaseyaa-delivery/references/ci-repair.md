# Exact-head CI repair loop

Use this reference when a Waaseyaa pull request is already under governed delivery and hosted GitHub Actions reports failures. Keep the repair inside the existing issue, branch, worktree, and pull request unless the failure is unrelated or requires a separately owned program.

## Establish the current candidate

Record and compare:

- the owned worktree path, branch, status, and local `HEAD`;
- the remote branch head;
- the pull-request head SHA;
- the newest workflow run for that SHA;
- the governed auto-merge method and pinned head, when present.

Do not diagnose a superseded run as current. Cancellation or skipped descendants from an older SHA are historical unless they reveal an independently reproducible infrastructure defect.

## Reduce statuses to root failures

Start from failed execution jobs. Map matrix leaves and aggregates such as unit-test, coverage, random-order, and shard summaries to the underlying failing test methods or commands. Report each root once and identify derivative red statuses separately.

When ordinary and random-order lanes report the same method, that is one observed failure with duplicated execution evidence. Preserve random-order context if order changes the reproduction, seed, or result.

If normal log retrieval is unavailable while a workflow is still running, fetch the completed job directly:

```text
gh api "repos/<owner>/<repo>/actions/jobs/<job_id>/logs"
```

## Repair proportionately

For each coherent root cause:

1. Decide whether it is a candidate regression, a flawed assertion around a valid invariant, an unrelated pre-existing failure, or infrastructure.
2. Preserve the invariant. Repair product code, generated authority, fixture, or check implementation at the narrowest correct seam.
3. Run the failing test or command and the closest discriminating refusal or negative control.
4. Refresh only generated evidence whose material inputs changed.
5. Commit and push one coherent candidate update.
6. Revalidate all three SHAs and refresh any exact-head auto-merge pin through the governed mechanism.

A repair can reveal another candidate defect in hosted CI. Continue this loop on the new exact head. Re-review only the changed area and affected boundaries unless the repair invalidates the complete prior assessment.

Generated authorities may require a clean tree or bind evidence to a commit. In that case, make the smallest reviewable commit before running the canonical generator or final verifier. If generation changes tracked files, commit those outputs and verify again on the resulting clean exact head.

## Report evidence precisely

Separate:

- focused local checks that passed;
- broader checks already valid for unchanged inputs;
- checks unavailable on the local host;
- checks explicitly owned by hosted Linux, Windows, browser, split-package, or consumer jobs;
- expected skips;
- unresolved root failures.

Never convert `hosted-required`, unavailable, skipped, or derivative success into a local pass claim.

## Reconcile the landing

After hosted checks and governed landing, verify and report:

- final pull-request head;
- merge state and merge commit;
- check totals, expected skips, and genuine blockers;
- clean worktree status;
- owning issue acceptance and closure state;
- residual issue links and project state;
- whether a release or deployment occurred.

A merged pull request does not imply a release or deployment.
