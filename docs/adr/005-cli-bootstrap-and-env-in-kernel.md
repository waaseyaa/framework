# ADR-005: CLI project-root resolution via `getcwd()`; populate `$_ENV`/`$_SERVER` in `EnvLoader`

**Status:** Superseded in part by #2615
**Date:** 2026-04-16
**Repos:** waaseyaa/framework
**Sequences with:** ADR-004 (same release train)

## 2026-08-27 addendum

The no-dependency parser decision in sections 4 and 6.3 is superseded. The
hand-written parser and Symfony Dotenv were subsequently shown to run in one
HTTP boot and disagree on interpolation, multiline values, inline comments,
and `export` prefixes. `EnvLoader` now delegates to Symfony Dotenv, preserves
process-injected values, and loads each real base path once per process. HTTP
front controllers call that same boundary before worker dispatch; CLI retains
its kernel and command-specific calls, which become no-ops after the first
successful load.

The project-root decision and CLI invocation contract remain accepted.

## 1. Decision

Two narrow changes to eliminate the consumer-side CLI bootstrap workarounds:

1. **CLI bin.** `packages/cli/bin/waaseyaa` determines project root from `getcwd()`, not from `__DIR__`. It requires `{projectRoot}/vendor/autoload.php` and hands off to `ConsoleKernel`. No `.env` loading in the bin — the kernel already owns it.
2. **`EnvLoader` populates `$_ENV` and `$_SERVER`.** Currently writes only via `putenv()`. Extending to also set `$_ENV[$key]` and `$_SERVER[$key]` (subject to the existing "don't overwrite pre-set vars" rule) makes consumer-side `Symfony\Component\Dotenv\Dotenv` calls in `public/index.php` truly redundant and safely removable.

## 2. Invariant

**The CLI binary's resolution of project root does not depend on the physical location of the bin file.** Symlinks in `vendor/` must not change the answer.

## 3. Root Cause

`packages/cli/bin/waaseyaa` resolves project root via:

```php
$autoloadPaths = [
    __DIR__ . '/../../../autoload.php',       // consumer install
    __DIR__ . '/../../../vendor/autoload.php', // monorepo dev
];
$projectRoot = dirname($autoloaderPath, 2);
```

PHP's `__DIR__` returns the canonical directory of the file being executed. When a consumer symlinks `vendor/waaseyaa/cli` to the monorepo (standard local-dev workflow — giiken does this for all 37 waaseyaa packages), `__DIR__` resolves to the monorepo path, the walk-up lands on the monorepo's own `vendor/autoload.php`, and `$projectRoot` becomes the framework repo instead of the consumer repo.

Every downstream symptom cascades from this single resolution error:

- `ConsoleKernel` boots with the wrong root.
- `EnvLoader::load($this->projectRoot . '/.env')` (already invoked by `AbstractKernel::boot()` at line 88) looks for `.env` at the framework repo. No such file there.
- `APP_ENV` falls through to `production` (the resolution default in `resolveEnvironment()`).
- Production guards trip in dev. SQLite storage path lands inside `vendor/waaseyaa/cli/storage/`.

The kernel already owns `.env` loading. The bin is the single broken link.

## 4. Why Not ADR-005-as-previously-drafted

An earlier draft of this ADR proposed moving `.env` loading into `AbstractKernel::boot()`. Investigation (2026-04-16, Phase 1 of the implementation plan) revealed the kernel already does exactly this via its own `EnvLoader` class — a deliberate minimal implementation that does not depend on `symfony/dotenv`. That part of the proposal was moot.

What remained: the bin bug (real) and a smaller alignment question on what `EnvLoader` populates. The ADR is narrowed accordingly.

## 5. Target Shape

### 5.1 `packages/cli/bin/waaseyaa`

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = getcwd();
if ($projectRoot === false || !file_exists($projectRoot . '/composer.json')) {
    fwrite(STDERR, "waaseyaa: must be run from a project root (directory containing composer.json).\n");
    exit(1);
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "waaseyaa: vendor/autoload.php not found. Run 'composer install'.\n");
    exit(1);
}
require $autoload;

exit((new Waaseyaa\Foundation\Kernel\ConsoleKernel($projectRoot))->handle());
```

### 5.2 `EnvLoader::load()`

Additive change inside the existing loop. Current shape:

```php
if (getenv($key) === false) {
    putenv("{$key}={$value}");
}
```

Becomes:

```php
if (getenv($key) === false) {
    putenv("{$key}={$value}");
}
if (!array_key_exists($key, $_ENV)) {
    $_ENV[$key] = $value;
}
if (!array_key_exists($key, $_SERVER)) {
    $_SERVER[$key] = $value;
}
```

Each superglobal is guarded independently so a partial pre-population (a value set in `$_ENV` but not `putenv()`, for example) is respected.

### 5.3 Consumer `public/index.php` (after `EnvLoader` enhancement)

The Symfony Dotenv block becomes genuinely redundant and can be removed:

```php
<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($path)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__));
$kernel->handle()->send();
```

## 6. Rejected Alternatives

### 6.1 Walk up from `getcwd()` until finding a non-vendor `composer.json`

Rejected. Simpler contract wins: "run from the project root, or fail cleanly." Matches Laravel's `artisan` and Symfony's `bin/console` convention. Users running from a subdirectory get a clear error, not mysterious wrong-root behavior.

### 6.2 Use `$_SERVER['argv'][0]` / `realpath` gymnastics to detect whether the bin was invoked via a symlink

Rejected. Non-trivial to get right across all invocation paths (Composer proxy, direct, `php bin/...`). `getcwd()` is ambient data the OS already provides, aligns with user mental model, and sidesteps the whole symlink-traversal question.

### 6.3 Replace `EnvLoader` with `Symfony\Component\Dotenv\Dotenv`

Rejected. `EnvLoader` is a deliberate no-dependency minimal implementation. Swapping to Symfony Dotenv adds a runtime dep for behavior we already have. The `$_ENV`/`$_SERVER` enhancement closes the only meaningful gap without taking the dep.

## 7. Breaking Changes

- **CLI invocation contract.** Running `./vendor/bin/waaseyaa` from outside the project root now errors with a clear message instead of silently resolving to the wrong root. This was always the intended behavior; the bug was permissive.
- **No breaking API changes.** `EnvLoader` signature and its existing "don't overwrite pre-set vars" semantic are preserved; the change is strictly additive.
- **Consumer entry points.** `public/index.php` files in the wild that still carry Dotenv blocks continue to work — Dotenv's `loadEnv` is idempotent with `overrideExistingVars=false`. Removing the block is a cleanup, not a compatibility break.

## 8. Release Coordination

This ADR and ADR-004 (framework package collapse) ship in the same release. The giiken cleanup is the joint outcome: ADR-005 fixes the bin and lets giiken delete `bin/giiken` + `repoint-vendor-bin.php`; ADR-004 stabilizes the package boundary. Release train: `v0.2.0-alpha.1`.
