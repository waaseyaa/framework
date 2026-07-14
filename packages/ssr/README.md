# waaseyaa/ssr

**Layer 6 — Interfaces**

Server-side rendering layer for Waaseyaa applications.

Renders entity and page content as HTML using Twig templates. `SsrPageHandler` handles path alias resolution, editorial visibility checks, language negotiation, and cache headers. `RenderController` resolves template candidates (entity-specific, path-based, or fallback). `ThemeServiceProvider` manages the Twig environment with a theme chain loader. `EntityRenderer` produces field bags consumed by entity templates.

`EntityRenderer::render()` accepts the request's `AccountInterface` and is constructed with `SsrPageHandler`'s own `EntityAccessHandler`; when an account is supplied it filters the entity **`fields` bag** through `EntityAccessHandler::filterFields($entity, ..., 'view', $account)` before formatting, on top of the always-internal-field/`internal`-setting exclusions — so a field a `FieldAccessPolicyInterface` policy forbids for the viewing account (e.g. classification/clearance) is dropped from the field loop (`entity.html.twig` prints every remaining field via `{{ field.formatted|raw }}`). `SsrPageHandler::renderEntityHtml()` is the enforcement seam: it threads the account and `$this->accessHandler` through `RenderController::renderEntity()` into `EntityRenderer::render()`, and fails closed with a 500 rather than rendering unfiltered output when no access handler is wired (audit M1 / R6 PR1). Scope caveat: this covers the `fields` bag only — the entity label/title (emitted via the `<title>` block and the schema.org JSON-LD, both reading `$entity->label()` directly from storage) is not field-access-filtered here; closing that cross-package label channel is a follow-up (R7), consistent with the Markdown H1's existing behavior.

The `?raw` / `Accept: text/markdown` representation renders via `SsrPageHandler::renderEntityMarkdown()`, which delegates to `waaseyaa/api`'s `EntityMarkdownPresenter`. That presenter requires a non-null `EntityAccessHandler` and viewing `AccountInterface` — `renderEntityMarkdown()` threads the request's account and `SsrPageHandler`'s own `$accessHandler` through so the same per-account field filter that gates JSON:API/HTML also gates Markdown (see `docs/specs/api-layer.md` WP4 note); an unwired access handler fails closed with a 500 rather than rendering unfiltered content.

`RenderCache` keys are versioned via `RenderCache::SCHEMA_VERSION` (folded into `RenderCache::buildKey()`) so a payload-shape change like the field-filtering fix above makes every previously-cached entry unreachable under the new key, forcing a re-render through the fixed path instead of continuing to serve stale cached HTML.

Twig functions: `asset()`, `env()`, `config()` (when wired), `csrf_token()` (when User middleware present).

Key classes: `SsrPageHandler`, `RenderController`, `ThemeServiceProvider`, `EntityRenderer`, `WaaseyaaExtension`.

Typed app-controller entity parameters are an access boundary: the invoker
accepts `HttpKernel`'s upcast entity (or repository-loads a raw id for direct
callers), then requires the request gate to allow `view`. Missing, denied, and
unresolvable entities all produce the same 404. Custom method-service parameters
resolve through `HttpServiceResolverInterface::resolve()`.
