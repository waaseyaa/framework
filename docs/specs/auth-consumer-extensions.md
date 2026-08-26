# Auth consumer extensions

Status: LIVE. Change record: `FW-AUTH-EXT-01`. Forge mirror: #2437.

## Security ownership

Framework packages exclusively own authentication controllers, the `User`
security model, credential hashing and verification, sessions, CSRF, reset and
verification tokens, two-factor state, rate limits, and authorization reads.
An application does not customize auth by replacing one of those services.

## Published auth UI ownership and drift

`scaffold:auth` publishes only the login page, login form, brand panel,
`useAuth` presentation composable, and auth CSS. It never publishes backend
authentication, session, CSRF, credential, token, rate-limit, or two-factor
implementation code. Once published, those presentation files are
application-owned and Framework updates never overwrite them automatically.

The versioned `app/.waaseyaa/scaffold-manifest.json` records, per file, the
stable upstream source path, originating Framework version, digest algorithm,
upstream digest, and reviewed consumer digest. `scaffold:auth --check` is
read-only and deterministically reports `added`, `removed`,
`changed-upstream`, `changed-consumer`, and `conflict` states. Ordinary site
audit treats this as a non-blocking diagnostic; a repository may opt into a
blocking policy with `scaffold:auth --check --strict`.

Missing or malformed manifests never trigger repair or file publication. A
missing manifest alongside consumer auth files and every malformed manifest
return a failure with no write. After manually reviewing and merging upstream
changes, `scaffold:auth --accept-current` advances only the recorded upstream
and consumer baselines. It refuses if any current source or consumer file is
missing and never writes an application file.

## Provider contract

An application service provider implements
`ProvidesAuthExtensionsInterface` and returns one
`AuthExtensionContribution`. The contribution may own any of these typed
slots: registration availability/approval, application-profile persistence,
success redirect selection, auth-mail presentation, and governed initial-role
selection.

Providers are inspected in compiled manifest order. Different slots compose in
that deterministic order. A second owner for one exclusive slot is a boot
error naming the slot and both providers. Invalid contribution objects, unsafe
redirects, unknown roles, incomplete profile handling, and mail presentations
that attempt to replace canonical token variables fail closed.

## Supported seams

- Registration policy sees validated name/mail plus the configured mode. It
  never receives a password or invite/reset/verification token. It may deny or
  require administrator approval; it cannot weaken invite validation.
- Profile handlers receive only the request's `profile` object and, after the
  core user is saved, an immutable user-id/name reference. The application owns
  the linked record; product fields never enter `User`.
- Lifecycle reactions use the existing event dispatcher and typed auth events.
  Event payloads contain identity and disposition metadata, never credentials
  or tokens.
- Redirect policies return a validated same-origin absolute-path value. The
  Framework returns it as response metadata; it never accepts an arbitrary
  request-provided redirect.
- Mail policy may choose subject, Twig templates, and non-reserved branding
  variables. Framework still chooses recipients and constructs action URLs;
  canonical user/action variables overwrite policy variables.
- Initial-role policy returns registered role ids. `RoleRepository` is the
  authority for role definitions and permission expansion; duplicate role ids
  and unknown assignments refuse rather than silently overriding.

## Existing seams and disposition

- `AuthConfig` remains the core registration-mode and token/mail safety
  configuration. Consumer policy layers on it rather than replacing it.
- The Framework event dispatcher is the lifecycle-listener mechanism; no
  parallel listener registry is introduced.
- `ProvidesRolesInterface` and `RoleRepository` remain the governed role
  definition path. Its historical later-provider-wins collision behavior is
  not a supported extension seam and is replaced with conflict refusal.
- Direct service-container replacement of auth internals is accidental and
  unsupported.

## Verification

Unit tests pin every policy input/output, unsafe-value refusal, deterministic
composition, and conflict diagnostic. Controller tests pin security ordering
and prove extensions cannot receive credential/token material. A packaged
consumer enables every slot while an auth regression test proves the current
Framework credential/session invariant remains load-bearing.

CLI tests additionally pin every drift classification, legacy checksum
compatibility, malformed-manifest refusal, packaged-consumer source discovery,
strict-policy exit behavior, and the no-overwrite invariant.
