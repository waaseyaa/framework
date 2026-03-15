# SSR Health Checks — Waaseyaa v1.1

Describes the planned health check endpoints for production observability.

## Planned Endpoints (v1.1 implementation)

> These endpoints do not exist yet. Implementation is tracked in the v1.1 milestone.

### `GET /health/api`

Validates the JSON:API layer:
- Router initializes without error
- Entity type manager loads all registered types
- Returns: `{"status": "ok", "layer": "api", "entity_types": N}`

### `GET /health/ssr`

Validates the SSR rendering pipeline:
- Twig environment loads without error
- `home.html.twig` template compiles
- Returns: `{"status": "ok", "layer": "ssr"}`

### `GET /health/auth`

Validates the auth system:
- Session middleware is registered
- `AuthController` routes are reachable
- AnonymousUser resolves correctly
- Returns: `{"status": "ok", "layer": "auth"}`

## Implementation Notes

- All health endpoints should be `_public: true` (no auth required)
- Return HTTP 200 on success, 503 on failure
- Add to `RouteBuilder` in `packages/api`
- Wire via `HttpKernel` route registration

## Smoke Test Integration

The Playwright smoke suite should add `/health/api` and `/health/ssr` checks once
the endpoints are implemented. See `packages/admin/e2e/` for existing smoke tests.
