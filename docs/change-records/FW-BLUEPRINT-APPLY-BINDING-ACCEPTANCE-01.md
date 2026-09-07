# FW-BLUEPRINT-APPLY-BINDING-ACCEPTANCE-01

Status: blocked acceptance candidate

Anchor mirror: waaseyaa/framework#2787

Base: `9c14c7eedf41c1678b27da24cc92089fb8cb8cd6`

## Intent

Prove that a governed application blueprint reviewed by `site:init --dry-run`
can be applied in a later real CLI process without recompilation. The later
invocation must carry three distinct authorities unchanged:

1. the complete canonical artifact plan and its `plan_digest`;
2. the evaluated current `project_state_digest`; and
3. the separate approved `BlueprintDecisionReceipt` matching the plan's
   embedded manifest.

The decision receipt is deliberately not a member of
`waaseyaa.artifact_apply_request`. Treating receipt data embedded in an apply
request as approval would let an untrusted transport mint its own authority.

## Current source evidence

- `SiteInitHandler` supplies a decision receipt to blueprint evaluation and
  returns the complete plan plus both digests.
- `ArtifactApplyRequest` carries the plan and the two reviewed digests without
  recompilation.
- `SiteInitializationService::apply()` accepts a separate
  `BlueprintDecisionReceipt`, validates it against the manifest transported
  inside the plan, and records its digest in the change receipt and generated
  evidence.
- `SiteApplyHandlerTest` already proves, for blueprint-free plans, canonical
  request-byte decoding, current-state staleness, plan-digest tampering,
  `GEN005_STALE_PLAN`, and zero-write refusal. Those guards are not duplicated
  here.
- `SiteBlueprintProcessTest` proves direct `site:init --yes` blueprint apply,
  but it does not transport the reviewed plan through the later `site:apply`
  process.

## Discriminating RED

`BlueprintApplyBindingProcessTest` uses the actual console twice. It obtains a
blueprint plan from approved dry-run, creates the canonical apply request from
only the returned plan and two digests, then invokes `site:apply` with that
request and the same separate receipt. It expects the exact plan and state
digests in the result, the receipt digest in the terminal change receipt, and
the canonical receipt in generated ownership evidence.

At the recorded base the test fails before execution because
`SiteServiceProvider::siteApplyCommand()` does not register
`--decision-receipt`. `SiteApplyHandler` consequently cannot read a receipt and
calls `SiteInitializationService::apply($request)` with `null` approval. Calling
the command without the unknown option instead reaches the engine and is
correctly refused as `GEN011_UNAUTHORIZED_SET_DELTA`. Therefore the later
process cannot currently apply any blueprint plan.

Focused actual-CLI command at the recorded base:

```text
php -d memory_limit=1G ./vendor/bin/phpunit packages/cli/tests/Integration/BlueprintApplyBindingProcessTest.php --no-coverage
```

Observed result: `Tests: 1, Assertions: 9, Failures: 1`. The single failure is
`The "--decision-receipt" option does not exist.` followed by
`Failed asserting that 2 is identical to 0.` The nine preceding assertions
show that the real `site:init --dry-run` process returned a canonical reviewed
plan, preserved the exact plan and current-project-state digests in the apply
request, and made no target-project write before the later process reached the
missing option.

## Smallest canonical repair

Register the same required `decision-receipt` option on `site:apply` that
`site:init` uses. Move the closed, read-once receipt decoding/path-resolution
logic to one shared CLI helper or otherwise reuse it without changing its
validation contract. Pass the decoded receipt to
`SiteInitializationService::apply(decisionReceipt: $receipt)`; do not add the
receipt to `ArtifactApplyRequest`, infer approval from generated content, or
recompile the blueprint.

The repair should add focused handler rivals for missing, malformed, rejected,
mismatched and replaced receipt input, plus blueprint-specific plan-digest and
project-state `GEN005` no-write controls after valid approval admission. The
existing generic `SiteApplyHandlerTest` remains the authority for equivalent
blueprint-free request guards.

## Shared-file repair ownership

The implementation owner will need these existing shared files:

- `packages/cli/src/Provider/SiteServiceProvider.php`
- `packages/cli/src/Handler/SiteApplyHandler.php`
- one shared receipt-input helper if extraction is chosen
- `packages/cli/tests/Unit/Handler/SiteApplyHandlerTest.php`
- `docs/specs/cli-kernel.md`
- `docs/specs/site-golden-path.md`

This acceptance lane owns none of those files and makes no runtime or spec
change.

## Residual boundary

Passing this test will prove source-cohort blueprint apply binding. It will not
prove a published package cohort, the #2664 supported-upgrade lifecycle, or the
supported host matrix. Those remain separate packaged acceptance obligations.
