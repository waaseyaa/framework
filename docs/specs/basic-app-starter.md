# Basic application starter

Status: Draft proposal. Observed November 1, 2026.
Change record: [FW-BASIC-APP-STARTER-01](../change-records/FW-BASIC-APP-STARTER-01.md).
Readable plan: [starter planner](../plans/basic-app-starter.html).

## Outcome and authority

Russell agreed to plan a basic app starter that shares setup infrastructure with
the CMS starter and does not install CMS packages. That outcome and planning
scope are Agreed. Russell subsequently agreed that AI is included by default in
both starters without pulling CMS packages into the basic app. This supersedes
the earlier proposal to omit AI. Package audit completion is included in this
planning scope as a starter readiness requirement under #3118. Other architecture and defaults below are Proposed. No product
implementation, dispatch, merge, release, package registration or deployment is
authorized by this document. Existing release holds are unaffected.

This is the proposed contract for the new starter, not a description of shipped
behavior. Existing site-golden-path, CLI, discovery, activation and HTTP contracts
remain authoritative until their affected sections are reconciled in slice 1.

## Evidence and constraints

Inspection baseline refreshed to Framework main `c4aff57585a8a7bd13e27e77d710f10dcc0cf662`
on November 1. Core, CLI, HTTP and AI dependency observations below remain
unchanged. The bounded CLI IO lifecycle repair is reconciled in this delivery;
CMS dependency separation remains proposed work.
The original checkout has unrelated social planning edits; they were preserved.

- `skeleton/composer.json` requires `waaseyaa/framework`; its development graph
  includes `waaseyaa/ai-development`.
- `packages/core/composer.json` provides the engine. A traversal of first-party
  runtime requirements found no CMS domain packages in that closure.
- `packages/cli/composer.json` directly requires page-builder, path, publishing,
  SEO, SSR and workflows. Its transitive closure also contains media and API.
- `packages/api/composer.json` requires media and workflows. SSR requires API,
  SEO, workflows and path. Neither is presently a minimal app rendering choice.
- The ai-development runtime closure includes media, page-builder, publishing
  and workflows. Copying the existing require-dev block would break the goal.
- `HttpKernel::finalizeBoot()` constructs `DiscoveryApiHandler` unconditionally.
  `tests/CoreOnlyBoot/boot.php` deliberately probes an AbstractKernel subclass,
  not complete HTTP/CLI setup. It is not starter acceptance evidence.
- The default homepage uses the SSR-owned public.home route. A lean starter
  cannot reuse that route unchanged while excluding CMS dependencies.
- `.github/workflows/sync-skeleton.yml` owns skeleton publication;
  `tools/sync-skeleton-requirements.php` currently advances the framework pin.
  The package split workflow deliberately does not publish the skeleton.
- The HTTP entry-point prose prohibits an outer catch, but the actual skeleton
  has a sanitized boot-failure catch and runtime adapters. Reconcile that owning
  contract against current intended behavior before extracting shared files.

These are source observations and a static dependency traversal, not fresh
Composer resolution, execution, published-package or production qualification.

## Proposed user experience

Two thin Composer project templates, assembled from one shared source:

| Template | Proposed entry point | Default experience |
| --- | --- | --- |
| Basic app | `waaseyaa/app` (name not registered or verified available) | Working app-owned homepage, routes, custom entities, shared setup and default AI support without CMS dependencies |
| Existing CMS | `waaseyaa/waaseyaa` | Existing CMS experience with the same default AI foundation |

Keep the existing CMS package name. Changing its framework dependency to the
cms metapackage is a separate qualification, not a prerequisite for this work.
The basic template must select its dependency graph before Composer installs;
a post-install prompt or uninstall step is not sufficient.

Both templates retain the same lifecycle:
create project -> site:init -> install:init -> site-verify -> development serve.
Use the same command names, site manifest, generation transaction, collision
handling, migration ledger, configuration activation and diagnostics.
The existing minimal/editorial recipe presets are not installation profiles;
do not claim `site:init --preset=minimal` makes a framework install CMS-free.

Proposed basic defaults: PHP 8.5+, SQLite, Composer, a plain app-owned HTTP
homepage and no frontend build step. AI support is included by default. No default
admin SPA, CMS recipe, JSON:API resource layer or login UI. Core user/access services remain available;
absence of a login screen does not weaken authentication or access checks.
Use a normal route/controller response initially. Twig may be an explicit later
choice; do not install current SSR just to render the welcome page.

Reuse the existing development server command after its dependency closure is
qualified. FrankenPHP may remain a development dependency if needed for command
parity; runtime binary/platform support is a separate explicit requirement.
Production activation must work in a separate locked no-dev installation using
the existing field-access and configuration rules. No new bypass flags.

