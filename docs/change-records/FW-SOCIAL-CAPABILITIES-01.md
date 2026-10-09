# FW-SOCIAL-CAPABILITIES-01

Status: accepted scope, detailed design pending, 2026-10-08.
Base: `bd32c916b9a8f44ca08f9d640619b4cd85b93866`.
Parent: FW-PACKAGE-CONVERGENCE-01 / #3118. Design owner: [#3197](https://github.com/waaseyaa/framework/issues/3197).
Contract: [Shared social capabilities](../specs/social-capabilities.md).

## Decision and boundary

The maintainer accepted shared social planning including posts, feeds, comments
and replies, profiles, relationships, groups, messaging, notifications and safety.
Framework contracts, issues and roadmap remain consumer-neutral. Product settings,
roadmaps and actual application adoption evidence belong downstream.

This documentation slice defines scope, owners, acceptance obligations and delivery
order. It does not implement features, ratify unresolved transition defaults,
upgrade consumers, certify installs, publish packages or deploy. Existing audit
findings and qualified repairs retain their owners and evidence.

## Acceptance and evidence

- Map each capability to existing packages or an explicit ownership gap.
- Keep confirmed defects separate from planned capabilities and adoption work.
- Specify lifecycle and permission transitions before implementation.
- Retain Symfony reuse and current transaction/access/schema authorities.
- Distinguish source/synthetic checks from installed consumer qualification.
- Replace the obsolete roadmap narrative with current alpha convergence and
  phased social planning. Remove named-product framing from active workflow
  guidance; historical evidence is not rewritten as new proof.
- Audit ledger/index consistency: 134 tests, 1092 assertions passed before this
  planning revision; final candidate verification is recorded with delivery.

## Private custody closeout

The maintainer designated a private location outside repositories and sessions.
Listing and messaging original briefs and independent review/probe evidence
were copied without modifying originals: 22 files, SHA-256 verification per
file, restricted ACL verification and a private receipt held by Russell Jones.
Locations and security details remain private. Listing's custody blocker is
settled; messaging domain/profile decisions remain open for this design work.
