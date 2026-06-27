# waaseyaa/analytics

**Layer 0 — Foundation**

Shared Umami analytics integration for Waaseyaa apps.

Provides a server-side `UmamiClient` that proxies pageview and event records through the host server (avoiding browser-side ad-blocker collisions) and a tiny JS helper for client-side event reporting. No tracking scripts are loaded automatically — hosts opt in by injecting the bundled Twig partial and configuring the Umami URL.

Key classes: `UmamiClient`, `Transport` (interface), `StreamTransport` (default implementation).

## Extension points

`UmamiClient` accepts two optional trailing constructor parameters that do not affect existing callers:

- **`?Transport $transport = null`** — replaces the built-in `StreamTransport` (a `file_get_contents` + `stream_context_create` POST). Implement `Waaseyaa\Analytics\Transport` to swap in a different HTTP backend (e.g. cURL, async, test spy). `Transport` is marked `@api`.
- **`?\Closure $logger = null`** — a zero-dependency logger sink with the signature `(string $message, array $context = []): void`. When provided, it receives a message on both the misconfig early-return path (empty `trackerUrl`/`siteId`) and any failed-send or transport-exception path. `null` (default) preserves the original silent fail-open behaviour.

This package has **no `waaseyaa/*` dependencies** — `require` is `php >=8.5` only.
