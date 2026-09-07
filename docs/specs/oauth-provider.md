<!-- Spec created 2026-09-07 - #2978 FW-IDENTITY-ONLY-OAUTH-01: initial canonical spec for waaseyaa/oauth-provider, covering the identity-only Google/GitHub configuration options. No prior canonical spec existed for this package (see README.md for the pre-existing class overview). -->

# oauth-provider

**Layer 0 — Foundation.** `waaseyaa/oauth-provider` is the OAuth 2.0
provider abstraction consumer applications (e.g. Studio) use to authenticate
a user against a third-party identity provider (Google, GitHub, ...) and
extract a stable subject. Its state helper uses the consumer-provided session abstraction; account
and durable session persistence belong to the consumer.

## Contract surface

| Type | Role |
|---|---|
| `OAuthProviderInterface` | Per-IdP contract: `getName()`, `getAuthorizationUrl(scopes, state)`, `exchangeCode(code)`, `refreshToken(refreshToken)`, `getUserProfile(accessToken)`. |
| `ProviderRegistry` | Resolves a registered `OAuthProviderInterface` by name (`register()`, `get()`, `has()`, `all()`). |
| `OAuthStateManager` | Issues/validates anti-CSRF `state` tokens via `SessionInterface`. |
| `OAuthToken` | Value object: `accessToken`, `refreshToken` (nullable), `expiresAt` (nullable), `scopes` (list), `tokenType`. |
| `OAuthUserProfile` | Value object: `providerId` (stable subject), `email`, `name`, `avatarUrl` (nullable), `emailVerified`. |
| `UnsupportedOperationException` | Thrown by a provider's `refreshToken()` when the IdP has no refresh grant (e.g. GitHub). |
| `Provider\GoogleOAuthProvider` | Google implementation. |
| `Provider\GitHubOAuthProvider` | GitHub implementation. |
| `Provider\GoogleAccessType` | Backed enum (`Offline`, `Online`) selecting Google's `access_type` authorization parameter. |

`OAuthProviderInterface`'s five methods are the entire cross-provider
contract. Provider-specific authorization behavior (consent policy, which
secondary profile lookups run) is configured through each concrete
provider's constructor, never through the interface — the interface stays
IdP-agnostic and additive constructor options stay provider-local.

## Identity-only configuration (#2978)

Both bundled providers default to their historical, most-capable behavior.
A consumer that only needs a stable subject (no refresh token, no email
lookup) opts in explicitly and additively; nothing about the interface or
the default construction shape changes.

### Google: online access without forced consent

```php
use Waaseyaa\OAuthProvider\Provider\GoogleAccessType;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;

// Identity-only: Studio never stores a refresh token and does not want
// Google to force a re-consent screen on every login.
$google = new GoogleOAuthProvider(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    httpClient: $httpClient,
    accessType: GoogleAccessType::Online,
    forceConsent: false,
);
```

- `accessType` (default `GoogleAccessType::Offline`) selects the
  `access_type` authorization query parameter (`offline` or `online`). `offline` access requests refresh-token eligibility; issuance also depends
  on Google's grant state and policy, so it is not guaranteed on each login. See
  <https://developers.google.com/identity/protocols/oauth2/web-server>.
- `forceConsent` (default `true`) controls whether `prompt=consent` is sent.
  When `false`, the `prompt` parameter is omitted entirely — the package
  does not substitute an alternate `prompt` value (e.g. `none` or
  `select_account`); alternate prompt values are not exposed by this API.
- The default 4-argument construction (`clientId`, `clientSecret`,
  `redirectUri`, `httpClient`) is unchanged and continues to produce the
  historical `access_type=offline&prompt=consent` authorization URL,
  byte-for-byte, in the same parameter order.
- `refreshToken()` is unaffected and stays available — an offline consumer
  calls it exactly as before. Online configuration changes the authorization request only; token responses
  are still parsed as returned. It neither deletes existing refresh tokens nor
  adds a runtime guard against using them. The consumer owns token retention,
  and Google determines whether a refresh token is valid.

### GitHub: identity-only profile fetch

```php
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;

// Identity-only: Studio only needs the stable numeric GitHub user id.
$github = new GitHubOAuthProvider(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    httpClient: $httpClient,
    fetchEmail: false,
);
```

- `fetchEmail` (default `true`, existing behavior) controls whether
  `getUserProfile()` issues the secondary `GET /user/emails` request.
- When `false`, only `GET /user` is called. GitHub's authenticated-user
  endpoint already returns the owner's public profile — including the
  stable numeric `id` — for a token requesting no scopes at all:
  <https://docs.github.com/en/rest/users/users#get-the-authenticated-user>.
  The returned `OAuthUserProfile` carries `email: ''`,
  `emailVerified: false`; `providerId`, `name` (falling back to `login`
  when GitHub omits `name`), and `avatarUrl` extraction are unaffected.
- This does not change what scopes `getAuthorizationUrl()` requests — the
  caller already controls scopes directly through
  `getAuthorizationUrl(array $scopes, string $state)`. `fetchEmail: false`
  only stops the provider from making an HTTP call the consumer would
  otherwise silently pay for and discard.

### Invariant: identity refusal is unaffected by either flag

Both providers fail loudly — throwing `\RuntimeException` — when the
identity source request itself is unsuccessful, or succeeds but omits the
stable id (`id` for Google/GitHub). This check runs before any optional
secondary lookup and is identical whether or not `fetchEmail`/`accessType`/
`forceConsent` are at their defaults; an identity-only configuration must
never coerce a failed or unidentifiable response into a degenerate profile
with an empty `providerId`.

### Invariant: optional fields never warn

`email`/`name` (Google) and `name`/`login` (GitHub) are read defensively:
missing, `null`, or non-string (malformed) values degrade to an empty string
rather than triggering a PHP "Undefined array key" or "Array to string
conversion" warning. This is independent of the stable-id check above — a
malformed optional field never masks, and is never masked by, a missing or
invalid `id`.

## What this package does not own

- Session/account persistence of the resolved `OAuthUserProfile` — that is
  the consumer's responsibility (see Studio's own login → principal →
  permission → receipt pipeline, tracked outside this package).
- Revoking or auditing previously granted (broader) scopes. Adding a
  narrower identity-only configuration for new logins does not retroactively
  narrow or revoke a grant a user already made under a broader scope
  request; that is a separate, consumer-side concern.
- Publication/release of this package to Packagist — a capability landing
  here is not itself a publication event.
