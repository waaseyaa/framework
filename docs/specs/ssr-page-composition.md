# Generic SSR entity-page composition

<!-- Spec reviewed 2026-07-23 - #2117: first-class application shell composition for generic SSR entity routes. -->

## Purpose

Generic SSR entity routes retain framework ownership of routing,
authorization, field filtering, content negotiation, and cache policy while
allowing an application to own the successful HTML document shell. An
application opts in by binding:

```php
Waaseyaa\SSR\PageComposition\EntityPageComposerInterface
```

The interface is:

```php
public function compose(EntityPageRenderPayload $page): ?Response;
```

A returned response supplies the application document. `null` deliberately
selects the framework's existing entity renderer for that page. With no
binding, `SsrPageHandler` calls the pre-existing renderer directly; content,
status, and response headers are unchanged.

## Ordering and authority boundary

For a generic entity HTML request, `SsrPageHandler` preserves this order:

1. language and alias lookup-path resolution;
2. published path-alias resolution or canonical `/{type}/{id}` fallback;
3. entity load;
4. editorial visibility and preview eligibility;
5. generalized R6/R8 entity `view` access;
6. authorized working-copy selection, when applicable;
7. relationship endpoint visibility filtering and workflow render context;
8. access-checked label and schema.org metadata;
9. per-account field filtering and field formatting;
10. optional application composer construction and page composition.

The composer is not resolved or invoked for unresolved/missing paths, existing
editorial or entity-access denials, path templates, or Markdown negotiation.
The production resolver's non-instantiating `hasBinding()` probe may run after
step 8 so an unregistered install can retain its legacy cache fast path. When
a binding exists, its provider factory (`resolveBound()`) runs only after step
9 has produced the authorized payload. Bound factory failure is distinct from
no binding and follows the private failure-fallback contract below.
This feature does not change the existing status contract: unresolved/missing
generic entities use the existing 404 renderer, while the currently pinned
editorial and R6/R8 denial branches use the existing 403 renderer. Their
content and headers remain outside application composition.

The composer has no authorization, routing, preview, entity-selection, or
canonical-redirect role. It is a presentation callback after those decisions.

## Safe payload

`EntityPageRenderPayload` is immutable and exposes:

- `title`: the result of `EntityAccessHandler::viewableLabel()`, with the
  existing entity-type fallback;
- `requestPath`: the normalized inbound path; an alias remains an alias;
- `entityType`, `bundle`, `viewMode`, and resolved `langcode`;
- `fields`: an associative map of immutable `EntityPageField` values;
- `schemaOrgJsonLd`: the framework-generated, access-safe script fragment.
- `bodyCompositionHtml`: a structure-preserving, framework-sanitized version
  of the authorized string `body` field for a code-owned application
  normalizer.

Each `EntityPageField` contains only `name`, definition `type`, and the
formatter-produced `formatted` string. `field($name)` returns one authorized
fragment and `bodyHtml()` returns the formatted `body` fragment or an empty
string.

The generic field map never contains a raw value. `bodyCompositionHtml` is the
one deliberate structure-preserving channel: it is created only after `body`
survives the per-account field-access filter and only when its source is a
string. The framework sanitizer preserves safe elements, CSS classes, and
relative link/media URLs needed by code-owned normalizers, while removing
scripts, styles, event-handler attributes, unsafe URL schemes, and other
unsafe markup. A missing, forbidden, array, or object body produces an empty
string. An application may pass this sanitized fragment through a structural
normalizer such as SFN's `MigratedBodyRenderer::renderEditorialBody()` and
then render that result as HTML; `bodyHtml()` remains the framework formatter's
default representation.

The payload never contains an `EntityInterface`, account/session object,
arbitrary raw field bag, repository, access handler, template suggestion, or
mutable Twig context. The framework's internal renderer may continue to use
its richer bag; the composition mapper copies only the allowlisted values
above. Application templates are selected in application code. Stored content
cannot choose a template through this contract.

No public raw-bag renderer is introduced. `RenderController` continues to
accept an entity plus account and delegates field enforcement to
`EntityRenderer`; callers cannot submit their own formatted values or template
suggestions to bypass that boundary. Within the request, `SsrPageHandler`
privately reuses the single authorized/formatted bag when a registered
composer declines or fails, so a stateful formatter cannot produce different
fallback bytes on a second pass.

## Response acceptance and fallback

Composer output is accepted only when all of the following hold:

- status is exactly 200;
- content is a non-empty string after trimming;
- `Content-Type` is absent, `text/html`, or `application/xhtml+xml`.

Composer service-resolution failures, thrown exceptions, empty content,
redirects, non-200 responses, and explicitly non-HTML content all log a
payload-free diagnostic and fall back to the complete framework entity
renderer. Resolution failures and invalid/throwing composer fallbacks are
`private, no-store`, receive no public surrogate keys, and are not persisted
in `RenderCache`, so a transient application failure cannot become sticky in
a browser, CDN, or framework cache. A deliberate `null` decline retains
ordinary framework caching.

Accepted application headers flow through the normal response path. The
framework merges `Accept` into any application `Vary` value. Because an
application shell can depend on navigation, theme configuration, session
decorators, or other state that the entity-only cache cannot tag, every
accepted composed document is `private, no-store`, receives no public
surrogate keys, and is not persisted in `RenderCache`. Application response
headers otherwise remain intact. A future shared-cache opt-in requires a
separate dependency-metadata contract.
Repeatable response headers remain repeatable across the handler/router
boundary; in particular, multiple `Set-Cookie` values are not flattened.
The intermediate handler result excludes Symfony's automatically generated
`Date` header. `SsrRouter` constructs the final outbound response and owns its
transport date, keeping intermediate render output deterministic without
removing HTTP response dating.

## Cache partition

With no composer binding, the complete pre-contract HTML variant payload and
hash remain unchanged. A registered composer adds deterministic hashes of its
implementation class and normalized inbound request path. This prevents a
cacheable deliberate decline on an alias from crossing into another alias or
canonical path, while changing implementations produces a new variant.
Accepted composed documents are currently non-persistent as described above.
Markdown ignores composer identity and path.

`RenderCache::SCHEMA_VERSION` is `v6`, making pre-contract `v5` documents
unreachable on deployment.

## Application registration

The application service provider binds the interface to its code-owned
adapter:

```php
$this->singleton(EntityPageComposerInterface::class, function (): EntityPageComposerInterface {
    return new SiteEntityPageComposer(
        $this->resolve(SiteRenderer::class),
        $this->resolve(MigratedBodyRenderer::class),
    );
});
```

An SFN-shaped adapter uses `bodyCompositionHtml` as input to its
application-owned body normalizer, then passes the resulting string, `title`,
and `requestPath` to its own shell renderer. Apps that do not need the
structure-preserving transform use `bodyHtml()` directly. No framework route
or generic Twig template is shadowed.
