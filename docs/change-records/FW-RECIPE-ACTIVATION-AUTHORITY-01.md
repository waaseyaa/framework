# FW-RECIPE-ACTIVATION-AUTHORITY-01 — first-party recipe provider activation

- **Issue:** #2857
- **Decision:** ADR-025 D-15
- **Status:** root-authorized technical integration candidate; normal review
  and merge are still required. This record does not claim separate
  product-owner or human approval.

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

#2857 owns `SiteRecipeProviderRegistrationInterface` on the three existing
recipes, `SiteArtifactRenderer::compile()` with `render()` compatibility,
preservation of the base plan's payload collections in
`ApplicationBlueprintCompiler` except the transaction-composed ownership
document, `SiteInitHandler` plan handoff, and focused unit and packaged tests.

The packaged proof uses the real supported skeleton. It verifies the package
cohort came from the existing framework dependency graph, the selected provider
appears exactly once in literal root `composer.json`, `install:init` succeeds,
and a real kernel resolves the page-builder surface with `page_layout`. A rival
that removes the literal-root provider while retaining the generated fragment
and provider class must fail. It must not attribute already-installed packages
to recipe activation or duplicate #2787's blueprint approval tests.

#2664 may land a fresh-only boot-free orchestrator after #2857. It invokes
`site:init` → `install:init` → `composer site-verify`, forwards existing
input/profile options, stops at the first failure, and preserves that status.
Upgrade and AI update/verify remain later slices after non-root generator
migrations and accepted #2660/#2663 plan contracts. A Composer activation phase
requires the new consumer decision described in D-15.4.

The current `docs/specs/cli-kernel.md` statement that presets do not make a
declared capability run remains truthful until the provider implementation
lands. The #2857 implementation must then narrow that sentence to the provider
activation result; this candidate does not expand to a fifth documentation
file.

Shared CI, full qualification, issue reconciliation, release publication and
broad lifecycle documentation remain integration-owner work. This decision
record authorizes no production edit by itself.

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

This entry records only the local unit-level fix and its focused regression
test. The packaged proof itself has not been rerun against this repair as
part of this candidate; packaged success is not claimed here.
