# ROUTE-METADATA-01 Admin Surface admission delta

Base: c3bd44e3e1bb170eaa0bab1b76a29b8e182637cb. Owner: waaseyaa/admin-surface;
consumer unblock #3122, scoped provider/static seam #3083, audit program #3118.
Dependency lock unchanged: bb7aee941342ac8a8e3aba0c7ecc1d7139bbb2b8b711bbb05950fee810c796e7.
State: source remediation candidate; immutable review and default preflight pending.

The package charter assigns route composition, transport and static delivery to
Layer6 Delivery and composition. Domain hosts retain operation/access/storage
policy. Existing Dependency model is the maintained Deptrac authority; new
handlers are classified in that same layer, not a new generic abstraction.

## Required consumer finding and disposition

RM-AS-01, discovered in/owned by waaseyaa/admin-surface, required for consumer
unblock. Legacy routes capture live core/page-builder hosts and read vendor
index HTML during composition. Canonical graph collection therefore cannot
admit this producer without executing unrelated services/filesystem code.
Evidence: original provider routes/registerRoutes/registerPageBuilderRoutes;
captured six/thirteen-route fixtures at the base. Synthetic initial controls
fail two missing routeDefinitions errors. Disposition: replace declarations
with one copied-input table and explicit nonshared request handlers. Removal
condition for bare replay is migration of existing bare callers under RM-06.

| Changed production token | Classification / responsibility |
| --- | --- |
| AdminSurfaceServiceProvider | Delivery/composition, one table and explicit factories |
| Http/AdminSurfaceHttpController | Delivery/composition, existing core transport promotion |
| Http/PageBuilderHttpController | Delivery/composition, existing principal/body and JSON API response promotion |
| Http/AdminSpaHttpController | Delivery/composition, existing current static/index/fallback HTML |
| Host/AdminSurfaceHostFactoryInterface | Boundary contract, clarified selected construction lifetime |

No protocol or access-gate changes. Canonical page-builder inclusion intentionally
uses admitted binding presence; unhealthy selected execution refuses. Bare
registration retains the previous healthy optional-host gate. Required manager
and field schema authority remain at selected generic-host construction; absent
access keeps the existing fail-closed host behavior. SPA delivery reads files at
execution and retains path traversal exclusion, fallback and runtime CSRF rewrite.

## Source acceptance and residual evidence

Captured canonical tables, cold no-autoload/no-service-read declarations,
nonshared selected core/page-builder status/media/body matrices, matched argument
authority, principal/content forwarding and declared unhealthy-host refusal pass.
SPA selected execution observes late-created/changed assets and rewrites both
static HTML and index fallback. Real kernel API+Admin profile completes62 routes
and dispatches anonymous core session401. All package Unit, affected AdminSurface
integration and real kernel suites:529 tests4164 assertions. Four production files
pass scoped PHPStan. Deptrac:zero violations/uncovered,462 allowed dependencies.
Generated graph, scanner/preflight and immutable review follow before checkpoint.

This is source consumer remediation, not whole-package convergence or installed
qualification. Split/generated/browser/Linux profiles remain explicitly unqualified
here. Existing #3078/#3079/#3082/#3084/#3075 residuals stay separately owned;
#3083 broader cleanup beyond declarative provider/static execution remains open.
Historical audit freshness changes to needs delta review; full template/ledger
re-record is owned by #3118 and is not made an implicit blocker for this consumer
repair. FETDER and CLI/Bimaaji migration still precede installed strict export.
