# waaseyaa/inertia

> **Optional / experimental — not the primary workspace UI.** Per charter directive
> **DIR-007** (`docs/governance/charter.md`), the committed workspace UI is the Nuxt SPA
> in `packages/admin/`. This L6 adapter is supported for distributions that prefer
> server-driven UI, but is **not** bundled by `waaseyaa/full`.

**Layer 6 — Interfaces**

Server-side Inertia.js v3 protocol adapter. A controller returns
`Inertia::render($component, $props)`, producing an `InertiaResponse` that carries the
Inertia page object. `InertiaMiddleware` reads the `X-Inertia-*` headers to tell an
initial full-page load (HTML via `RootTemplateRenderer`) from an XHR navigation (JSON
page object), and returns `409` + `X-Inertia-Location` on an asset-version mismatch.
Implements the foundation contracts `InertiaPageResultInterface` and
`InertiaFullPageRendererInterface`.

> **Partial reloads are not yet wired (experimental).** `OptionalProp` and
> `PropResolver` are provided as standalone building blocks for deferring expensive
> props (`PropResolver::resolve($props, $only, $except)`), but nothing in this package
> invokes them: `InertiaMiddleware` reads only `X-Inertia` / `X-Inertia-Version` (not
> the `X-Inertia-Partial-Data` partial-reload header), and `InertiaResponse` carries the
> full prop set unchanged. So a partial reload currently returns **all** props, not just
> the requested keys. Honoring `only` / `except` automatically is unbuilt — call
> `PropResolver` directly in a controller if you need it today.

## Install

Ships in the `waaseyaa/framework` metapackage but is not pulled in by `waaseyaa/full`;
add it explicitly: `composer require waaseyaa/inertia`. Register
`Waaseyaa\Inertia\InertiaServiceProvider` (auto-discovered via `extra.waaseyaa.providers`)
to wire the renderer and HTTP middleware.

## Key API

```php
// Inertia — static response factory + shared-prop / version state.
Inertia::setVersion(string $version): void
Inertia::getVersion(): string
Inertia::setRenderer(RootTemplateRenderer $renderer): void
Inertia::getRenderer(): RootTemplateRenderer
Inertia::share(string $key, mixed $value): void
Inertia::render(string $component, array $props, bool $encryptHistory = false, bool $clearHistory = false): InertiaResponse
Inertia::reset(): void

// InertiaResponse implements InertiaPageResultInterface — readonly: component, props,
//   url, version, encryptHistory, clearHistory, preserveFragment, and the
//   deferred/merge/prepend/deepMerge/once prop sets.
public function toPageObject(): array

// InertiaMiddleware — #[AsMiddleware(pipeline: 'http', priority: 20)]
public function __construct(string $version)
public function process(Request $request, HttpHandlerInterface $next): Response

// PropResolver
public static function optional(\Closure $callback): OptionalProp
public function resolve(array $props, array $only = [], array $except = []): array

// RootTemplateRenderer implements InertiaFullPageRendererInterface
public function __construct(?\Closure $template = null, ?ViteAssetManager $assetManager = null)
public function render(array $pageObject): string

// InertiaServiceProvider implements HasMiddlewareInterface
public function register(): void
public function registerWithRoot(?string $root): void
public function middleware(EntityTypeManager $entityTypeManager): array
```

## Usage

```php
Inertia::setVersion('abc123');
Inertia::share('auth', fn() => ['user' => currentUser()]);
$response = Inertia::render('Users/Index', ['users' => [1, 2, 3]]);
$page = $response->toPageObject();
```

Page props override shared props of the same key; shared closures resolve fresh per
`render()`; `toPageObject()` always injects `props.errors`.
