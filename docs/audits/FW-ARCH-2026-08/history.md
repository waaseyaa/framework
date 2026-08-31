# Historical reconciliation

Source baseline and limitations: [index](README.md). Issue states were retrieved
from GitHub on 2026-08-31 UTC. Source observations below are static unless expressly
identified as carried behavioral evidence. **Closed does not mean reverified.**

## Programs are not interchangeable assurances

| Historical record | What closed | Current treatment |
|---|---|---|
| [#817](https://github.com/waaseyaa/framework/issues/817) | A five-pass architectural audit | Reconcile surviving finding issues #823–#858 individually; not today's framework certification |
| [#859](https://github.com/waaseyaa/framework/issues/859) | Remediation planning, handed to #895 | A planned/clustered finding is not evidence of implemented behavior |
| [#2379](https://github.com/waaseyaa/framework/issues/2379) | A bounded implementation/assurance checkpoint | Evidence belongs to its exact source and exclusions; do not import private consumer material or generalize to all packages |
| [cleanup-backlog.md](../cleanup-backlog.md) | CL-series mixed fixes and follow-ups | Reconcile each current entrypoint below |
| Original `AUDIT.md` C-series | Original complete roster unavailable | Partial surviving references only; do not invent C1–C24 coverage |

The report named by #817, `2026-03-31-framework-architectural-audit.md`, was not found
in the inspected reachable Git history or GitHub exact-path commit history.
`AUDIT.md` was also not found by these checks. This is not proof that either never
existed. The original artifacts have been requested from the maintainer. A0 cannot
claim complete historical reconciliation from issue titles or inferred numbering.

## M1 issue roster (#823–#858)

All 36 issues were closed when retrieved. Several closure comments explicitly
collapse findings into later remediation clusters. The following table records
the present disposition rather than reopening those issues automatically.

| Issue | Historical concern | Current disposition / audit owner |
|---|---|---|
| #823 | API → SSR runtime dependency | Named runtime manifest edge absent in current `packages/api/composer.json`; source-level retirement, not all API parity proved. A4/A6 |
| #824 | JavaScript admin outside Composer | Intentional packaging variation; census explicitly includes the JS package. A4/A6 |
| #825 | Missing executable layer assignments | All 73 PHP libraries assigned by executable map; descriptive table still omits `site-contract`. A6 |
| #826 | Testing → GraphQL runtime dependency | Named edge absent from current testing manifest; consumer install not rerun here. A6 |
| #827 | Kernel bootstrap upward imports | Current Kernel subtree has an explicit composition exemption; inspect contract and use, not merely import direction. A3/A6 |
| #828 | Synchronous cross-provider resolution | Resolution remains an explicit runtime seam. Its lifecycle and failure contracts need composition review. A3 |
| #829 | User/SSR concrete coupling | Runtime versus dev dependency and rendering seam need focused qualification; old closed issue is not current proof. A4/A6 |
| #830 | Listener registrar outside kernel exception | Current documented Kernel subtree exemption supersedes the narrow historical placement claim. A3/A6 |
| #831 | Admin provider rebuilding access policy | Current `discoverAccessHandler()` reads the kernel-services handler; named duplication no longer present. A4/A2 |
| #832 | AccessChecker package documentation | Documentation/source alignment still requires a scoped check; no new runtime defect asserted. A6 |
| #833 | Concrete manager in provider API | `ServiceProviderInterface::routes()` and base hook still use concrete `EntityTypeManager`; keep as explicit API-design lead. A3/A6 |
| #834 | Half-specified serializer access context | `ResourceSerializer` and `SchemaPresenter` now reject XOR-null handler/account pairs with `PartialAccessContextException`. Static fix observed. A2/A4 |
| #835 | Incomplete entity-manager registration spec | Retain contract comparison in A2/A6; no claim of full specification parity from inventory. |
| #836 | Concrete manager in generic admin host | Current host/provider use `EntityTypeManagerInterface`; source-level correction observed. A4 |
| #837 | Revision/storage API spec drift | Storage/revision implementation has evolved; revalidate current contract rather than historical method lists. A2/A6 |
| #838 | Provider interface omits hooks | Current interface explicitly documents lifecycle and compatibility; capability/base/kernel parity needs review, not blanket closure. A3/A6 |
| #839 | Missing `emailVerified` client field | Field exists in shared admin contract types. Static correction, not browser behavior proof. A4 |
| #840 | Missing `description` client field | Field exists in shared admin contract types. Static correction, not browser behavior proof. A4 |
| #841 | Discovery route absent from spec | Current route/spec comparison remains an A4/A6 item. |
| #842 | Missing shared admin contract tests | `tests/Integration/AdminSurface/AdminSurfaceContractConformanceTest.php` exists; coverage of each host remains bounded. A4/A6 |
| #843 | Provider interface/base/kernel test coherence | Review actual called hooks against current contract tests. Test existence alone is insufficient. A3/A6 |
| #844 | Missing negative partial-context tests | Production rejection now exists (#834); negative-case/entrypoint completeness remains qualification work. A2/A4 |
| #845 | Reflection-heavy kernel tests | Identify which real-composition assertions are missing before removing fixtures or reflection. A3/A6 |
| #846 | No root admin integration tests | Route-wiring and consumer-host integration tests now exist; not full SPA end-to-end qualification. A4/A6 |
| #847 | Higher-layer imports in foundation tests | Separate intentional composition tests from production layer violations; test placement is not itself a runtime defect. A6 |
| #848 | Incomplete package graph documentation | Full manifest graph now exported; descriptive docs still need semantic maintenance. A6 |
| #849 | Stale subsystem specs | Umbrella concern requires per-contract validation; census cannot close it. A1–A6 |
| #850 | Missing subsystem routing in agent context | Current CLAUDE.md has expanded routing; completeness must be checked against all packages. A6 |
| #851 | Admin prose/shared-payload conflict | Shared fields corrected (#839/#840); maintain independent frontend/backend parity check. A4 |
| #852 | Workflow milestone inconsistency | Current workflow uses portable change records, not GitHub milestone identity, as authority. Intentional governance evolution. A6 |
| #853 | README create-project repository identity | Current README distinguishes application skeleton from framework monorepo. Static correction observed. A6 |
| #854 | Missing provider declarations for routes/commands | 86 manifest providers resolve; actual registration/reachability remains a semantic check. A3/A6 |
| #855 | Brittle combined dev-server shell script | Current Composer scripts separate `dev:php` and `dev:admin`; named fork pattern retired. A6 |
| #856 | No unified verification entrypoint | Current preflight and verification commands exist; exact gate/suite coverage still matters. A6 |
| #857 | Missing package READMEs | Per-package presence is in `data/packages.json`; `migration` still has no README at baseline. A6 |
| #858 | Built commands never registered | Provider inventory is not command reachability proof; use real CLI registration with isolated host. A3/A6 |

Source observations above were checked against the adoption branch, whose
post-baseline production delta is limited to #2735. None of these historical
source observations depends on that deletion fix. This is a disposition roster,
not a claim that all 36 findings were behaviorally retested.

## CL-series

| ID | Current disposition | Owner / next proof |
|---|---|---|
| CL-1 | Named legacy MCP paths absent; reconcile recorded #2191 closure | A4: current equivalent route behavior |
| CL-2 | MCP auth resolves at point of use with app override and intentional anonymous-read fallback | A4/A2: actual endpoint/tier/config negative cases, not a missing-register-binding allegation |
| CL-3 | Network and embedded-agent catalogues intentionally differ | A4: prove policy/tier reachability before proposing exposure or removal |
| CL-4 | Lower-level translation API retained on concrete repository | A2/A6: consumer and extension inventory before interface change |
| CL-5 | Beacon controller/tool rule duplication remains a source lead | A4: compare domain behavior; preserve transport differences |
| CL-6 | Backlog records fix; current schema path differs from original race | A1: do not retest obsolete CREATE path as if current |
| CL-7 | `migrate:defaults` declaration alone proves no autowiring defect | A3: real isolated CLI container resolution |
| CL-8 | Backlog records #1705; `EditTrailTool` exists | A4: runtime registration and authorized behavior |
| CL-9 | Realtime build configuration exists | A4: inspect serve-time injection before claiming runtime immutability |
| CL-10 | Historical split retry is not current release-atomicity proof | A5/A6: reuse #2527 and current release workflow |
| CL-11 | `AuthTokenRepository::ensureSchema()` uses read-only schema availability assertion | A1: original request-path DDL allegation superseded; inspection cost is separate |
| CL-12 | Bounded `streamShouldContinue` source path exists | A3: teardown, cancellation and runtime limits still need execution |
| CL-13 | Media download router and audited source reader exist | A2/A4: current byte ownership and denial matrix; old absence claim is obsolete |

These observations carry the earlier A0 source checkpoint forward; the adoption
work additionally inspected the current MCP and media/admin composition anchors.
No CL row is promoted to fully behaviorally verified by this document.

## C-series and existing owners

Surviving locators include C-6/C-7 in #1702, C-15 in #1703, C-22 work in
PRs #1846–#1850 and `docs/notes/c22-consumer-inventory.md`, and C-24 in
`docs/audits/c24-field-item-layer-deprecation-scoping.md`. They are partial
historical references, not a reconstructed complete finding roster.

Keep current work with existing owners: #1588 (database retirement), #2055
(distribution-safe retirement), #2479 (environment ownership), #2527 (delivery
process), and #2716 (transport-test failure). Reusing an owner is not a statement
that its present scope covers every newly discovered counterexample.

Before proposing deletion, reconcile downstream consumers, public extension
contracts, installed package availability and replacement behavior. A closed
cleanup issue or zero production callers in the monorepo is not enough.
