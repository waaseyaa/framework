# Spec Quality Checklist — CLI Command DI Resolution

## Content Quality
- [x] Concrete file:line evidence for both defects (D2, D3)
- [x] Root cause distinguishes wiring/binding from command logic
- [x] Proven-good sibling pattern (`make:content-type`) cited as the remedy template
- [x] Test-gap that masked each defect identified

## Requirement Completeness
- [x] Functional requirements cover both commands end-to-end through the real kernel container
- [x] Single-source-of-truth constraint for route composition stated (NFR-002)
- [x] Latent-failure sweep included (FR-005)
- [x] Each FR maps to a Success Criterion

## Filing Readiness
- [x] Scope In/Out separates wiring fix from route-definition/precedence work (D5/#1632)
- [x] Stale CLAUDE.md "not Symfony Console" note flagged for spec maintenance (A-003)
- [ ] Reproduction tests written and shown red on current `main` (deferred to implement phase)
