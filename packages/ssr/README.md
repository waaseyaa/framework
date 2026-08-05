# waaseyaa/ssr

**Layer 6 — Interfaces**

Server-side rendering layer for Waaseyaa applications.

Renders entity and page content as HTML using Twig templates. `SsrPageHandler` handles path alias resolution, editorial visibility checks, language negotiation, and cache headers. `RenderController` resolves template candidates (entity-specific, path-based, or fallback). `ThemeServiceProvider` manages the Twig environment with a theme chain loader. `EntityRenderer` produces field bags consumed by entity templates.

In persistent PHP workers, each fresh `HttpKernel` owns a fresh Twig environment. Theme and SSR providers publish and capture that instance-owned environment during registration, before extension-provider boot, so a later request never resolves the previous kernel's initialized renderer.

`EntityRenderer::render()` accepts the request's `AccountInterface` and is constructed with `SsrPageHandler`'s own `EntityAccessHandler`; when an account is supplied it filters the entity **`fields` bag** through `EntityAccessHandler::filterFields($entity, ..., 'view', $account)` before formatting, on top of the always-internal-field/`internal`-setting exclusions — so a field a `FieldAccessPolicyInterface` policy forbids for the viewing account (e.g. classification/clearance) is dropped from the field loop (`entity.html.twig` prints every remaining field via `{{ field.formatted|raw }}`). `SsrPageHandler::renderEntityHtml()` is the enforcement seam: it threads the account and `$this->accessHandler` through `RenderController::renderEntity()` into `EntityRenderer::render()`, and fails closed with a 500 rather than rendering unfiltered output when no access handler is wired (audit M1 / R6 PR1). Scope caveat: this covers the `fields` bag only — the entity label/title (emitted via the `<title>` block and the schema.org JSON-LD, both reading `$entity->label()` directly from storage) is not field-access-filtered here; closing that cross-package label channel is a follow-up (R7), consistent with the Markdown H1's existing behavior.

The `?raw` / `Accept: text/markdown` representation renders via `SsrPageHandler::renderEntityMarkdown()`, which delegates to `waaseyaa/api`'s `EntityMarkdownPresenter`. That presenter requires a non-null `EntityAccessHandler` and viewing `AccountInterface` — `renderEntityMarkdown()` threads the request's account and `SsrPageHandler`'s own `$accessHandler` through so the same per-account field filter that gates JSON:API/HTML also gates Markdown (see `docs/specs/api-layer.md` WP4 note); an unwired access handler fails closed with a 500 rather than rendering unfiltered content.

`RenderCache` keys are versioned via `RenderCache::SCHEMA_VERSION` (folded into `RenderCache::buildKey()`) so a payload-shape change like the field-filtering fix above makes every previously-cached entry unreachable under the new key, forcing a re-render through the fixed path instead of continuing to serve stale cached HTML.

Applications may bind `Waaseyaa\SSR\PageComposition\EntityPageComposerInterface` to wrap an authorized generic entity page in application chrome. The composer receives only an immutable `EntityPageRenderPayload`: the access-checked title, normalized inbound path, type/bundle/view/language metadata, schema.org JSON-LD, and formatter-produced strings for fields that survived field access. Its one structure-preserving channel, `bodyCompositionHtml`, is created after field access and sanitized while retaining safe CSS classes and relative media/link URLs; forbidden, missing, array, and object bodies yield an empty string. It never receives an entity, account, arbitrary raw field bag, repository, or template name. The seam is HTML-only and runs after alias resolution and all existing editorial/entity access gates. No binding or a deliberate `null` return uses the framework renderer. Resolution failure, exceptions, empty content, redirects, non-200 output, and explicitly non-HTML output fall back to that complete renderer as `private, no-store`; accepted composed documents are also non-shareable until the contract can represent app-shell cache dependencies. See `docs/specs/ssr-page-composition.md`.

The intermediate render result preserves application response headers except
for the transport-owned `Date` header. `SsrRouter` constructs the actual
outbound Symfony response and therefore owns its final date; retaining the
composer or fallback response's wall-clock value would make otherwise
identical render results timing-dependent and could persist a stale date.

Twig functions: `asset()`, `env()`, `config()` (when wired), `csrf_token()` (when User middleware present).

Key classes: `SsrPageHandler`, `RenderController`, `ThemeServiceProvider`, `EntityRenderer`, `EntityPageComposerInterface`, `EntityPageRenderPayload`, `WaaseyaaExtension`.

Typed app-controller entity parameters are an access boundary: the invoker
accepts `HttpKernel`'s upcast entity (or repository-loads a raw id for direct
callers), then requires the request gate to allow `view`. Missing, denied, and
unresolvable entities all produce the same 404. Custom method-service parameters
resolve through `HttpServiceResolverInterface::resolve()`.
