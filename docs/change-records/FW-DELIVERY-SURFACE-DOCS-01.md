# FW-DELIVERY-SURFACE-DOCS-01 — package-local surface documentation authority

Date: 2026-09-05. Forge mirror: #2901. Parent programme: #2527.

## Problem

The package-local declaration migration made `packages/<pkg>/public-surface.php`
the editable authority for public-surface dispositions and made
`docs/public-surface-map.php` and `.md` generated views. Seven current
specifications still direct contributors to edit the aggregate, or describe
unshipped tier and mission-status fields as current acceptance data. Following
those instructions would create avoidable shared-file conflicts or a
surface-parity rejection.

## Scope and decisions

Update only current contributor instructions to name the owning package-local
declaration. Keep valid generated-view references, historical and superseded
mission statements, and unrelated classification locators unchanged. The
stability charter's beta item remains pending; this change corrects its authority
locator without claiming completion. No surface disposition, generated
aggregate, runtime behavior, release process, or gate semantics changes.

This follow-up is separated from the implementation delivered by
`FW-DELIVERY-SURFACE-01`. It adds no prose-locking test: the existing surface
declaration and generated-view gates remain the executable authority.

## Acceptance and verification

- Current public-surface guidance directs edits to the owning
  `packages/<pkg>/public-surface.php` declaration.
- The entity-storage mission no longer requires nonexistent tier or
  mission-status fields.
- Historical status claims and the pending beta condition keep their meaning.
- The candidate passes the full preflight and the Unit, Integration, and
  Architecture suites on one exact committed head. Evidence is retained under
  `work/qualification/`.
