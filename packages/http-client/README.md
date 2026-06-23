# waaseyaa/http-client

**Layer 0 — Foundation**

Minimal HTTP client for JSON APIs and webhooks.

`HttpClientInterface` exposes a generic `request(string $method, string $url, …)` plus `get()` / `post()` convenience helpers — other verbs (PUT/DELETE/PATCH/…) go through `request()`; there are no dedicated `put()`/`delete()`/`patch()` methods. `StreamHttpClient` is the production implementation backed by PHP streams. Returns `HttpResponse` value objects (no shared state) and throws `HttpRequestException` on transport failures. Designed as an injectable seam — tests replace `HttpClientInterface` with a fake rather than mocking PHP's stream layer.

Key classes: `HttpClientInterface`, `StreamHttpClient`, `HttpResponse`, `HttpRequestException`.
