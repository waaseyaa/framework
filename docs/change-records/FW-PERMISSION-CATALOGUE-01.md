# Framework permission catalogue convergence

Status: implementation candidate for Framework #3119.

## Problem

The alpha.301 kernel correctly refuses provider-declared roles whose grants are
absent from the composed permission catalogue, but the node, media, menu,
taxonomy, workflows, and optional content-search features did not contribute
the permission ids their own policies and tools consume. A real downstream
application therefore failed before CFG-03 manifest signing.

The retained Sheguiandah reproduction names 62 unique missing grants. Ten are
application-owned. Six are fixed Framework ids. The remaining 46 are concrete
node-bundle, media-type, vocabulary, and workflow-transition permissions.

## Decisions

1. Fixed policy permissions have one installed package-manifest owner.
2. Node, media, taxonomy, and workflows own pure public permission-family
   helpers for application-defined subjects.
3. An application supplies canonical subject ids or workflow definitions and
   derives catalogue definitions and role grants through those helpers.
4. Catalogue composition remains after provider registration and before
   provider boot. Contributions perform no writes or network discovery.
5. `waaseyaa/ai-tools` contributes `tool.content.search` only when the optional
   search adapter is installed, reusing the descriptor in `AgentCapabilities`.
6. Unknown, malformed, and duplicate permissions continue to fail closed.
7. Node's consumption of the workflows-owned editorial publish permission and
   taxonomy's consumption of node-owned `access content` are retained as
   documented cross-package couplings for convergence under #3118; this repair
   does not introduce a dependency rewrite.

## Package ownership

| Package | Fixed declarations | Dynamic family |
| --- | --- | --- |
| node | `administer nodes`, `access content`, `view own unpublished content` | create/edit-any/edit-own/delete-any/delete-own per bundle |
| media | `administer media`, `access media`, `view own unpublished media` | create/edit-any/edit-own/delete-any/delete-own per media type |
| menu | `administer menu` | none |
| taxonomy | `administer taxonomy` | create/edit/delete terms per vocabulary |
| workflows | seven shipped editorial transition permissions | explicit or derived permission per application workflow transition |
| ai-tools | provider-owned `tool.content.search` when Search is available | none |

## Verification boundary

- Unit tests pin every permission grammar, deterministic order, complete family,
  malformed-subject refusal, and workflow collision refusal.
- An installed-manifest test reads the actual package Composer declarations,
  proves one owner per fixed id, and checks the shipped editorial definitions
  against `DefaultWorkflows` through `WorkflowPermissions`.
- A real kernel test composes installed fixed definitions with one application
  provider's dynamic families, validates a representative least-privilege role,
  and retains the pre-existing unknown-grant refusal.
- Existing node, media, menu, taxonomy, and workflow policy tests remain the
  enforcement regression boundary after policies switch to the canonical ids.

## Residual acceptance

Framework merge does not qualify or release a consumer cohort. Sheguiandah #298
must adopt a published fixed cohort, contribute its application-owned and
concrete definitions through these helpers, and repeat installation, CFG-03
signing, editorial, member, and privacy journeys. Framework #3118 retains the
broader package-catalogue and cross-package coupling audit.
