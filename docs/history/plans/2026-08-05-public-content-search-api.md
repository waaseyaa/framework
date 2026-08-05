# Optional public content search API plan

Issue: #2193

Program: #2198

Prerequisite: #2192 / PR #2213

## Contract

The API package owns an opt-in `GET|HEAD /api/content/search` JSON:API
adapter. It never reimplements access or aggregates raw search data: the
Layer-3 `SearchProviderInterface` receives the immutable request principal and
returns the only values the API may project. Facets remain provider-computed
post-authorization aggregates in top-level `meta`.

The surface is present only when `api.content_search.enabled` is exactly
`true` and Composer can autoload both optional contracts. One cached scalar
presence decision registers the route and domain router together. The router
then resolves both database-backed services lazily inside each request, after
the middleware principal is validated; it never resolves or memoizes them
while routes are built, preserving the #1611 database lifecycle invariant.
A truly absent package omits both route and router. Installed classes with a
missing, failing, or wrong-type binding are a broken deployment and return a
sanitized, correlated 503 rather than masquerading as package absence. API uses Composer
suggest+conflict+require-dev metadata to make optional presence version-safe
without forcing either package into `waaseyaa/cms`.

The controller depends only on API-owned read-model/rate-limiter ports and
validated local query/page DTOs. Optional Auth/Search contracts are verified
and invoked behind string-resolved adapters, then translated field-by-field.
Two-sided Composer conflict bounds plus real require-dev integration coverage
make that runtime coupling explicit without creating a hard package edge.

Every admitted search atomically consumes a deployment-global bucket and a
bounded identity bucket. Anonymous traffic uses one fixed bucket; authenticated
traffic uses a SHA-256 digest of immutable principal identity and scope. No IP
or forwarding header enters the key, avoiding trusted-proxy spoofing, load
balancer collapse, IPv6 bucket multiplication, and unbounded attacker-created
keys. The global ceiling bounds aggregate work even when accounts are cheap.
Limiter failure is a sanitized 503 and exhaustion a 429 with a conservative
`Retry-After` equal to the configured fixed window.

Input is a closed set: `q`, `page`, `page_size`, `topic`, `content_type`,
`source`, `min_quality`, `sort`, `order`, and `facets`. Query text and filter
bounds are inherited from public Search DTOs, page is additionally capped at
200, and unknown or malformed parameters return a JSON:API 400 before limiter
consumption. Responses use `application/vnd.api+json`, `Cache-Control:
no-store`, resource objects with stable `type`/`id`, closed hit attributes,
and JSON:API error documents for every refusal. `HEAD` performs the same
authorization/rate decision but returns an empty body.

The explicit route is registered before generic JSON:API entity routes and
with higher priority, so an entity type or resource named `content/search`
cannot shadow it. Enabling the endpoint also adds its path to the anonymous
stateless-session inventory; authenticated cookie requests still resume their
session under the existing middleware contract.

## Red-first coverage

1. Pin opt-in and absent-package behavior, compatible optional dependency
   metadata, zero route-time service resolution, request-time broken-binding
   503 behavior, exact route/method/media contract, and route precedence.
2. Pin anonymous and authenticated principal propagation, closed response
   projection, safe provider-owned facets/counts, and no development fallback.
3. Pin global plus identity atomic consumption, fixed anonymous keys regardless
   of forwarding headers, 429/503 refusal, and handler-never-called behavior.
4. Pin input bounds, deep pagination, JSON:API error shapes, HEAD, no-store, and
   automatic stateless-path registration.
5. Drive a real FTS provider through the controller with denied/raw-only
   candidates and prove they affect no hit, count, page, or facet.

## Deferred

- MCP search is #2194.
- MCP content resources and templates are #2197.
- Upstream edge/WAF fairness controls are deployment concerns; the framework
  limiter deliberately protects aggregate compute without trusting client IP.
