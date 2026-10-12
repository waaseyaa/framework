# Failure handling across packages

Apply to supported failure boundaries alongside the kernel, CLI, wire and
persistence profiles. This is an audit method, not a new runtime contract.
Supplement missing evidence in existing audits; preserve their original findings
and qualification limits instead of restarting them.

## Ownership and intended behavior

- Trace the real composition root through failure origin, exception conversion,
  transaction settlement, logging/redaction, rendering and the final caller
  outcome. Name each owner and competing or unreachable paths. A renderer unit
  test does not prove the application installs or invokes it.
- Separate generic mechanisms from required domain policy. Apply the checklist's
  maintained-infrastructure decision to exception capture, rendering, logging
  integration and process lifecycle. Compare supported Symfony versions using
  source/tests and official documentation. Record installation and no-dev impact;
  a dependency's presence does not prove adoption and absence does not justify
  reinvention. Consider established ecosystem components where Symfony delegates.
- Reconcile intended statuses, exit codes, wire envelopes, privacy and recovery
  semantics in their owning specs before remediation. Existing custom behavior
  or tests do not automatically establish a compatibility obligation. Retained
  custom machinery needs the evidenced gap, owner and review trigger required
  by the shared infrastructure policy. Avoid a universal error wrapper merely
  to make distinct protocols look alike.

## Boundary evidence

- Select applicable stages: pre-container/bootstrap, dependency construction,
  middleware/authorization, handler execution, serialization/output, post-commit
  completion and worker settlement. Distinguish expected refusals, unexpected
  failures, committed outcomes and uncertain outcomes. Do not imply rollback or
  safe retry when durable effects may already exist.
- Assert public status/body or process status/stdout/stderr, persisted effects,
  safe private diagnostics and recovery ownership together. Exercise real HTTP,
  CLI and protocol boundaries where claimed; mocks alone cannot qualify them.
- Check nested/hostile exception messages, harmless sensitive-looking symbols,
  actual secrets and failure inside logging/rendering. Preserve privacy without
  losing every useful diagnostic discriminator. Establish diagnostic ownership
  and intentional fan-out; detect accidental duplicate reporting, recursive
  failure and protocol contamination.
- Exercise applicable development/production, no-dev and supported host profiles,
  debug-policy refusal, repeated requests/jobs, interruption and restart. Scope
  these to reachable supported behavior; record exclusions with evidence.
- Verify unaffected success and refusal controls. For retries or partial writes,
  use the persistence profile and existing outcome contracts rather than inventing
  new guarantees. Record each control's exact candidate and evidence class.

## Closeout

Record the path/owner/spec/component decision and missing controls in the existing
audit record and ledger. Reuse existing defect owners. A pending design choice,
unexecuted probe or missing composition proof remains a gap, not a confirmed
runtime defect or completed assessment. After repair, retire superseded handlers,
adapters and documentation only after current consumers and obligations are
reconciled; preserve original evidence as historical evidence.