## Dependency and ownership contract

The basic template targets core, shared setup/CLI and default AI support with their
declared infrastructure dependencies. The exact manifest is a slice-1 output,
not a presently working installation command.

Proposed forbidden packages in both the normal and no-dev installed closures:
`waaseyaa/framework`, `waaseyaa/cms`, `waaseyaa/full`, `waaseyaa/node`,
`waaseyaa/page-builder`, `waaseyaa/taxonomy`, `waaseyaa/media`, `waaseyaa/path`,
`waaseyaa/menu`, `waaseyaa/workflows`, `waaseyaa/seo`, `waaseyaa/ssr`,
`waaseyaa/publishing`, and `waaseyaa/admin-surface`.
Also omit the JSON:API resource layer by default. Do not add a forbidden package to core
or conceal it in require-dev to make a smoke test pass.

### AI is a default capability in both starters

Both normal development installations include maintained agent guidance,
framework introspection and local AI development tools. Prefer repairing the
existing `waaseyaa/ai-development` bundle and its dependency owners rather than
creating a basic-app-only imitation. Removing AI is not an acceptable way to
satisfy the CMS exclusion test. CMS-specific AI tools belong with optional CMS
integrations and become discoverable only when their requirements are installed.

Both starters also include shared runtime AI integration contracts in their
production dependency graph. The exact owning package and provider boundary are
slice-1 design outputs. Default inclusion does not require moving every AI
implementation into the `waaseyaa/core` metapackage. Use existing interfaces and
provider contracts where suitable; do not create a parallel AI abstraction.

Preserve ADR-022 and CP009: `waaseyaa/ai-development`, `waaseyaa/ai-agent` and
local-operator authority stay out of the production require closure. Runtime AI
contracts must remain usable after `composer install --no-dev`, independently
of local development tooling. Any extraction must preserve that trust boundary.

Provider adapters, models and credentials are explicitly configured. Project
creation, initialization and ordinary boot require no AI credentials, perform
no inference calls or model downloads, and incur no AI-provider spending. These
constraints do not prohibit normal Composer dependency downloads. Invoking an
AI operation without a configured provider returns an actionable configuration
error, never fabricated success. Qualify configured dispatch with a deterministic
test provider; paid live-provider qualification is not a starter prerequisite.

Repairing CMS coupling in the default AI graph is required in this stage,
alongside CLI/HTTP separation. Broader AI feature development remains outside it.

Prefer thinning the existing CLI to shared commands and moving domain command
registration/handlers to owning optional packages. Reuse existing provider and
optional-package discovery contracts. Keep one initialization implementation,
one CLI executable and one HTTP kernel. If moving commands requires a small
integration package because of layer constraints, settle its owner, graph and
name in slice 1 before implementation. Do not create a second CLI framework.

HTTP integration must register optional API/CMS services only when their
capability is installed. Missing an explicitly requested capability must fail
with an actionable diagnostic before generation writes; it must not silently
disable declared behavior or auto-install packages. Authentication, access,
production refusal and error sanitization remain enforced for both templates.

## Shared starter source

Propose `starters/shared/`, `starters/app/` and `starters/cms/` as the canonical
source layout, with a small deterministic assembly tool. Reuse maintained
filesystem/Composer tooling. This is file composition, not a new template engine
or runtime dependency injection layer.

Shared files include the HTTP entry point, environment initialization, setup
script, verification launcher, baseline configuration and common CI/testing
configuration, maintained AI guidance and common AI configuration conventions.
Profile files contain their Composer manifests, homepage/provider
differences, capability defaults and explanatory README content. Explicitly
declare allowed overrides; refuse accidental collisions or unrecognized profiles.
Preserve permissions, dotfiles, exclusions and canonical consumer rule mirrors.

Assembled templates are generated build artifacts. Published consumers receive
ordinary editable files and never need the monorepo or assembler at runtime.
Application initialization continues to use existing generated-file ownership
rules; shared source does not authorize overwriting consumer edits on upgrade.
Retire duplicated skeleton source only once its CI, archive, audit, release and
documentation consumers have moved together. No indefinite parallel source trees.

## Package audits alongside starter delivery

Use the [core and starter readiness checklist](../audits/packages/core-starter-readiness.md)
under FW-PACKAGE-CONVERGENCE-01 / #3118. It lists all 22 current core packages,
existing assessment evidence/owners, example remediation issues and named
proposed assessment destinations. A successful boot does not complete any audit.

