# FW-WORKFLOW-VIS-01 — workflow candidate and served visibility

Status: implementing  
Anchor mirror: waaseyaa/framework#2569  
Parent: waaseyaa/framework#2435  
Parent candidate: `2c2a4fe3bedacf301eeb87ac1d58c4ee4d288552`

## Intent

Separate the two publication questions hidden behind the legacy workflow
visibility helper: whether a proposed workflow state is declared public, and
whether an entity's published serving projection is currently public.

## Decisions

1. Candidate-state visibility resolves `Workflow::getState($id)->published`.
   State machine names such as `published`, `live`, or `active` have no inherent
   visibility meaning.
2. Served visibility resolves the cast-aware materialized `status` projection.
   Under default-revision discipline that projection belongs to the published
   pointer, even while a working-copy tip carries a draft state.
3. The relationship visibility interface retains its legacy method names, but
   `WorkflowVisibilityFilter` is explicitly a served-projection adapter.
4. Ingestion validation is a candidate-data boundary. It resolves status,
   semantic publication requirements, and relationship endpoint publication
   from the supplied workflow declaration.
5. Persistence audit/repair is excluded and remains a sibling slice of #2435.

## Consumer inventory

- Served projection: AI vector indexing/search/warming, discovery primary
  entities, relationship traversal/discovery, and SSR relationship navigation.
- Candidate state: ingestion validation and SSR preview authorization/context,
  using the workflow bound to the entity type and bundle.
- Engine guards/transitions already resolve `WorkflowState::$published`
  directly and require no visibility-helper call.

## Invariants

- A `live` state declared `published: true` is a public candidate.
- A `published` state declared `published: false` is a private candidate.
- A draft working copy with a live published pointer remains publicly served.
- A literal-id comparison or a global workflow-state-over-status precedence
  rule cannot satisfy the negative controls.

## Verification evidence

- Focused visibility suites: 18 tests, 39 assertions.
- Affected workflow/AI/API/relationship/SSR/ingestion suites: 550 tests,
  1,386 assertions.
- Full preflight, exact candidate SHA, packaged-form evidence, split suites,
  and hosted checks will be recorded on the pull request.
