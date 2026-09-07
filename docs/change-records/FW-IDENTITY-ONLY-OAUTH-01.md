# FW-IDENTITY-ONLY-OAUTH-01 — identity-only OAuth capability

- Parent: `62424ff04f6af8bd37b3f2dba4fd1db74bad2c8b`
- Parent tree: `0fc8ffa4a9d0fffd826782b6902e796464b261d7`
- Contract: `docs/specs/oauth-provider.md` (new)
- Forge mirror: `waaseyaa/framework#2978`
- Authority: local source changes, synthetic HTTP-double verification, and an
  unmerged review candidate only. No release, publication, deployment, or
  live OAuth account changes are authorized by this record.

## Problem

`waaseyaa/oauth-provider` v0.1.0-alpha.300 gives Studio no way to authenticate
a stable provider subject without over-requesting:

- `GoogleOAuthProvider::getAuthorizationUrl()` hardcodes `access_type=offline`
  and `prompt=consent`. The interface only accepts scopes/state, so a consumer
  cannot request online, non-forced-consent access without rewriting the
  adapter's URL or forking it.
- `GitHubOAuthProvider::getUserProfile()` unconditionally calls
  `GET /user/emails` after `GET /user`, even when the consumer only needs the
  stable numeric subject. GitHub's authenticated-user endpoint already
  returns the owner's public profile (including `id`) for a token carrying no
  extra scope: <https://docs.github.com/en/rest/users/users#get-the-authenticated-user>.
- Google's profile parsing directly indexed optional `email`/`name` fields
  with `(string) $data['email']` / `(string) $data['name']` and no `isset()`
  guard (unlike the existing guard on `avatarUrl`); a missing key warned
  ("Undefined array key"), and a malformed non-string value (e.g. an array)
  warned ("Array to string conversion") and coerced into the literal string
  `'Array'`. GitHub's `name`-fallback branch had the same defect one level
  deeper: `(string) $userData['login']` ran unconditionally whenever `name`
  was absent/empty, so a response also missing or malforming `login` hit the
  identical two failure modes. Confirmed live: this repo's `phpunit.xml.dist`
  sets `failOnWarning="true"`, so a real run turns either defect into a test
  failure, not a silently-ignored notice.

## Decision

Add explicit, validated, additive configuration — no catch-all parameter bag,
no URL rewriting hook, no interface break:

- **`Waaseyaa\OAuthProvider\Provider\GoogleAccessType`** — a new backed enum
  (`Offline = 'offline'`, `Online = 'online'`) marked `@api`.
- **`GoogleOAuthProvider`** constructor gains two optional trailing
  parameters: `GoogleAccessType $accessType = GoogleAccessType::Offline` and
  `bool $forceConsent = true`. The existing 4-argument constructor call shape
  keeps compiling and keeps producing byte-identical authorization URLs
  (`access_type=offline` + `prompt=consent`, same query-parameter order).
  Setting `accessType: GoogleAccessType::Online` sends `access_type=online`;
  setting `forceConsent: false` omits the `prompt` parameter entirely instead
  of substituting an alternate value, per
  <https://developers.google.com/identity/protocols/oauth2/web-server>
  ("Online" access type is the API default when the parameter is absent;
  omitting `prompt` lets Google decide whether re-consent is needed instead of
  a framework-chosen policy value). `refreshToken()` is unchanged and stays
  available for offline consumers; online configuration changes the authorization request only. Token responses remain parsed as returned; existing refresh tokens are not revoked or deleted, and the consumer still owns token retention.
- **`GitHubOAuthProvider`** constructor gains one optional trailing
  parameter: `bool $fetchEmail = true`. When `true` (default), the existing secondary email lookup remains enabled. When `false`,
  the `GET /user/emails` request is never issued — only `GET /user` is
  called — and the returned profile carries `email: ''`,
  `emailVerified: false`. Stable subject extraction remains unchanged; optional name parsing is
  hardened below, and absent avatar fields continue to return null.
