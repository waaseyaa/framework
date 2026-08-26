# App controller invocation (SSR `Class::method`)
<!-- Spec reviewed 2026-08-26 - #2569: SsrServiceProvider now supplies SsrPageHandler with the binding-aware EditorialVisibilityResolver used by ordinary entity rendering. This changes workflow publication resolution only; app-controller discovery, constructor/method argument resolution, invocation, and error mapping are unchanged. -->
<!-- Spec reviewed 2026-08-22 - #2501: SeoPublicController's constructor gains four optional trailing parameters (PublicUrlPolicyInterface, CrawlEligibilityPolicyInterface, SitemapContributorInterface, DiscoveryFailurePolicy). They are supplied by the EXISTING constructor-injection path in SsrPageHandler::resolveControllerInstance(), which already falls back to the HttpServiceResolverInterface for any non-builtin typed parameter and then to the declared default; no new injection channel, allowlist entry, or descriptor-cache input is introduced, and the resolution order is unchanged. Because all four are nullable with defaults, a controller instantiated where nothing is bound resolves them to null exactly as it does CanonicalPublicOrigin today, so existing consumers and the strict-mode contract are unaffected. The action methods (robotsTxt/sitemapXml/llmsTxt) still take no arguments, so no request-derived value can reach the canonical origin or a URL policy. Under DiscoveryFailurePolicy::Propagate an application policy's Throwable now leaves the controller rather than being absorbed; it is handled by the same controller-exception mapping documented under "Error -> HTTP" and introduces no new status semantics. App-controller discovery, argument resolution, and error behavior are otherwise unchanged. -->
<!-- Spec reviewed 2026-08-20 - #2151: SeoPublicController constructor gains an optional trailing CanonicalPublicOrigin (trusted config / APP_URL only). robotsTxt() still takes no request arguments, so Host / Forwarded / X-Forwarded-Host cannot select the Sitemap origin. App-controller discovery, argument resolution, invocation, and error behavior are unchanged. -->
<!-- Spec reviewed 2026-08-16 - #2113: SsrServiceProvider threads the boot-scoped InternalFieldVisibilityPolicy into SsrPageHandler's Markdown ResourceSerializer. This only unifies field projection metadata with Admin/JSON:API; application-controller discovery, argument resolution, invocation, and error behavior are unchanged. -->


<!-- Spec reviewed 2026-08-07 - #2291: app controllers gain an opt-in,
request-scoped redirect surface. Plain controllers may inject
Waaseyaa\Routing\Redirector; controllers that prefer Drupal-style ergonomics
may extend the thin Waaseyaa\Routing\Controller base. Named redirects use the
same WaaseyaaRouter instance that matched the request. Direct targets are
local absolute paths validated by Waaseyaa\Access\RedirectValidator. -->

## Scope

SSR app controllers are invoked through `Waaseyaa\SSR\SsrPageHandler::dispatchAppController` after a Symfony `Route` match. This spec defines **typed method arguments** only: the legacy four-argument `($params, $query, $account, $httpRequest)` contract is removed.

## Strict mode

- Configuration: `config['app_controller']['strict']` or `WAASEYAA_APP_CONTROLLER_STRICT` env. Default **strict ON** (treat unset / non-`false` as strict).
- In strict mode:
  - No implicit raw route/query bags; use `#[MapRoute]` / `#[MapQuery]` on `array` parameters when needed.
  - Content entity parameters require `#[ContentEntityType]` on the entity class (see `waaseyaa/entity`).
  - Ambiguous parameter bindings fail at **descriptor build** time (before invoke).

## Service injection (allowlist)

Method parameters resolved as services **only** for these types (or subtypes where `is_a` applies):

- `Symfony\Component\HttpFoundation\Request`
- `Waaseyaa\Access\AccountInterface`
- `Waaseyaa\Entity\EntityTypeManagerInterface` and `Waaseyaa\Entity\EntityTypeManager`
- `Twig\Environment`
- `Waaseyaa\Access\Gate\GateInterface` when the kernel supplies a gate

