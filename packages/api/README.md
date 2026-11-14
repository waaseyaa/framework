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


## JSON:API metadata producer

JsonApiRouteProvider::routeDefinitions generates immutable route declarations from finalized copied entity/exposure inputs. Legacy registration remains supported and projects fresh Symfony routes from a bounded immutable cache. Hidden entities retain opaque 404 behavior. ApiServiceProvider is still a legacy contributor pending complete availability and terminal adapter migration; this source preparation does not qualify installed Bimaaji.

## Optional engagement endpoints

When `waaseyaa/engagement` is installed, this package exposes the settled `/engagement/v1` [HTTP contract](../../docs/specs/engagement-likes-comments.md). Configure the engagement domain policy, installation identity and Foundation keyring as described in its [README](../engagement/README.md). Missing domain configuration returns sanitized 503 per request; installing API alone does not install engagement.

Use JSON objects with `Content-Type: application/json` or `application/vnd.api+json`. Obtain a submission key, then create comments using `text`, `submissionKey` and optional `parentUuid`; reuse the same key after a retryable failure. Mutations require the existing session CSRF token even for JSON. Like state uses a JSON boolean, edits use an integer `expectedRevision`, and caller-supplied author/target fields are rejected. Bulk summaries POST is read-only. Comment UUID routes resolve their immutable root inside the domain service.

Responses contain only policy-permitted domain projections, with JSON:API-shaped error envelopes and `private, no-store` caching. Pages expose `meta.nextCursor` and `meta.hasMore`; continue even when a page has no visible items. The API privacy middleware also covers matched-route CSRF and transport-abuse refusals. Domain quotas, removal, expiry and retention keep the same behavior for HTTP and trusted service callers.
