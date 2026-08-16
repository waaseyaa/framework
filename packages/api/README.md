# waaseyaa/api

**Layer 4 — API**

JSON:API endpoint layer for Waaseyaa applications.

Provides `JsonApiController` with CRUD patterns, `ResourceSerializer` for entity-to-JSON:API serialization (with optional field-level access filtering), `SchemaPresenter` for JSON Schema output, and `DiscoveryApiHandler` for resource discovery. Access is enforced via route options processed by `AccessChecker`.

Key classes: `JsonApiController`, `ResourceSerializer`, `SchemaPresenter`, `DiscoveryApiHandler`.

## Optional public content search

Applications that install `waaseyaa/search` and `waaseyaa/auth` may opt in to
the principal-safe public endpoint with `api.content_search.enabled: true`.
It registers `GET|HEAD /api/content/search` only when Composer can autoload
both runtime contracts. Package absence withdraws the route. Services resolve
lazily inside the request; a missing or failing binding returns a sanitized,
correlated 503 instead of silently disabling the endpoint or resolving a
database writer while routes are built.

The optional seam is represented by API-owned read-model and rate-limiter
ports. Version-bounded adapters validate the real optional package contracts
and translate their public DTOs field-by-field; API source does not import
optional Auth/Search runtime types or enumerate result-object properties.

The response is a closed JSON:API projection of access-checked search hits.
Raw index rows are never serialized. Every request uses the immutable
authorization principal prepared by the HTTP middleware and consumes both a
deployment-global atomic rate-limit bucket and a fixed anonymous or hashed
authenticated-principal bucket. Client forwarding headers do not participate
in identity. Anonymous GET/HEAD requests are session-stateless; an existing
session cookie still resumes the authenticated session.

`meta.isComplete` reports whether Search exhausted its bounded raw candidate
window. When it is false, totals, pages, and facets are lower bounds, and
filters or non-relevance sorts apply only inside that window. An empty visible
result may still be incomplete when every inspected candidate is denied or
filtered; the response never exposes those candidates' identifiers or values.

Optional bounds live under `api.content_search.rate_limit`:

```php
'api' => [
    'content_search' => [
        'enabled' => true,
        'rate_limit' => [
            'identity_max' => 30,
            'global_max' => 300,
            'window_seconds' => 60,
        ],
    ],
],
```

Accepted query keys are `q`, `page`, `page_size`, `topic`, `content_type`,
`source`, `min_quality`, `sort`, `order`, and `facets`. Unknown or malformed
input is refused before rate limiting or provider execution.