Run package assessments and bounded starter implementation as coordinated work,
not one oversized refactor. Reuse completed evidence with delta review and
existing remediation issues. No audit, chat assignment or defect repair is
performed merely by adding this plan requirement.

Starter readiness requires complete assessments, owned findings, verified
required repairs or explicitly authorized residual dispositions, required review
and cross-package installed qualification. Extend the checklist to the actual
final default CLI/AI and development dependency closure; the 22-package core
list is the starting set, not the complete AI-enabled starter. CMS changes also
need their applicable assessment/delta review and regression qualification.
No missing package row or unassessed dependency can be hidden by a green demo.

## Acceptance criteria

| ID | Observable result |
| --- | --- |
| BAS-01 | Real clean Composer installation, with normal scripts/plugins, contains none of the forbidden packages in vendor or either lock section; repeat with no-dev. No borrowed monorepo vendor, aliases or hidden package injection. |
| BAS-02 | Both templates assemble deterministically from the same shared files. Unknown profiles and undeclared file collisions fail. A shared-source edit reaches both outputs. |
| BAS-03 | Basic consumer runs site:init, install:init twice, verification and CLI list. Reinitialization preserves authored files; collisions refuse through existing recovery rules. No CMS tables, recipes or providers appear by default. |
| BAS-04 | Real HTTP returns the app homepage and an ordinary 404 for an unknown route, cold, warm and after restart. No API/CMS class is needed and exceptions remain sanitized outside debug. |
| BAS-05 | A consumer-owned test entity is saved and loaded in a new process. A protected operation refuses an unauthorized account and allows the explicitly authorized account using existing policy contracts. This fixture is acceptance evidence, not a bundled todo product. |
| BAS-06 | Missing configuration activation or field-access evidence still prevents production boot. The activated consumer passes HTTP checks in a separate locked no-dev installation without development tools. |
| BAS-07 | Requesting an unavailable CMS capability produces a clear missing-package diagnostic without partial generated changes or silent Composer execution. |
| BAS-08 | Existing CMS creation, content initialization, verification and homepage journeys pass after shared extraction. CMS commands remain discoverable only with their required capabilities. |
| BAS-09 | Native Windows and Linux run the portable initialization/verification path with recorded runner identities. HTTP/runtime claims are limited to explicitly exercised hosts; no inferred macOS support. |
| BAS-10 | Candidate archive proofs record exact source/template/lock identities. Later published create-project checks use obtainable published versions and separate dev/no-dev consumers. Candidate success alone is not publication or consumer certification. |
| BAS-11 | Both fresh development consumers expose maintained AI guidance, framework introspection and local development tools without an extra package-install step. Exercise an actual introspection operation. The basic consumer still satisfies BAS-01 with the AI bundle installed. CMS-only tools are absent there and available with their required CMS integrations. |
| BAS-12 | Both locked no-dev consumers load and exercise shared runtime AI contracts with a deterministic configured test provider. The production require closure contains neither ai-development nor ai-agent/local-operator authority; existing CP009 controls still pass. |
| BAS-13 | Without provider credentials, creation, initialization and ordinary boot succeed with zero inference calls or model downloads. An explicit AI operation fails with a clear configuration diagnostic. A configured test provider handles an explicit operation through the shared integration, without a paid external call. |
| BAS-14 | Every core package and every additional first-party package in the final basic starter development/production closures has a reconciled readiness row, completed assessment evidence, named ownership, dispositioned findings and mandatory review. Required repairs are verified; residuals require explicit authorized decisions. Compare the actual lock/installed roster to coverage, then pass the separate installed-consumer criteria. Missing evidence blocks starter readiness/publication, not authorized design or bounded repair work. |

## Boundaries and decisions

Agreed: separate basic app outcome, shared setup infrastructure, no CMS package
installation in the basic app, AI included by default in both starters, package-audit readiness alongside
installed-app qualification under #3118, planning only.

Proposed: `waaseyaa/app` name; plain HTTP homepage; no JSON:API/admin by default;
shared source plus deterministic generated distributions; retain existing CMS
name and dependency set for the initial extraction.

Open technical decisions for slice 1: exact CLI command relocation and package
ownership; full HTTP optional-service inventory; supported basic site manifest
defaults; definitive direct dependency list; development runtime on each host;
CMS-free AI development closure and ownership of shared runtime AI contracts.
These require bounded source tracing, not a user preference questionnaire.

Deferred: one-command app-to-CMS conversion, frontend scaffolds, login/account
journeys, AI features beyond the required dependency separation, domain features, CMS dependency optimization and
new deployment platforms. Later CMS adoption needs explicit dependencies,
capability changes, migrations and requalification; installing a metapackage
alone is not proven conversion.