Additionally, the existing HTTP **service resolver** closure may satisfy a parameter by **exact interface/class name** (same rules as controller constructor resolution). Duplicate identical service types in one method signature are invalid in strict mode.

`Waaseyaa\Routing\Redirector` is also a built-in request service. The HTTP
kernel constructs it from the fully registered `WaaseyaaRouter` that matched
the request, then app-controller constructor and method-argument resolution
both expose that same instance. It is never resolved while providers register
routes and is never retained across requests.

## Redirect responses

`Waaseyaa\Routing\Redirector` is the composition-first API:

- `to(string $path, int $status = 302, array $headers = [])` returns a
  `Waaseyaa\Routing\RedirectResponse` only for a safe local absolute path.
  The response is transport-compatible with Symfony while keeping app
  signatures on Waaseyaa's public surface. Empty, scheme-bearing,
  protocol-relative, backslash-containing, and ASCII-control-containing
  targets throw `InvalidArgumentException`; they are never silently replaced
  by a fallback.
- `toRoute(string $name, array $parameters = [], int $status = 302,
  array $headers = [])` generates the target through the request's registered
  `WaaseyaaRouter`, preserving its missing-route, missing-parameter, and
  parameter-validation exceptions, then applies the same local-target check.

Applications that prefer a Drupal-style controller surface may extend the
optional `Waaseyaa\Routing\Controller`, whose protected `redirect()` and
`redirectToRoute()` methods delegate to its injected `Redirector`. The base
owns no container and exposes no entity-manager, account, configuration,
translation, rendering, or service-locator shortcuts. Plain and `final`
controllers remain first-class and inject `Redirector` directly, including as
an action parameter.

There is deliberately no global helper, trait with hidden mutable state, or
external-URL redirect convenience. External destinations require a separate
explicit trust/allowlist contract.

## Route-derived values

- `#[FromRoute('name')]` binds from the matched route attribute `name`.
- Without `#[FromRoute]`, the route attribute key defaults to the **parameter name** (e.g. `$todo` → `todo`), with camelCase → snake_case as an additional candidate.
- Scalars: invalid cast → **400** (`InvalidAppControllerArgumentException`).
- Entities: accept the entity already upcast by `HttpKernel`'s `EntityParamConverter`, or load a raw id through `EntityTypeManagerInterface::getRepository($entityTypeId)->find($rawId)` for direct invoker callers. Binding then requires the invocation context's `GateInterface` to allow `view` for the request account. A missing entity, denied view, or missing gate all collapse to the same **404** (`Symfony\Component\Routing\Exception\ResourceNotFoundException`), so typed parameter injection cannot become an existence oracle or bypass entity access.
- Route option `parameters.{name}.type = entity:{entityTypeId}` declares an entity segment. Optional `_waaseyaa_app_bindings.{name}` stores an expected PHP `class-string` for validation after load.

## Error → HTTP

| Condition | Exception | HTTP |
|-----------|-----------|------|
| Entity not found for id | `ResourceNotFoundException` | 404 |
| Entity view denied / gate unavailable | `ResourceNotFoundException` | 404 |
| Invalid scalar / enum | `InvalidAppControllerArgumentException` | 400 |
| Type / binding programmer error | `InvalidAppControllerBindingException` / `AppControllerTypeMismatchException` | 500 |

Response shape follows existing conventions: JSON:API errors for non-`_render` routes; HTML error page when `_render` is true (align with authorization middleware split).

## Descriptor cache key

Cached reflection metadata **must** include:

- Controller class + method name
- Route name (`_route`) when present
- Fingerprint of route data affecting binding: path, methods, `options['parameters']`, `options['_waaseyaa_app_bindings']`, and defaults that supply parameter names

See `Waaseyaa\Routing\RouteFingerprint`.

## Extension

`Waaseyaa\SSR\Http\AppController\AppControllerArgumentResolver`: optional plugins run **after** built-in resolution fails to produce a value for a parameter (see interface docblocks).
