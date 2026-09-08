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

One-time consumer bootstrap provisioning installs the authoring public key in
`config_manifest_signing.trust_keys` and selects the compiler-owned
`<project-root>/config/sync` directory with `WAASEYAA_CONFIG_SYNC_PATH` (or the
equivalent `config.sync_path` setting). The authoring host separately maps the
matching CFG-04 `signing_key` secret reference to its custody provider. Those
settings persist across requests. The per-request path passes the same answers
and decision receipt to `project:config:authorize`, then passes only its public
canonical output to `project:init`; it has no intermediate config copy,
manual-sign, or manual-import step.

The closed `waaseyaa.project_config_authorization` version 1 document embeds a
CFG-03 version 1 envelope. Its fixed initial scope, sequence, and signed
producer evidence bind the exact evaluated site-manifest and artifact-plan
digests. This wrapper does not change the CFG-03 envelope contract: ordinary
envelopes remain version 1 and ordinary `config:import` continues to reject a
committed or stale bundle sequence.

`project:init --config-authorization=... --json` preserves the existing
site-then-install process boundaries, derives the site identity from the actual
`site:init` JSON result, and invokes the booted consumer command
`project:config:activate`. That command independently re-parses the consumer's
committed `.waaseyaa/site.yaml` and runs the canonical blueprint compiler, so
the activation boundary does not accept site or plan digests asserted in CLI
arguments. The consumer resolves its own configuration
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
