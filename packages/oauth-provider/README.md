# waaseyaa/oauth-provider

**Layer 0 — Foundation**

OAuth 2.0 provider abstraction for Waaseyaa applications.

`OAuthProviderInterface` is the per-IdP contract (Google, GitHub, Apple, etc.); `ProviderRegistry` resolves a registered provider by name. `OAuthStateManager` issues and validates anti-CSRF state tokens via `SessionInterface`; `OAuthToken` and `OAuthUserProfile` are value objects carried through the OAuth callback. Concrete providers live under `Provider/` and consumer apps register additional ones at boot.

Key classes: `OAuthProviderInterface`, `ProviderRegistry`, `OAuthStateManager`, `OAuthToken`, `OAuthUserProfile`.

## Identity-only configuration

A consumer that only needs a stable provider subject — no refresh token, no
email lookup — configures the bundled providers explicitly. Existing
4-argument construction is unchanged and keeps its historical behavior
(offline access with a forced consent prompt for Google, an email lookup for
GitHub); these options are additive.

```php
use Waaseyaa\OAuthProvider\Provider\GoogleAccessType;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;

// Google: online access, no forced re-consent prompt.
$google = new GoogleOAuthProvider(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    httpClient: $httpClient,
    accessType: GoogleAccessType::Online,
    forceConsent: false,
);

// GitHub: skip the secondary /user/emails lookup.
$github = new GitHubOAuthProvider(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    httpClient: $httpClient,
    fetchEmail: false,
);
```

An offline consumer that stores a refresh token, or a consumer that needs a
verified GitHub email, keeps using the default construction — see
`docs/specs/oauth-provider.md` for the full contract.
