---
name: waaseyaa-delivery
description: Deliver an authorized Waaseyaa change through implementation, proportionate verification, review and publication. Use for repository changes and delivery reconciliation, not a fresh package audit.
---

# Waaseyaa Delivery

Default to one agent using the current session and available tools. No model,
vendor, external runner or machine-specific routing file is required. Additional
agents need authorization under the active session and repository instructions.

Read the repository's `AGENTS.md`, applicable specs and local testing policy.
In Framework, `docs/governance/agent-contract.md` owns authorization and review
policy. This skill does not grant permission to publish, merge, release or
deploy. Carry forward the user's existing authorization without asking again
for routine steps inside that scope.

Load this repository source directly, following `.agents/skills/README.md`.
Do not edit a generated client copy.

## Deliver the change

1. **Establish scope.** Inspect the current code, relevant issue acceptance and
   worktree state. Name the outcome, owned files, candidate/base and focused
   verification. Use the repository's change record and release fragment where
   required. Preserve other work in isolated custody.
2. **Implement.** Make the smallest coherent change. Follow the repository's
   architecture and maintained-component policy. Reproduce a defect before its
   repair where practical; use document/link checks for ordinary prose changes.
   Accepted audit findings are inputs to delivery, not a reason to restart the
   entire audit.
3. **Verify proportionately.** Run affected checks and mandatory hooks. Reuse
   valid unchanged evidence. Record the exact candidate, commands, results and
   limitations; a fixture or local pass does not establish a real browser,
   container, published-package or hosted result.
4. **Review according to risk.** Apply the review policy below. Repair findings
   and refresh only affected evidence. Broaden review when the repair changes
   shared contracts or invalidates the earlier assessment.
5. **Publish and reconcile.** Complete the already-authorized delivery scope.
   Use the repository's permitted direct batch or PR workflow; neither implies
   permission to release or deploy. Verify the actual remote/PR head, checks and
   landing state. Update issue and project records only for demonstrated
   acceptance, leaving residual work explicit.

## Review policy

Routine documentation, presentation and narrow low-risk changes may use the
implementer's recorded self-review plus focused checks. Do not require a second
agent merely because work has a commit or PR. Classify risk by the affected
behavior, not file extensions: a policy document can change a security boundary.

High-risk changes require independent review before integration: authentication,
authorization, persistence or migration, execution, isolation, significant shared
architecture, or mandatory safeguards. Preserve any explicit
review requirement in an owning acceptance contract, including audit verification.

An independent reviewer is a person or separately authorized agent who did not
author the candidate. Give them the immutable diff, acceptance and existing
evidence; require findings and a verdict. They may use the same model or vendor.
Never label the implementer's second pass as independent review. If required
review cannot run, finish safe preparation and report that specific gate as
pending. A review requirement does not authorize parallel implementation.

## Verification and product boundaries

`docs/local-testing-policy.md` owns test scope, native-host limitations and
evidence reuse. Follow the selected repository profile; full qualification is
required when the owning acceptance or release contract calls for it, not for
every edit. Mandatory hooks and release gates remain enforced.

Framework owns reusable application semantics, validation and materialization.
Studio owns product presentation and composition. Exact Framework candidates may
support development; release qualification requires the intended published
cohort. Never hide a producer/consumer mismatch behind a private implementation.

## Read only when applicable

- Authorized parallel agents: [coordination and custody](references/parallel-delivery.md).
- Failing hosted checks: [CI repair](references/ci-repair.md).
- Unreleased Framework integration: [candidate consumer integration](references/candidate-consumer-integration.md).

Report what changed, what was verified, what was actually published or landed,
and any remaining acceptance. Do not report a partial slice as whole-issue
completion, agent review as human approval, or a merge as deployment.
