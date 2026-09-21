# Route metadata consumer acceptance evidence

This directory contains the deliberately failing source-consumer acceptance for
Framework issue #3123 at base
`5196f173b06bac698f14d292c7b827f2ddac388f`. It is outside the configured
PHPUnit suites so an accepted RED contract does not masquerade as a green CI
test. Run the file explicitly.

## Acceptance boundary

The fixture is a temporary consumer-shaped application whose root
`composer.json` declares
`InspectableApplicationServiceProvider`. The production `HttpKernel` discovers
the candidate's provider cohort plus that application provider. The inspection
runner uses the booted kernel's real provider roster and entity type manager,
`BuiltinRouteRegistrar`, and the current Bimaaji
`RoutingIntrospectionProvider` adapter.

The HTTP positive enters the production kernel, matches
`GET /application/inspectable`, and invokes the application handler. The RED
inspection proves the same nonempty application route can be described and
that its handler is not invoked, then fails because the legacy `routes()` hook
constructs an execution service and controller while composing the collection.
The failing assertion is construction, not an empty collection, missing class,
missing dependency, undefined future API, or supplied synthetic route.

During remediation the fixture must opt into the accepted declarative route
contribution API. Calling legacy `routes()` during inspection is prohibited and
must not become the way this test turns green. The expected staged result is a
nonempty explicit declaration, zero legacy hook calls during inspection, zero
execution-service/controller construction, and unchanged request-time HTTP
resolution and invocation.

## Candidate and dependencies

- Repository/worktree: `C:/dev/waaseyaa/framework-worktrees/route-metadata-design-3123`
- Branch: `codex/route-metadata-design-3123`
- Base and tested HEAD: `5196f173b06bac698f14d292c7b827f2ddac388f`
- Runtime: PHP 8.5.5, PHPUnit 13.1.14, native Windows
- `composer.lock` SHA-256: `0AFEC6855797A75C5DD93E4F84BE22AB30DEB14F633EBC2D1B9434B8C7923164`
- Provisioning: `composer install --no-interaction --prefer-dist`, exit 0,
  207 installs. Dependencies are candidate-local and lockfile-derived.
- No donor `vendor`, package symlink/junction, or autoload override is used.
  The disposable consumer copies candidate-local package manifests and `src/`
  bytes so package discovery can scan the installed shape; runtime classes load
  from the candidate's normal Composer autoloader.

Representative reflection through `vendor/autoload.php` resolved:

```text
Symfony\Component\Process\Process
  C:\dev\waaseyaa\framework-worktrees\route-metadata-design-3123\vendor\symfony\process\Process.php
Waaseyaa\Foundation\Kernel\HttpKernel
  C:\dev\waaseyaa\framework-worktrees\route-metadata-design-3123\packages\foundation\src\Kernel\HttpKernel.php
Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar
  C:\dev\waaseyaa\framework-worktrees\route-metadata-design-3123\packages\foundation\src\Kernel\BuiltinRouteRegistrar.php
Waaseyaa\Bimaaji\Introspection\Routing\RoutingIntrospectionProvider
  C:\dev\waaseyaa\framework-worktrees\route-metadata-design-3123\packages\bimaaji\src\Introspection\Routing\RoutingIntrospectionProvider.php
```

The three executable evidence files handed to review had these SHA-256 values:

```text
648B373F9997A6FDA0B3837E7D722FF793DD1E47818D3C1E38AAE5F9F28B2266  ApplicationRouteMetadataAcceptanceTest.php
1F91434ED2F2D164AF295EF9C36D9F638EC10B644789CEC22D4BB5CB5646D94B  Fixtures/route_metadata_runner.php
F1CAED4C5E11218384D2986AF0E79AD5C2747FDFFC485B19DD739D3BACAC4FA7  Fixtures/InspectableApplicationServiceProvider.php
```

## Reproduction and results

HTTP positive control:

```powershell
php vendor/bin/phpunit --no-coverage --bootstrap tests/bootstrap.php `
  --filter http_resolves_and_invokes_the_real_application_handler `
  tests/Regression/RouteMetadata/ApplicationRouteMetadataAcceptanceTest.php
```

