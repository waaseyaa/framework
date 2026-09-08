# FW-PROJECT-CONFIG-ACTIVATION-01 — signed generated configuration activation

- **Issue:** [#3037](https://github.com/waaseyaa/framework/issues/3037)
- **Parent journey:** [#2664](https://github.com/waaseyaa/framework/issues/2664),
  [#2787](https://github.com/waaseyaa/framework/issues/2787)
- **Status:** implemented on a local candidate; independent review, merge,
  release, and deployment are not claimed by this record.

## Decision

The opt-in fresh-project composition accepts a signed project configuration
authorization produced before `project:init`. An authoring host with existing
CFG-04 custody runs `project:config:authorize` against the same answer and
decision-receipt documents. The command parses and compiles them through the
canonical site-manifest and application-blueprint authorities, stages only the
plan-owned `config/sync/*` bytes in a private temporary directory, and invokes
the existing `ConfigManifestBundleSigner`. No signing key or secret provider is
placed in the generated consumer.

The closed `waaseyaa.project_config_authorization` version 1 document embeds a
CFG-03 version 1 envelope. Its fixed initial scope, sequence, and signed
producer evidence bind the exact evaluated site-manifest and artifact-plan
digests. This wrapper does not change the CFG-03 envelope contract: ordinary
envelopes remain version 1 and ordinary `config:import` continues to reject a
committed or stale bundle sequence.

`project:init --config-authorization=... --json` preserves the existing
site-then-install process boundaries, derives the site identity from the actual
`site:init` JSON result, and invokes the booted consumer command
`project:config:activate`. The consumer resolves its own configuration
authority and sync path, verifies signature, trust, schema, installed package
cohort, complete current sync bytes, and dependencies, then permits activation
only from its exact empty canonical genesis generation. Existing applications
require a separate migration design and are refused explicitly.

The activation request identity is derived from the consumer authority and
the signed manifest identity. A retry with a committed request takes a
separate exact-reconciliation path: signature and complete current sync bytes
are verified again, envelope sequence must equal stored committed replay state,
and the current token and effective generation must equal the committed
result. This does not weaken ordinary replay protection.

The opt-in result is `waaseyaa.project_init_result` version 2 with site,
install, and activation phases. A failure after site publication reports those
artifacts as committed through the succeeded site phase. A process failure
whose activation commit cannot be observed reports `uncertain` and tells the
caller to retry the same request. The composition never claims to roll back
published site artifacts or calls configuration rollback.

