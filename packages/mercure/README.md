# waaseyaa/mercure

**Layer 0 — Foundation**

Mercure hub publisher for real-time SSE push in Waaseyaa.

`MercurePublisher` POSTs JSON updates to a configured [Mercure](https://mercure.rocks) hub, minting a short-lived HS256 JWT (one-hour expiry) for each request. The token follows **least privilege**: its `publish` claim is scoped to the exact topic being published (`publish: ['/notes/42']`), never the `['*']` wildcard — so a leaked token cannot be used to publish to any other topic. The hub URL **must** be `https://`: the token is sent as a bearer header, so a non-https hub would leak it in cleartext and is therefore treated as unconfigured (the POST also pins `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`). Publication is **best-effort**: when the hub URL is unconfigured/non-https or the JWT secret is unset, or the hub returns a non-2xx response, `publish()` returns `false` rather than throwing — so a failed broadcast never crashes the primary request, matching the framework convention for non-critical side effects. Pure side-effect utility — no entity storage, no global state.

## Install

Ships as part of `waaseyaa/framework`; consumers get it via the `core`, `cms`, or `full` metapackage. To pull it on its own:

```bash
composer require waaseyaa/mercure
```

Requires PHP >= 8.5 and the cURL extension. `MercureServiceProvider` is auto-discovered (`extra.waaseyaa.providers`) and binds `MercurePublisher` as a container singleton, reading `config['mercure']['hub_url']` and `config['mercure']['jwt_secret']`.

## Key API

### `MercurePublisher` (`@api`, final)

```php
public function __construct(string $hubUrl, string $jwtSecret)

// Publish $data (JSON-encoded) to $topic. Returns true on a 2xx hub
// response; false if unconfigured, on cURL failure, or non-2xx.
public function publish(string $topic, array $data): bool

// True only when jwtSecret is non-empty and hubUrl is an https:// URL.
public function isConfigured(): bool
```

### `MercureServiceProvider` (final, extends `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`)

Registers `MercurePublisher` as a singleton from the `mercure` config block:

```php
public function register(): void
```

## Usage

Resolve `MercurePublisher` from the container (or construct it directly) and push an update:

```php
use Waaseyaa\Mercure\MercurePublisher;

$publisher = new MercurePublisher(
    hubUrl: 'https://hub.example.com/.well-known/mercure',
    jwtSecret: 'your-hub-jwt-secret',
);

if ($publisher->isConfigured()) {
    $publisher->publish('/notes/42', ['status' => 'updated']);
}
```

Because `publish()` is best-effort, you can call it unconditionally and ignore the return value in fire-and-forget paths — an unconfigured publisher simply returns `false` without side effects. Guard with `isConfigured()` (or inspect the boolean result) when you need to know whether the broadcast was actually attempted.
