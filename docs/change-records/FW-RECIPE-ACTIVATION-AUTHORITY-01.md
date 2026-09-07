# FW-RECIPE-ACTIVATION-AUTHORITY-01 — first-party recipe provider activation

- **Issues:** [#2857](https://github.com/waaseyaa/framework/issues/2857),
  [#2975](https://github.com/waaseyaa/framework/issues/2975)
- **Decision:** ADR-025 D-15
- **Status:** accepted and merged in
  [PR #2976](https://github.com/waaseyaa/framework/pull/2976) as
  `62424ff04f6af8bd37b3f2dba4fd1db74bad2c8b`. This record does not claim
  separate product-owner or human approval, release, or deployment.

## Corrected problem and decision

The supported skeleton requires `waaseyaa/framework`, whose production
dependency graph already installs the three packages repeated by the
governed-authoring recipe fragment. The earlier design treated those
requirements as missing and proposed a Composer activation phase. Independent
review proved that phase would be a no-op on the canonical fresh-project path
and its install assertion would pass without exercising the proposed mechanism.

The real defect is provider discovery. It reads literal root `composer.json`,
so generated fragment metadata does not register the recipe provider. ADR-025
D-15 authorizes the smallest repair: fixed typed provider registrations enter
the existing root `ArtifactPlan` and D-6.6 transaction. No requirement DTO,
Composer phase, new transaction, arbitrary package input, or supported thinner
consumer is introduced.

The three generated fragments retain their current bytes and governed
ownership. Governed-authoring's `^0.1` requirements are compatibility output on
the full-framework skeleton, not constraints ratified for a future split
topology. Choosing a `core`/`cms`-based skeleton and its package
materialization rules remains an explicit product and ADR decision.

## Implementation ownership and proof

The accepted #2857 implementation owns
`SiteRecipeProviderRegistrationInterface` on the three existing recipes,
`SiteArtifactRenderer::compile()` with `render()` compatibility, preservation
of the base plan's payload collections in `ApplicationBlueprintCompiler`
except the transaction-composed ownership document, `SiteInitHandler` plan
handoff, and focused unit and packaged tests.

The packaged proof uses the real supported skeleton. It verifies the package
cohort came from the existing framework dependency graph, the selected provider
appears exactly once in literal root `composer.json`, `install:init` succeeds,
and a real kernel resolves the page-builder surface with `page_layout`. A rival
that removes the literal-root provider while retaining the generated fragment
and provider class must fail. It must not attribute already-installed packages
to recipe activation or duplicate #2787's blueprint approval tests.

The generated governed-authoring provider owns one page-scoped publishing
composition. It constructs the `node`/`page` descriptor from the generated
layout-field and permission configuration, selects the canonical `node`
repository, and requires the kernel's database, audit, entity-access, and
publication-transition authorities. It does not publish a global
`ContentPublisher` binding or silently omit an unavailable authority.

The generated published-content and governed-authoring providers register
their in-memory `node`/`page` field declarations during `register()`. That is
the definition-only phase `install:init` executes before entity schema sync;
ordinary provider `boot()` cannot add a field that the completed installation
was already expected to materialize. `FieldServiceProvider` adopts the
kernel-owned `FieldDefinitionRegistryInterface` before constructing its
`BundleTemplateCompiler`, so the published-content fields and governed
`page_layout` field enter the same registry that drives listing validation,
schema materialization, provider-local resolution, and HTTP resolution.
Standalone provider tests retain an isolated fallback only when no kernel
registry exists; a non-null wrong-typed authority is rejected.

#2664 may land a fresh-only boot-free orchestrator after #2857. It invokes
`site:init` → `install:init`, forwards existing input/profile options, stops at
the first failure, and preserves that status. `composer site-verify` remains a
separate qualification step.
Upgrade and AI update/verify remain later slices after non-root generator
migrations and accepted #2660/#2663 plan contracts. A Composer activation phase
requires the new consumer decision described in D-15.4.

`docs/specs/cli-kernel.md` was narrowed by the accepted implementation to the
provider-activation result: presets still do not execute an arbitrary declared
capability, while a selected first-party recipe contributes its fixed provider
through the typed root-plan seam and the existing generation transaction.

The unconditional `ci/site-recipe-provider-activation` job runs the packaged
proof against the exact candidate selected by CI and propagates any proof
failure. It passed for qualified head
`0b47602b3567dc297ba4b847f4aad36174696159` before merge.

## Accepted integration evidence

[PR #2976](https://github.com/waaseyaa/framework/pull/2976) merged as
[`62424ff04f6af8bd37b3f2dba4fd1db74bad2c8b`](https://github.com/waaseyaa/framework/commit/62424ff04f6af8bd37b3f2dba4fd1db74bad2c8b).
The exact-candidate packaged positive consumer booted the supported skeleton,
resolved the page-builder surface, observed canonical `page_layout`, and
verified physical `node__page.page_layout` storage. The rival removed only the
literal-root provider registration while retaining the generated fragment and
provider class; it still booted, exposed neither the surface nor the canonical
field, and retained the physical column. This is the discriminating proof that
provider registration controls activation without destructive removal.

Exact-head qualification passed 43 gates, Unit 14,686 tests / 242,845
assertions, Integration 2,345 / 12,165, and Architecture 1,070 / 36,735.
Architecture retained one deliberate coverage-driver negative-control skip.
Its
receipt SHA-256 is
`d3714c9963fdc40ad57704a286c6dde5e7a87a69562255692e75b7d636d8183e`.
All 47 hosted PR checks passed, and
[post-merge CI 34141974190](https://github.com/waaseyaa/framework/actions/runs/34141974190)
succeeded on the merge commit. Issues #2857 and #2975 are closed with final
evidence.

Published Studio dependency refresh, thinner-consumer package intent,
authenticated authoring, and upgrade orchestration remain separate. This
record claims no release or deployment.

## Pre-existing provider-boot lifecycle defect exposed by the packaged proof

Running the #2857 packaged proof against the real supported skeleton (real
`site:init` → `install:init`, real kernel boot) surfaced a pre-existing,
unrelated defect: kernel boot rejected listing `page_index` because bundle
`page` was not yet registered. Root cause — `PackageManifestCompiler` places
the installed `Waaseyaa\Listing\ServiceProvider` before root App providers in
the provider list; `Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry`
invokes every provider's ordinary `boot()` in that order, and only after ALL
of them runs `FinalizesProviderBootInterface::finalizeProviderBoot()`. The
Listing provider ran its FR-052/FR-053 `ListingDefinitionValidator` inside its
own ordinary `boot()`, before the generated App provider's later `boot()`
had registered the bundle's fields — a real ordering race, not specific to
recipe-provider activation.

Repair: `Listing\ServiceProvider` now implements the existing
`FinalizesProviderBootInterface` and runs FR-052/FR-053 validation from
`finalizeProviderBoot()` instead of `boot()`. Cache-invalidator listener
wiring and canonical context seeding stay in ordinary `boot()`, unchanged.
No lifecycle phase was added, no fallback introduced, and `page_index` was
not weakened — the same `UnsupportedListingException` fail-fast still fires
for a genuinely invalid listing, now from the finalization boundary.

Regression coverage: `packages/listing/tests/Unit/ListingProviderBootFinalizationTest.php`,
using the real `ProviderRegistry` and the real `Listing\ServiceProvider` (no
mocks) with a fixture App provider registered after it in provider order.
`lateAppProviderBundleFieldsValidateOnlyAfterAllProviderBootsComplete`
reproduced the defect (RED) against the pre-repair provider — the same
`UnsupportedListingException: bundle "page" is not registered for entity
type "lifecycle_page"` observed in the packaged proof — and passes (GREEN)
against the repaired provider.
`invalidListingStillRaisesUnsupportedListingExceptionAtFinalization` asserts
fail-fast is preserved for a listing that remains invalid after every
provider's `boot()` has run. `docs/specs/listing-pipeline-v1.md` FR-052/FR-053
now describe the actual finalization boundary.

This entry preserves the focused regression evidence for the lifecycle repair.
The exact-candidate packaged proof and hosted CI described above subsequently
passed and accepted that repair as part of PR #2976.
