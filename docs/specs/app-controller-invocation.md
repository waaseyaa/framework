# App controller invocation (SSR `Class::method`)

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
