# FW-SITE-INIT-PROFILES-01

Issue mirror: #2442. Parent source:
`c0a8d5d4dab09d9bb527ec502f5c61b88b027564`.

## Purpose

Bind the accepted declarative `site:init --preset=minimal|editorial` contract
to a copied-package consumer proof. The proof must exercise the exact candidate
through one published skeleton and one dependency cohort, without a path-link or
monorepo-autoload fallback.

## Acceptance evidence

`tests/PackagedForm/check-site-init-profile-acceptance` creates independent
consumers from identical skeleton, lock, and seed bytes. It proves:

- a non-vacuous editorial dry-run reports planned creations and writes no byte;
- minimal publishes only the resolved minimal decisions and remains
  byte-identical on an unchanged rerun;
- two independent editorial runs publish identical governed bytes;
- an unowned collision and an edited managed artifact both refuse non-zero and
  leave project state unchanged; and
- generated manifests contain resolved capability and recipe decisions rather
  than a persistent preset/profile flag or backend security implementation.

The companion architecture test keeps the harness, closed seed, probe, exact
candidate-copy controls, and CI invocation reviewable without running Composer.
The dedicated `ci/site-init-profile-acceptance` job executes the packaged proof
against the workflow's requested exact SHA.

## Boundary and residual acceptance

This evidence closes only the packaged proof for the already implemented
declarative half. It does not claim that `editorial` reaches a running
authenticated authoring surface. The full Framework skeleton already installs
the packages and #2846 already implements the generation authority; the actual
remaining #2857 edge is that provider discovery reads literal root
`composer.json`, where the generated provider is not registered. #2442 remains
incomplete until that provider-registration path and the corresponding packaged
authenticated-authoring and upgrade evidence land.

This candidate changes verification and governance records only. It introduces
no production command, manifest, recipe, provider, authorization, or publication
behavior.

## Qualification

The review candidate must pass the focused architecture test, shell syntax
check, and the packaged harness locally. Hosted qualification must pass the
dedicated exact-SHA job before governed landing. Record the candidate commit and
hosted run at the publication checkpoint; neither is inferred by this document.
