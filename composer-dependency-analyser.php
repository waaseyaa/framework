<?php declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * Monorepo-wide composer dependency audit.
 *
 * Analyses the root composer.json (which transitively requires every
 * waaseyaa/* sibling via path repositories) against source code in every
 * packages/<name>/src and tests/ directory. This catches unused root
 * dependencies, missing direct requires (relying on transitive deps),
 * and shadow dependencies.
 *
 * Per-package require accuracy is intentionally out of scope: the monorepo
 * uses path repositories with a shared vendor/, so each package's own
 * composer.json cannot be analysed independently without its own vendor/
 * tree. Enforcement of per-package require shape lives in
 * bin/check-package-layers and bin/check-composer-policy.
 */

$config = new Configuration();

$rootDir = __DIR__;
$packagesDir = $rootDir . '/packages';

foreach (glob($packagesDir . '/*', GLOB_ONLYDIR) as $packageDir) {
    $manifest = $packageDir . '/composer.json';
    if (!is_file($manifest)) {
        continue;
    }
    $src = $packageDir . '/src';
    if (is_dir($src)) {
        $config->addPathToScan($src, isDev: false);
    }
    $tests = $packageDir . '/tests';
    if (is_dir($tests)) {
        $config->addPathToScan($tests, isDev: true);
    }
    $testing = $packageDir . '/testing';
    if (is_dir($testing)) {
        $config->addPathToScan($testing, isDev: true);
    }
}

if (is_dir($rootDir . '/tests')) {
    $config->addPathToScan($rootDir . '/tests', isDev: true);
}

if (is_dir($rootDir . '/tools')) {
    $config->addPathToScan($rootDir . '/tools', isDev: true);
}

/*
 * Baseline: pre-existing findings as of the warn-only audit's introduction.
 *
 * Each entry below is a known-current-state finding suppressed so CI surfaces
 * only NEW drift introduced by future PRs. Triage by removing entries from
 * this baseline, not by silencing new findings as they appear.
 *
 * Regenerate after a triage sweep:
 *   vendor/bin/composer-dependency-analyser --config=composer-dependency-analyser.php --format=junit
 *   (then map each <testsuite name> to the matching ErrorType:: constant)
 */

// Shadow dependencies: used directly in code but only pulled in transitively.
$config->ignoreErrorsOnPackages([
    'doctrine/dbal',
    'nikic/php-parser',
    'psr/container',
    'psr/event-dispatcher',
    'symfony/event-dispatcher',
    'symfony/event-dispatcher-contracts',
    'symfony/http-foundation',
    'symfony/messenger',
    'symfony/routing',
    'symfony/uid',
    'symfony/validator',
    'symfony/var-dumper',
    'symfony/yaml',
    'twig/twig',
    'webonyx/graphql-php',
], [ErrorType::SHADOW_DEPENDENCY]);

// Shadow PHP extensions: used in code but not declared as require: ext-* in root composer.json.
$config->ignoreErrorsOnExtensions([
    'ext-ctype',
    'ext-curl',
    'ext-fileinfo',
    'ext-filter',
    'ext-mbstring',
    'ext-openssl',
    'ext-pcntl',
    'ext-pdo',
    'ext-posix',
    'ext-session',
    'ext-simplexml',
    'ext-zlib',
], [ErrorType::SHADOW_DEPENDENCY]);

// Dev dependencies referenced from production source paths.
$config->ignoreErrorsOnPackages([
    'nesbot/carbon',
    'phpunit/phpunit',
    'sendgrid/sendgrid',
    'waaseyaa/testing',
], [ErrorType::DEV_DEPENDENCY_IN_PROD]);

// Prod dependencies whose only references live in dev paths.
$config->ignoreErrorsOnPackages([
    'waaseyaa/ai-pipeline',
    'waaseyaa/billing',
    'waaseyaa/debug',
    'waaseyaa/error-handler',
    'waaseyaa/geo',
    'waaseyaa/inertia',
    'waaseyaa/ingestion',
    'waaseyaa/menu',
    'waaseyaa/mercure',
    'waaseyaa/taxonomy',
], [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);

// Unknown classes: PHPStan/PHPUnit fixture stubs (intentionally unautoloadable
// in the analyser's view) plus two storage classes whose namespace registration
// is package-local and not visible from root vendor scanning.
$config->ignoreUnknownClassesRegex('~^Waaseyaa\\\\(Entity|Field)\\\\Tests\\\\(.+\\\\)?Fixtures?\\\\.+$~');
$config->ignoreUnknownClasses([
    'Waaseyaa\\Database\\PdoDatabase',
]);

return $config;