- The pre-existing fail-loud checks (non-2xx response, missing/empty `id`)
  are untouched on both providers — an identity-only configuration still
  refuses a failed or unidentifiable response instead of returning a
  degenerate profile.
- **Optional-field hardening** (both providers): `GoogleOAuthProvider` now
  extracts `email`/`name` as `isset($data[...]) && is_string($data[...]) ?
  $data[...] : ''` instead of an unconditional `(string)` cast.
  `GitHubOAuthProvider`'s `name` fallback now checks
  `isset($userData['login']) && is_string($userData['login'])` before using
  it, falling back to `''` rather than casting a missing or non-string
  `login`. Both changes only touch the *optional*-field path; the required
  `id`/`providerId` check (non-2xx refusal, empty/missing `id` refusal) is
  unchanged and still throws `\RuntimeException` before either identity-only
  or optional-field logic runs.

Rejected: a generic `array $extraAuthParams` / `array $options` bag on either
provider (arbitrary URL rewrite, no validation, defeats the "no catch-all
policy parameters" constraint) and a provider-level `IdentityOnlyMode` toggle
that would silently also change scopes or refresh eligibility (conflates
concerns the interface already separates: the caller chooses scopes, the
provider only chooses whether the identity call fetches email as a
secondary lookup).

## Proof

Actual local run, `php -d memory_limit=1G vendor/bin/phpunit --no-coverage
packages/oauth-provider/tests` (root-installed candidate-local locked
dependencies; no `composer.json`/`composer.lock` change in this record):

- **First real run** (this record's initial 5-file candidate, before this
  repair pass): 33 tests, 108 assertions, 1 error —
  `Call to undefined method
  Waaseyaa\OAuthProvider\Tests\Provider\GitHubOAuthProviderTest::stringContainsString()`
  at `GitHubOAuthProviderTest.php:189`. That method never existed on
  `PHPUnit\Framework\TestCase`; the intended mock-argument constraint is the
  static factory `self::stringContains(...)`, but a loose substring match
  would have also passed for the wrong endpoint. Fixed by asserting the
  literal endpoint instead — `->with('https://api.github.com/user')` —
  matching the exact-URL pattern the pre-existing Google/GitHub tests
  already use for `post()`/`get()` assertions. Confirmed green in isolation:
  33 tests, 115 assertions, 0 failures.
- **Optional-field discriminating tests added** (RED, before the source
  fix above): 38 tests, 131 assertions, 2 failures, 6 warnings — exactly the
  Google missing/non-scalar-`email`/`name` cases and the GitHub
  missing/non-scalar-`name`+`login` cases; each failure/warning pair traces
  to the exact `(string) $data[...]` / `(string) $userData['login']` line
  named in Problem above. The one non-failing new case
  (`testGetUserProfileHandlesNullEmailAndName`) passed even pre-fix because
  an existing-but-`null` key does not trigger "undefined array key" and
  `(string) null` is already `''` — confirming the defect is specifically
  *absence*/*malformed-type*, not *null*.
- **After the optional-field source fix**: 38 tests, 133 assertions, 0
  failures, 0 warnings, 0 errors — full green, `packages/oauth-provider/tests`
  only.
- Scope of this run: `packages/oauth-provider/tests` exclusively, per this
  record's authorized scope. No other package's suite, the Architecture
  suite, or the full repository suite was run under this record.

## Scope and residual

This record covers `waaseyaa/oauth-provider` only. Publishing the package,
wiring Studio's consumer code, and confirming Studio's login → verified
principal → permission → canonical receipt path are separately authorized
follow-up work; issue #2978 explicitly stays open until publication and the
Studio follow-up land. No pre-existing Google/GitHub grant is revoked or
narrowed by this change — the issue's acceptance criteria call that out as a
documentation concern, not a code concern, and no code in this record touches
already-granted scopes.

`packages/oauth-provider/public-surface.php` registers the new public
`Provider\GoogleAccessType` enum. Existing unrelated declaration omissions
are not expanded by this change.

Review boundary: malformed non-string avatar values and malformed subject
values retain pre-existing casts; this change does not claim general upstream
payload validation. The optional-field warning proof covers email/name/login.
