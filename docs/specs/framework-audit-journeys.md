---
waaseyaa-spec:
  lifecycle: live
---
# Framework audit journeys

Planning contract adopted 9 October 2026 under FW-PACKAGE-CONVERGENCE-01 / #3118.
This specifies audit acceptance, not a new runtime API or a claim of conformance.
The package coverage index remains the roster; journey evidence lives in the
existing program record and owning package ledgers, not a second findings database.

## Coverage and sequence

| Journey | Outcome | Existing authorities |
| --- | --- | --- |
| J1 | Empty directory to initialized app and first cold/warm homepage | #3199; site-golden-path, S1 lifecycle, native-host, HTTP/SSR and package discovery |
| J2 | Account/session, authorized create/read/update/delete and refusal | Account lifecycle #2443; auth/access/entity/storage/audit contracts |
| J3 | Publication, listing/detail/search and cache visibility after changes | Publishing/listing/search/cache contracts; #3005, #3008, #1861 |
| J4 | Queue/notification execution through retry, rollback, crash and restart | #2741/#2743/#2745 and execution/persistence contracts |
| J5 | Upgrade, configuration/schema activation and recovery | #2844/#2850, migration/configuration and lifecycle contracts |
| J6 | Opt-in capabilities, aggregate/split distributions and remaining packages | Existing package audits and installation profiles; shared social design #3197 |

Order later work by safety/integrity, contract centrality, failing dependencies
and evidence gaps. These journeys do not postpone urgent proven defects. Optional
packages outside early journeys still require complete package disposition.
Reuse closed assessments and landed repairs after checking material deltas.

## J1: clean installation through the first homepage

Owner: [#3199](https://github.com/waaseyaa/framework/issues/3199). Start ready for
assessment, not certified or implementation-complete. No real consumer upgrade,
production operation, package release or deployment is in scope.

### Profiles and inputs

- Follow the current root README command `composer create-project waaseyaa/waaseyaa`
  and its documented stability selection in an empty directory outside all
  repository checkouts. Record the actual template version/reference and resolved
  package cohort before comparing with current monorepo source.
- Use the existing minimal site preset with synthetic local data and the public
  homepage defined by the skeleton. Pin a noninteractive seed under the existing
  site-golden-path schema if automation needs it; do not invent another manifest.
- Record PHP, extensions, Composer, SQLite, OS, filesystem and serving runtime.
  Use the current S1 Linux/PHP-FPM serving boundary for production evidence and
  the native-host contract for native Windows development. FrankenPHP development
  success is not S1 production certification. Read #2687/#2702 at intake.
- Test both fresh resolution and a repeat install from the resulting exact lock.
  Test production `composer install --no-dev` separately using those same locked
  production packages. Development verification/build tools may run before that
  boundary; the production request must not depend on them.
- Execute the documented plugins/scripts, configuration generation and preflight.
  Do not suppress hooks, overlay vendor, borrow dependencies, use monorepo path
  repositories or manually fix generated files to turn a failed baseline green.
  Controlled candidate artifacts are a separate evidence class after repair.

### Acceptance matrix

| ID | Boundary | Required witness and discriminator |
| --- | --- | --- |
| J1-A | Composer/template delivery | Resolved template/lock, expected exported files, scripts/plugins and autoload; repeat locked install; missing platform requirements refuse clearly |
| J1-B | Initialization | site:init then install:init and required schema/config/field-access activation; repeat preserves owned state or gives the specified refusal; no hidden manual schema/bootstrap step |
| J1-C | First and warm GET / | Real public/index.php to kernel, middleware/principal, provider/routes/access, configuration/storage, Twig and response; app-owned home template marker proves the intended render path; repeat and restart preserve correct behavior |
| J1-D | Failure/refusal | Missing required configuration, unavailable SQLite, unknown route and stranger access to an explicitly protected synthetic route; no misleading success or sensitive public error; safe actionable private diagnostic |
| J1-E | Production composition | Clean no-dev install and request on the supported serving profile; no development-only symbols, assets or undeclared dependencies; record activation path and runtime/build distinction |
| J1-F | Repair and closeout | Failures mapped to existing owners, intended contracts settled, bounded repairs reviewed and landed, final applicable journey rerun and package/docs/issue/Project evidence reconciled |

Use the skeleton's app-owned home template with a unique synthetic marker; a
framework fallback or static response must not pass. Follow the intended auth
contract, not a manufactured public route around middleware. Failed initialization
must not be silently retried into success without recording the original failure
and recovery behavior. Keep secret values and private security reproductions out
of public logs and issues.

### Reuse before adding tests

Inspect existing tests/FreshInstallBoot/boot.php, tests/CoreOnlyBoot/boot.php,
tests/PackagedForm/check-production-install-genesis,
tests/PackagedForm/check-fresh-install-boot and
tests/PackagedForm/check-site-recipe-provider-activation, plus skeleton-create-project
and native-host CI owners. Map each existing assertion to this matrix. Extend the
owning test only for a demonstrated gap; separate tests are justified for a
missing real boundary, not to copy a green suite. Unsupported local runs remain
diagnostic and cannot replace the owning hosted/profile proof.

### Intake candidates and stop conditions

Revalidate #3120 (activation docs), #3181 (doctor), #3110 (schema), #2687
(development runtime extensions), #2702 (server topology), #3116 (SSR fallback),
#3198 (diagnostics), #2859 (kernel lifecycle), #3117 and #3122-#3125 (composition).
Do not mark every candidate blocked-by before an actual dependency is demonstrated.
Broader #2844/#2850 and #2676/#2681 retain their acceptance and ownership.

Record a contract decision instead of guessing when intended behavior conflicts.
Stop only the affected profile on missing custody, unsafe side effects or an
unsupported environment. Continue independent supported checks and label the gap.
No external live service or paid provider is required by J1. Assessment may finish
with bounded findings; journey closure requires required repairs and qualification.
