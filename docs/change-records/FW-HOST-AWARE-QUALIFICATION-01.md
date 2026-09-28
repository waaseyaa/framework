# Host-aware candidate qualification and material-input evidence

Status: bounded implementation candidate for Framework #3085 under delivery
program #2527.

## Problem

The local preflight had only pass/fail output. It attempted every selected gate
before representing host capability, and a second push of the same candidate
repeated the same successful gates. Operators had to interpret unavailable host
controls and retained evidence by hand. The admin-surface remediation measured
319.6 seconds for a full local preflight and then repeated the default pre-push
profile twice at 122.5 and 125.4 seconds on the same exact head.

## Contract

`tools/preflight-gates.json` schema 2 resolves these fields for every gate:

- supported hosts and required capabilities;
- owning hosted check;
- relevant path selectors and cost class;
- evidence inputs.

`bin/check-pr-preflight` resolves the native host and command capabilities before
launch. Each selected gate is reported as `passed`, `failed`,
`hosted-required`, or `not-applicable`. Hosted-required and not-applicable are
never printed as local passes. The ordinary command exits 3 while hosted proof
remains. The pre-push adapter may use `--allow-hosted-required` to publish the
candidate to that named owner, but cannot qualify it or weaken hosted checks.

A successful result may be reused only when its Git-private receipt matches the
complete selected path set and bytes, base where relevant, `composer.lock`,
toolchain and capability identity, profile, selector, evidence inputs, and
effective gate definition. The receipt separately retains the exact candidate
where the gate ran. Output distinguishes execution, exact-candidate reuse, and
cross-candidate reuse of equivalent material inputs while naming the original
tested identity. A definition or selected-byte change invalidates that gate
without discarding receipts for unaffected gates. Local receipts are not inputs
to hosted CI, branch protection, release-cut, tags, or publication.

The narrow selectors introduced here cover only gates whose complete material
boundary is explicit: changelog validators, CI inventory and roster checks,
code style, PHPStan, and dead-code analysis. All other gates retain the
conservative repository-wide selector.

`bin/start-hosted-qualification` adds the early hosted checkpoint. It accepts
only a clean HEAD that is the exact remote branch tip, then dispatches the full
`ci.yml` profile with that SHA. Dispatch acceptance is not reported as green
qualification and does not satisfy a release boundary.

The schema distinguishes `local` from `hosted-only` execution. The full profile
inventories `split-artifact-acceptance`, its five acceptance surfaces, all
sixteen seeded negative controls, Linux ownership, required capabilities, and
`ci/split-artifact-acceptance` without launching the multi-minute consumer
locally. The reserved stdio surface remains bound to #2659. The symlink control
explicitly requires the symlink capability and remains Linux-hosted evidence;
native Windows cannot turn its unavailable control into a pass.

`bin/qualify-candidate` treats preflight exit 3 as incomplete rather than as a
repository defect. It continues through supported Unit, Integration, and
Architecture evidence, but cannot emit `qualification: true` until hosted
ownership is resolved. A failed or malformed preflight still holds the suites.

## Verification boundary

`PreflightParityTest` discriminates manifest metadata, native host Bash custody,
missing capabilities, false-green hosted requirements, exact-identity reuse,
gate-specific invalidation, unmatched selectors, base precedence, and failure
repair output. `ProjectHooksTest` binds the pre-push adapter to the explicit
hosted-required option and wording.

This record covers the completed #3085 implementation. Local execution,
cross-candidate evidence reuse, hosted-only control classification, supported
suite continuation, and the early exact-SHA checkpoint are all executable.
Hosted and release boundaries remain unchanged.
