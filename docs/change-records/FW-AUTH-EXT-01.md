# FW-AUTH-EXT-01 — sealed auth core consumer extensions

- Parent: `f40f341c0787ffc14ae7cea9efd1e97e5cd878cd`
- Forge mirror: Framework #2437
- Authority: typed application policy and lifecycle seams around package-owned auth

## Decision

Framework continues to own credentials, password hashing, sessions, CSRF,
tokens, two-factor verification, rate limiting, controllers, and authorization.
Applications contribute only typed policy objects through provider capability
discovery. Registration policy, profile persistence, redirect selection, mail
presentation, and initial-role selection are exclusive slots: distinct slots
compose in manifest order and duplicate owners refuse boot with both provider
classes in the diagnostic.

Application profile data is persisted by an application handler against a
Framework-issued user identity. It is never added to or read from the
security-critical `User` entity. Existing event dispatch and role definitions
remain the lifecycle-listener and permission authorities.

## Work sequence

1. Inventory and document existing configuration, event, mail, and role seams.
2. Add retained-red composition, conflict, and security-boundary tests.
3. Add the typed registry and immutable policy inputs/results.
4. Integrate policies around successful core operations without exposing or
   replacing security internals.
5. Prove all slots in an isolated packaged consumer and prove a Framework auth
   hardening remains effective with every extension enabled.

## Boundaries

No controller replacement, generic security-service override, user subclass,
credential access, token construction override, release, deployment, or data
migration is authorized by this record.

## Follow-up: production route composition (#2694)

The routing package must inject the container's composed
`AuthExtensionRegistry` into every auth controller that consumes it. Controller
fallback registries remain available for isolated construction, but production
HTTP routes may not use them: doing so bypasses application registration,
profile, role, mail, and redirect policies and suppresses lifecycle events.

Acceptance is a route-registration regression that proves one exact registry
instance reaches register, forgot-password, verify-email, resend-verification,
login, logout, and two-factor verification controllers. The runtime logger must
also reach the three controllers that emit development verification or recovery
URLs; their `NullLogger` fallback is not a production route composition.
No controller, extension-slot, token, credential, or session contract changes.
