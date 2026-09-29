# FW-MAINTAINER-SUBAGENT-REVIEW-01: AI-agnostic subagent reviews

- Program: `FW-PACKAGE-CONVERGENCE-01`, `waaseyaa/framework#3118`
- Base: `2eb7f2b63ab343a58b13f26fe434a926f0d536dc`
- Scope: Framework maintainer skills only

## Decision

Independent review and package-finding verification are subagent roles. The
integration owner supplies an immutable commit or diff, acceptance criteria,
the change record, and existing evidence. The reviewer returns evidence-backed
findings and an approve or changes-requested verdict.

The contract is AI-agnostic. Skills do not select or require a model, vendor,
or model-specific helper for review. Harness and model identity may be retained
as provenance when available, but it is not evidence of review quality. If
delegation is unavailable or unauthorized, review remains pending; a second
pass by the integration owner is not independent review.

Implementation-model routing remains outside this decision. The change does
not authorize delegation, external mutation, implementation, or landing by
itself.
