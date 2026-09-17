# FW-DELIVERY-GUIDANCE-CONVERGENCE-01: platform-aware agent delivery guidance

Tracking issue: [#3076](https://github.com/waaseyaa/framework/issues/3076).

## Problem

The canonical agent contract required all Git commands to use `bin/git`, while
the native-host support contract correctly classified that adapter as POSIX-only
Bash. Native Windows work therefore had no instruction set it could obey in
full. General delivery guidance could also be read as permission to stop after
planning even when the user had already authorized the later delivery stages.

## Contract

- Supported POSIX hosts use `bin/git`.
- Native Windows hosts use the Windows Git executable selected by a
  higher-authority user or harness instruction.
- Every host retains the prohibition on `git stash` and the remote-first
  worktree sequence.
- End-to-end authorization carries work through every already-authorized stage.
  A material unresolved decision, blocker, or authority boundary is the reason
  to request user input.
- Independent review preserves a distinct perspective on an immutable
  candidate. It does not independently authorize parallel implementation or
  multi-agent delivery.

The companion local `waaseyaa-delivery` skill points volatile model selection
back to its routing authority and contains no Studio-only concurrency target.
Repository guidance remains the authority for repository mechanics.

## Validation

This documentation-only change requires resolved relative Markdown links,
changelog-fragment validation, and `git diff --check`. It changes no runtime,
package, CI, release, deployment, credential, or infrastructure behavior.
