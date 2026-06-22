# Spec Quality Checklist — Public Content Routing

## Content Quality
- [x] 404 root cause traced to id-only loader vs uuid segment (SsrPageHandler + SqlEntityStorage)
- [x] Confirmed no uuid public lookup exists anywhere; EntityDeepLinkRouteBuilder has zero callers
- [x] Coupled missing-AccessPolicy fail-closed concern surfaced and linked to #1605/#1649
- [x] Canonical contract (numeric id) cross-checked against the author-path-remediation spec FR-006

## Requirement Completeness
- [x] Identifier-contract decision (id vs uuid) framed as a routing-owner decision, not guessed
- [x] Advertised-URL consistency requirement (sitemap/llms.txt/schema.org) included
- [x] Published-gating boundary preserved (FR-004)
- [x] getquery-bindings CI discipline called out if uuid lookup is added (NFR-001)

## Filing Readiness
- [x] Routing + access boundary flagged for confirmation (C-002, FR-001, FR-003)
- [x] Route-precedence (#1632) and JSON:API existence-oracle (#1649) kept out of scope
- [ ] Verify against a running server whether /story/1 renders anonymously today (A-002 — determines fix surface)