Exit 0 in 15.806 seconds: `OK (1 test, 11 assertions)`. It observed status
200, body `application-handler-invoked`, and exactly one route contribution,
execution-service construction, handler construction, and handler invocation.

Inspection RED:

```powershell
php vendor/bin/phpunit --no-coverage --bootstrap tests/bootstrap.php `
  --filter bimaaji_inspects_the_real_application_route_without_constructing_execution_services `
  tests/Regression/RouteMetadata/ApplicationRouteMetadataAcceptanceTest.php
```

Exit 1 in 10.133 seconds: 1 test, 11 assertions, 1 failure. Before the failing
assertion it proved intentional CLI bootstrap contributed zero fixture routes,
the inspected table was nonempty, the exact route path was
`/application/inspectable`, methods were `GET`, and handler invocations were
zero. The first construction assertion then observed
`execution_services_constructed = 1` where zero is required.

Combined command:

```powershell
php vendor/bin/phpunit --no-coverage --bootstrap tests/bootstrap.php `
  tests/Regression/RouteMetadata/ApplicationRouteMetadataAcceptanceTest.php
```

Exit 1 in 21.200 seconds: 2 tests, 22 assertions, 1 intended failure. The HTTP
positive passed in the same run.

Durable logs are under:

```text
C:/Users/jones/.codex/visualizations/2026/09/21/01a0c20a-0707-78b0-b0f1-4e8d944b6e66/consumer-test-evidence/
  http-positive.log
  inspection-red.log
  combined.log
```

## Harness and governance evidence

The subprocess harness uses Symfony Process with an argument vector, drains
stdout/stderr, enforces a 30-second deadline, and lets Process terminate a child
on timeout. Every disposable project path is an explicit child of
`sys_get_temp_dir()` with the `waaseyaa_route_metadata_` prefix. Teardown uses
Symfony Filesystem over ordinary copied directories; it does not traverse
links because this harness creates no links.

Applicable scoped checks:

```text
php bin/check-s1-sqlite-contract
  exit 0: S1 SQLite topology contract passed.

php bin/check-s1-configuration-authority
  exit 0: 288 classified boundaries, 7 commands, one authority.

bash bin/check-phpunit-paths
  exit 0: every package test covered (1877 PHP files).
```

`php bin/test-quality-inventory --format=json` exited 1 before inventory on
native Windows because its hard-coded POSIX `bin/git` entrypoint produced
`CreateProcess failed: %1 is not a valid Win32 application`. No baseline or
scanner was edited. The inventory also enumerates tracked files, so this
uncommitted RED evidence would require exact-candidate rerun after integration.

Initial harness setup failures were kept separate from the behavioral RED:

1. A helper named `run()` collided with PHPUnit 13's final method. It was
   renamed before behavioral execution.
2. The shared `ComposerProjectFixture` attempted package symlinks and received
   native-Windows permission denial. This harness now pre-materializes ordinary
   candidate-local manifest/source copies; no link fallback remains.
3. Manifest-only copies caused `POLICY_MANIFEST_MISMATCH` because policy source
   scanning found zero classes. Copying each candidate package's `src/` bytes
   made discovery representative without changing autoload authority.
4. The initial database configuration shape caused boot failure. The fixture
   now uses the supported scalar SQLite path and runs the real `db:init` handler
   before either acceptance action.

These setup failures are not evidence for the route defect. Only the final
executable RED above is the accepted discriminator.

## Limits and residual qualification

This is source-consumer evidence with a direct Bimaaji routing adapter after
real application discovery and route composition. It does not qualify an
individually installed split package, published artifact, root metapackage,
ConsoleKernel `graph:dump`, `route:list`, or other CLI/MCP adapter. Installed
consumer parity remains a later qualification stage.

The counters distinguish generic execution-service construction, controller
construction, and handler invocation from intentional kernel bootstrap. They do
not separately trap credential resolution, session starts, application-data
queries, or durable writes. Those operations require additional pure-contract
gates once the declarative contributor and snapshot lifecycle exist. This test
also does not cover immutable snapshot mutation, duplicate declarations,
ordering, readiness/refusal states, profile transitions, failed-boot retry, or
effective middleware/entity/field access. Those remain contract and
qualification work rather than claims made by this RED test.
