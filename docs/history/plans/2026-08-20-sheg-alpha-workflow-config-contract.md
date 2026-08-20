# Sheg alpha workflow configuration contract

**Change record:** `SHEG-ALPHA-FW-CFG-WORKFLOW-01`
**Forge mirror:** framework issue #2458
**Parent:** `cf4bd663ae5fa96b11683e48fdd487b6214ee192`
**Status:** implementation authorized; merge, release, tag, and deployment are not authorized

## Problem

`WorkflowBindingResolver` reads `workflows.assignments` only from the active
CFG-02 generation. A fresh `install:init` correctly creates a content-free
genesis generation, but `waaseyaa/workflows` declares neither a CFG-03 package
contract nor a schema for the assignment document. A consumer therefore cannot
author and activate the configuration that makes the shipped workflow engine
reachable.

The Sheguiandah alpha acceptance run demonstrates the result: the default
`editorial` workflow entity exists, but the Admin schema reports
`x-workflow.bound=false` and the transition endpoint returns an empty set.

## Design

1. `waaseyaa/workflows` owns schema ID `workflows.assignments`, version 1, under
   configuration-contract version 1.
2. The v1 document is a closed object whose dynamic assignment keys have string
   values. A package-owned semantic validator then enforces canonical binding
   and workflow IDs plus the existing entity-type/revisionability constraints.
   Semantic validation is registered and frozen with the schema registry and
   runs before authored/effective content identity is accepted. The workflow
   resolver retains the runtime workflow-existence defense.
3. `WorkflowServiceProvider::boot()` registers the schema on the one shared
   `ConfigSchemaRegistry` before it performs dispatcher-dependent engine wiring.
   Registration is optional only when the host does not install the
   configuration authority; it is never backed by a private registry.
4. `packages/workflows/composer.json` declares the matching discoverable
   package contract and names `WorkflowServiceProvider` as schema provider.
5. Genesis remains content-free. This candidate adds no implicit import,
   boot-time write, unsigned path, key material, or environment-based override.

## Retained-red proof

- The workflow package manifest is discoverable as a configuration-contract
  owner.
- Provider boot places `workflows.assignments@1` in the shared registry.
- A correctly bound strict v1 sync entry validates and passes installed-package
  compatibility.
- Non-string values, wrong owner, wrong contract version, and unknown schema
  remain refused.
- Provider boot without a configuration registry remains safe and does not
  manufacture another authority.

## Work packages

1. Retain failing unit and package-discovery tests for the absent contract.
2. Add the schema owner, provider registration, and manifest declaration.
3. Add a strict sync-entry compatibility test and update the enduring workflow
   and configuration contracts.
4. Run focused tests, split suites, architecture/preflight gates, and an exact
   packaged-consumer proof before publishing a review candidate.

## Deferred boundary

Consumer-specific assignment bytes, manifest signing, trust-key custody,
activation request IDs, and post-activation backfill belong to the Sheg
application/rehearsal package. This Framework candidate supplies the missing
schema and compatibility authority only.
