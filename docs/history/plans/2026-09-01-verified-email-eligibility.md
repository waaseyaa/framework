# Verified-email eligibility implementation plan

Stable change record: `FW-2757`

1. Add red unit tests for the shared policy and strict configuration parsing.
2. Add red controller/service tests for registration, password login,
   `AuthManager`, pending 2FA creation/promotion, and public resend.
3. Add red middleware tests for existing PHP sessions and bearer identities.
4. Implement the neutral contract, auth-owned policy, and production bindings.
5. Add lifecycle integration, route composition, upgrade documentation, and
   packaged-consumer evidence.
6. Run focused tests, all suites, full preflight, changed-line coverage, and an
   independent bypass/regression review before publication.

