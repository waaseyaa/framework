# Framework and builder product boundaries

Decision date: 2026-09-16. Accepted architectural direction, not a claim of
complete downstream acceptance. Record: `WAASEYAA-BUILDER-DIRECTION-01`.

## Product relationship

Waaseyaa Framework supplies reusable capabilities for websites and applications.
Waaseyaa Studio is the open-source builder distribution that composes those
capabilities. Russell's commercial builder will use that distribution under a
separate, undecided brand. Other operators must be able to create their own
branded builders on the same base. Studio is the provisional OSS name; this
decision changes no license.

The commercial SaaS working repository is `jonesrussell/waaseyaa-cloud`.
Intersnipe is a separate AI-first domaining product, not that SaaS's brand.
Its domain discovery, evaluation, portfolio and buyer/seller experience remain
product-owned. Reuse should serve those outcomes, not make the product a generic
Framework demonstration. Its adoption remains optional to Framework consumers.

Framework remains useful without Studio, a hosted builder account or a selected
commercial service. Reusable capabilities live in appropriately layered,
optional packages; this decision does not add dependencies to `core`, `cms`,
`full` or any production dependency closure. The existing development-plane
separation remains intact.

## Ownership

| Concern | Authority |
| --- | --- |
| Content, relationships, application accounts, access, revisions and workflows | Existing Framework services and specifications. |
| Page documents, block registry, validation, commands and shared editor | Framework's [page-builder contract](page-builder.md); applications bind semantic renderers, brand tokens and preview routes. |
| Application blueprint formats and exact decision binding | [Site contract](../../packages/site-contract/README.md) and [ADR-023](../adr/023-governed-application-blueprint-site-contract.md); higher Framework layers own generation and verification. |
| Projects, AI proposal jobs, review UX and application lifecycle orchestration | OSS Studio, consuming Framework contracts. |
| Customer offering, curated experience, pricing and managed service operations | The commercial product consuming Studio. |
| Installed TLS/origin topology, storage provisioning and private secrets | The selected infrastructure/operator implementation. |

Reusable billing, deployment, model adapters, themes and recipes may be shared
packages. Business-specific service policy and production configuration remain
with the product. A shared package must not require Russell's service or brand.

## Authoring contracts

There is one authoritative page document and one reusable editor contract.
Studio must compose the shared editor instead of copying its source or creating
a second layout schema, page entity, publishing path or revision store.
Existing Admin SPA and Anokii clients retain the same contracts.

Application proposals and page proposals are different operations:

- Application proposals describe entities, fields, relationships, permissions
  and workflows using `application_blueprint`. Existing exact approval and
  explicit apply requirements continue to govern materialization.
- Page proposals describe content and registered layout/block choices using the
  page-builder contract. AI and manual edits use the same server-authoritative
  validation, access, concurrency, revision and publishing boundaries.

AI output cannot grant permission, approve itself or bypass an existing write
boundary. Page editing does not introduce arbitrary executable HTML, CSS,
JavaScript or application code. Ordinary editorial updates must not require
application rematerialization. New AI integration operations need a bounded
design using these contracts, not an assumed extension of the existing API.

Application-owned renderers remain consistent between preview and published
output. A section refinement should preserve unrelated edits. Existing content
entities remain structured and queryable rather than becoming duplicated text
inside page layouts. See [SSR composition](ssr-page-composition.md) for its
separate rendering and access boundary.

## Reuse acceptance target

A second independently branded builder must create, edit, save and reopen a real
project using the same Framework and Studio releases without changing either
upstream core. Documented configuration, plugins, recipes and application-owned
themes are permitted. The operator should be able to update the base without
reapplying copied implementation changes.

This target is not yet a certified capability. Inspect the exact producer and
consumer integration before opening missing-contract work. Existing downstream
page-builder acceptance and current Studio MVP criteria are not replaced by
this architectural decision.

## Companion and scope

Studio owns the product direction and proposed roadmap in its
`docs/product-direction.md` and `docs/roadmap-discussion.md`, under the same
stable change-record ID. The roadmap is for discussion; no implementation
schedule, runtime change, issue closure, migration, public signup or deployment
is authorized by this specification. Intersnipe is now a concrete consumer
planning candidate: its existing PHP dashboard needs exact-main convergence,
while its separate Node concept prototype can test shared authoring integration.
Studio's `docs/intersnipe-plan.md` owns that proposed sequence. A dashboard upgrade
alone is not proof of OSS Studio reuse, and its migration is not a blanket
Framework prerequisite. Production changes require separate authorization.
