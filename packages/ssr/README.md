# waaseyaa/ssr

**Layer 6 — Interfaces**

Server-side rendering layer for Waaseyaa applications.

Renders entity and page content as HTML using Twig templates. `SsrPageHandler` handles path alias resolution, editorial visibility checks, language negotiation, and cache headers. `RenderController` resolves template candidates (entity-specific, path-based, or fallback). `ThemeServiceProvider` manages the Twig environment with a theme chain loader. `EntityRenderer` produces field bags consumed by entity templates.

The `?raw` / `Accept: text/markdown` representation renders via `SsrPageHandler::renderEntityMarkdown()`, which delegates to `waaseyaa/api`'s `EntityMarkdownPresenter`. That presenter requires a non-null `EntityAccessHandler` and viewing `AccountInterface` — `renderEntityMarkdown()` threads the request's account and `SsrPageHandler`'s own `$accessHandler` through so the same per-account field filter that gates JSON:API/HTML also gates Markdown (see `docs/specs/api-layer.md` WP4 note); an unwired access handler fails closed with a 500 rather than rendering unfiltered content.

Twig functions: `asset()`, `env()`, `config()` (when wired), `csrf_token()` (when User middleware present).

Key classes: `SsrPageHandler`, `RenderController`, `ThemeServiceProvider`, `EntityRenderer`, `WaaseyaaExtension`.
