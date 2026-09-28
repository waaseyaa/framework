# Host-aware candidate qualification and exact-identity evidence

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
exact candidate HEAD/tree and dirty byte manifest, base where relevant,
`composer.lock`, toolchain and capability identity, profile, selector, evidence
inputs, and effective gate definition. Output says whether the pass was executed
or reused. A definition change invalidates that gate without discarding receipts
for unchanged gates. Local receipts are not inputs to hosted CI, branch
protection, release-cut, tags, or publication.

## Verification boundary

`PreflightParityTest` discriminates manifest metadata, native host Bash custody,
missing capabilities, false-green hosted requirements, exact-identity reuse,
gate-specific invalidation, unmatched selectors, base precedence, and failure
repair output. `ProjectHooksTest` binds the pre-push adapter to the explicit
hosted-required option and wording.

This is the bounded first slice of #3085. It does not yet claim cross-commit
equivalence from relevant-path hashes, classify split-artifact controls inside
the preflight manifest, or implement an early-PR command. Those acceptance items
remain open on #3085 and the issue must not be closed by this slice.
