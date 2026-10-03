# waaseyaa/routing

**Layer 4 — API**

HTTP routing for Waaseyaa applications.

Wraps Symfony Routing with a `RouteBuilder` fluent API and adds route-level access options (`_public`, `_permission`, `_role`, `_gate`) evaluated by `AccessChecker`. Includes language negotiation middleware (`UrlPrefixNegotiator`, `AcceptHeaderNegotiator`).

Key classes: `RouteBuilder`, `Router`, `AccessChecker`.

## Auth/OIDC metadata adoption

Auth/OIDC route metadata: twelve auth declarations use nonshared explicit handler factories. Optional OIDC declarations use finalized service binding presence, never controller health probes. Missing bindings omit endpoints; declared unhealthy handlers refuse selected execution. Existing names, methods, access/CSRF posture and /api/user/me priority remain. Legacy object helpers project the same table. This source adoption does not qualify installed consumers or the CLI/Bimaaji integration.
