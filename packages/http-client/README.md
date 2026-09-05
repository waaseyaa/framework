# waaseyaa/http-client

**Layer 0 — Foundation**

Minimal HTTP client for JSON APIs and webhooks.

`HttpClientInterface` exposes a generic `request(string $method, string $url, …)` plus `get()` / `post()` convenience helpers — other verbs (PUT/DELETE/PATCH/…) go through `request()`; there are no dedicated `put()`/`delete()`/`patch()` methods. `StreamHttpClient` is the production implementation backed by PHP streams. Returns `HttpResponse` value objects (no shared state) and throws `HttpRequestException` on transport failures, including response bodies that exceed the configured size ceiling or end before their declared `Content-Length`. Designed as an injectable seam — tests replace `HttpClientInterface` with a fake rather than mocking PHP's stream layer.

Key classes: `HttpClientInterface`, `StreamHttpClient`, `HttpResponse`, `HttpRequestException`.

## Response framing boundary

HEAD, 1xx, 204, and 304 responses have an empty body regardless of metadata
Content-Length. Other length-delimited bodies stop at that length without
waiting for the peer to close the connection. EOF before the declared length
and timeouts while reading that body fail closed.

PHP's HTTP wrapper dechunks responses and removes Transfer-Encoding before
exposing the stream. The byte ceiling still applies to decoded chunked bodies,
but this transport cannot independently detect a missing chunk terminator. For
connection-close bodies, EOF defines completion; no independent expected length
is available. This change does not claim to validate those hidden framing cases.
