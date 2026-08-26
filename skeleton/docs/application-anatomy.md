# Application anatomy and ownership

Waaseyaa applications deliberately start with a small `src/`. Authentication,
sessions, authorization, the user security model, and the Admin SPA are supplied
by installed Framework packages so security fixes continue to arrive through
Composer. Your application owns its domain and presentation and extends the
Framework through named provider, policy, routing, and auth-extension contracts.

Use these ownership labels throughout this guide:

- **Framework-owned security core** — update through Composer; do not copy,
  replace, or fork it in the application.
- **Stable application extension point** — implement the named public contract
  or add configuration through the shipped application surface.
- **Consumer-owned presentation or domain code** — edit and test it in this
  repository.

Do not treat a path under `vendor/` as an invitation to copy that code. The
package and class names below are a map to the owning component; the adjacent
extension column is the supported place to customize behavior.

## Where common concerns live

| Concern | Ownership | Framework location | Supported application surface |
|---|---|---|---|
| User identity and credential fields | Framework-owned security core | `waaseyaa/user`, `Waaseyaa\User\User` | Keep product/profile data in an application-owned entity linked by user id. |
| Login, logout, registration, password reset, verification, and 2FA actions | Framework-owned security core | `waaseyaa/auth` controllers | Contribute narrow policy through `ProvidesAuthExtensionsInterface`; never replace a controller. |
| Sessions, CSRF, bearer authentication, and authorization middleware | Framework-owned security core | `waaseyaa/user` and `waaseyaa/access` | Configure documented settings in `config/waaseyaa.php`; do not reorder or duplicate middleware. |
| Framework auth and entity access policies | Framework-owned security core | `waaseyaa/access`, `waaseyaa/user`, and `waaseyaa/auth` | Add application policies under `src/Access/`; do not weaken the framework policies. |
| Auth and OIDC routes | Framework-owned security core | `Waaseyaa\Routing\AuthOidcRouteServiceProvider` | Add application routes in `src/Provider/AppServiceProvider.php`. Do not shadow `/api/auth/*`. |
| Admin SPA and default auth UI | Framework-owned until explicitly published | prebuilt `waaseyaa/admin` assets | Run `scaffold:auth` only when the application intends to own those presentation files. |
| Application providers and routes | Stable application extension point | provider discovery from `composer.json` | Extend `src/Provider/AppServiceProvider.php` or generate another provider with `make:provider`. |
| Domain entities and content types | Consumer-owned presentation or domain code | entity contracts from `waaseyaa/entity` | Put classes in `src/Entity/`; register them through a provider or `config/entity-types.php`. |
| Templates and page presentation | Consumer-owned presentation or domain code | Twig rendering from `waaseyaa/ssr` | Edit `templates/` and thin controllers under `src/Controller/`. |
| Application configuration | Consumer-owned, within documented contracts | Framework defaults and package config readers | Use `config/waaseyaa.php`, `config/entity-types.php`, and `config/services.php`. Do not place secrets in tracked config. |
| Migrations | Consumer-owned presentation or domain code | migration runner from Framework packages | Keep application migrations in `migrations/`; generate one with `make:migration`. |
| Tests | Consumer-owned presentation or domain code | PHPUnit and Framework test helpers | Put focused tests in `tests/Unit/` and boundary tests in `tests/Integration/`. |

## Task paths

### Customize login branding

Preview the presentation files first, then publish them deliberately:

```bash
./vendor/bin/waaseyaa scaffold:auth --dry-run
./vendor/bin/waaseyaa scaffold:auth
./vendor/bin/waaseyaa scaffold:auth --check
```

The command copies login-page, form, brand-panel, composable, and CSS files to
`app/`. Those copies become consumer-owned presentation. Credential handling,
sessions, CSRF, reset tokens, 2FA, rate limiting, and the auth controllers remain
Framework-owned. Do not use `--force` until local changes have been reviewed;
the command does not provide an automatic merge of upstream and consumer edits.
When the check reports upstream drift or a conflict, diff the named Framework
source against the application copy, merge it manually, test the result, then
run `./vendor/bin/waaseyaa scaffold:auth --accept-current`. That final command
updates only the reviewed manifest baseline. Repositories that intentionally
block CI on unresolved drift can run `scaffold:auth --check --strict`.

### Add a profile field

Do not add product fields to `Waaseyaa\User\User`. Create an application-owned
profile entity in `src/Entity/`, link it by user id, and protect it with a policy
in `src/Access/`. An application provider implements
`ProvidesAuthExtensionsInterface` and supplies a
`RegistrationProfileHandlerInterface` contribution to validate the request's
`profile` object and store the linked record after the core user is saved.

This keeps passwords, verification state, 2FA secrets, and credential reads in
the Framework-owned user model while the application owns its profile schema.
Add direct unit and integration coverage; `make:policy` and `make:test` can
generate starting files.

### Add a registration rule

Implement `ProvidesAuthExtensionsInterface` on an application provider and
return an `AuthExtensionContribution` containing a
`RegistrationPolicyInterface`. The policy receives validated identity metadata,
never a password or token, and may allow, deny, or require approval. Register a
separate provider with `make:provider` when the rule does not belong in
`AppServiceProvider`.

Do not replace `RegisterController`, invite validation, token handling, or the
configured core registration mode.

### Create a content type

Generate the entity, provider, and provider registration together:

```bash
./vendor/bin/waaseyaa make:content-type event --fields="title:string,body:text,event_date:datetime"
./vendor/bin/waaseyaa schema:sync
```

Review the generated `src/Entity/Event.php` and
`src/Provider/EventServiceProvider.php`, add an access policy, and cover the
storage and HTTP boundary. For lower-level work, `make:migration` generates an
application migration. The `migrations/` directory is created by
`make:migration` on first use.

### Add an authenticated route

Add the route in `src/Provider/AppServiceProvider.php` and make the access rule
part of the route declaration:

```php
$router->addRoute(
    'account.profile',
    RouteBuilder::create('/account/profile')
        ->controller([ProfileController::class, 'show'])
        ->requireAuthentication()
        ->methods('GET')
        ->build(),
);
```

Use `->requirePermission(...)`, `->requireRole(...)`, or `->gate(...)` when the
route needs a stronger rule. State-changing cookie-authenticated JSON routes
also declare the appropriate CSRF requirement; never perform a controller-only
authentication check as a substitute for route policy.

## Application path reference

- `src/Provider/AppServiceProvider.php` — initial DI and route composition.
- `src/Access/` — application-owned access policies.
- `src/Controller/` — thin request orchestration.
- `src/Domain/` — bounded-context logic.
- `src/Entity/` — application entities and profile/domain records.
- `templates/` — consumer-owned Twig presentation.
- `config/waaseyaa.php` — supported Framework configuration.
- `config/entity-types.php` — additional entity-type declarations.
- `config/services.php` — application service bindings and supported overrides.
- `migrations/` — created on first `make:migration` invocation.
- `tests/Unit/` and `tests/Integration/` — fast logic and real-boundary coverage.

After adding providers, rebuild the production discovery manifest with
`./vendor/bin/waaseyaa optimize:manifest`. Run `.ci/site-verify` before treating
the application as converged.
