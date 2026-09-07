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
  than a persistent preset/profile flag or backend security implementation; and
- literal root `composer.json` registers published content exactly once for both
  profiles, governed authoring exactly once only for editorial, and never the
  unselected subscription provider.

The companion architecture test keeps the harness, closed seed, probe, exact
candidate-copy controls, and CI invocation reviewable without running Composer.
The dedicated `ci/site-init-profile-acceptance` job executes the packaged proof
against the workflow's requested exact SHA.

## Boundary and residual acceptance

This evidence covers the packaged profile proof for the already implemented
declarative half. It verifies the selected providers reach literal root
`composer.json`, but does not itself boot them or claim that `editorial` reaches
a running authenticated authoring surface. #2857's separate packaged activation
proof owns real kernel boot, page-surface resolution, canonical field authority,
and storage materialization. #2442 remains incomplete for authenticated authoring
and upgrade evidence outside this profile slice.

The #2442 profile slice changed verification and governance records only; its
production provider-registration dependency is implemented and recorded by the
separate #2857 candidate. Neither slice introduces an authorization or
publication decision.

## Qualification

The review candidate must pass the focused architecture test, shell syntax
check, and the packaged harness locally. Hosted qualification must pass the
dedicated exact-SHA job before governed landing. Record the candidate commit and
hosted run at the publication checkpoint; neither is inferred by this document.
