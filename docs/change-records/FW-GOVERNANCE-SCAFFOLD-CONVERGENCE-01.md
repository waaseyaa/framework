# FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01

Status: implementation candidate, not qualified. Forge mirror: Framework #2848.

## Contract and boundary

Repair the manual stdout scaffolds through canonical blueprint emitters. ADR-025 D4 retains make:policy and scaffold:workflow as non-mutating commands. No filesystem publication, provider activation, configuration import, or apply authority is introduced by these handlers.

make:policy requires an explicit --entity and emits a class implementing the actual AccessPolicyInterface methods and PolicyAttribute. Explicit operation:permission grants use the shared AccessPolicyEmitter. Zero grants produce Neutral decisions: entity authorization denies when no policy allows. Field authorization has a different aggregation contract and is not described by that statement. An optional --entity-class selects the application entity class. Permission and entity identifiers here are authored input; generating output does not establish membership in an installed application's registries.

scaffold:workflow requires --id, --entity-type and --bundle. It emits a Workflow hydration array and an entity_type.bundle assignment. State and transition derivation lives once in WorkflowDefinitionEmitter::toDefinitionArray; the blueprint PHP serializer consumes that same value, only escaping and formatting it. Duplicate transition identifiers refuse rather than replacing a permission-bearing transition. Missing referenced states and an undeclared initial state refuse.

## Migration

Previously emitted policy methods did not satisfy their declared interface. Callers must now supply --entity; callers consuming the old workflow JSON must adopt the workflow hydration array and assignment map, and supply --entity-type. Existing defaults are still authored scaffold examples, not automatic permission registration. Generated output requires the supported application registration path before runtime use.

## Evidence

Existing blueprint golden output remains unchanged. Root focused verification after canonical mapping repair passed WorkflowDefinitionEmitterTest and WorkflowScaffoldHandlerTest: 16 tests, 52 assertions. Reflection verified both changed runtime classes loaded from this candidate worktree. Earlier duplicate-transition negative control failed before refusal and passed afterward (9 tests, 32 assertions). These observations are checkpoint evidence, not final qualification.

## Remaining acceptance and ownership

Root owns integration of this lane. The full #2848 outcome remains open until packaged application evidence proves supported policy/permission/role/workflow registration, allowed and denied principals, transitions and revision behavior, and rejection of unknown entity/permission/condition metadata through existing canonical admission before apply. Activation is coordinated with #2857 and existing governed blueprint/application services; no private manual apply engine may substitute. A shape-only test or an attribute in emitted text cannot prove registration.

Independent review and current-candidate qualification precede landing. No release, deployment, operator credentials, or production enablement is authorized by this record.
