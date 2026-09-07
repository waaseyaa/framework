<?php

declare(strict_types=1);

/**
 * Read-only assertions for the #2442 packaged profile proof.
 *
 * This file intentionally loads each consumer's installed autoloader only for
 * manifest parsing. It never boots the application or resolves a generated
 * provider: provider activation and authenticated authoring remain #2857.
 */

function fail_probe(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function project_root(string $path): string
{
    $root = realpath($path);
    if ($root === false || !is_dir($root)) {
        fail_probe("Project root does not exist: {$path}");
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function read_bytes(string $path): string
{
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        fail_probe("Could not read {$path}");
    }

    return $bytes;
}

/** @return array<string, mixed> */
function read_json_object(string $path): array
{
    try {
        $document = json_decode(read_bytes($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail_probe("Invalid JSON at {$path}: {$exception->getMessage()}");
    }
    if (!is_array($document) || array_is_list($document)) {
        fail_probe("Expected a JSON object at {$path}");
    }

    return $document;
}

/** @return list<string> */
function owned_paths(string $root): array
{
    $metadata = read_json_object($root . '/.waaseyaa/generated.json');
    if (($metadata['schema'] ?? null) !== 'waaseyaa.generated' || ($metadata['version'] ?? null) !== 1) {
        fail_probe('The generated ownership record has the wrong schema identity.');
    }
    if (!isset($metadata['artifacts']) || !is_array($metadata['artifacts']) || !array_is_list($metadata['artifacts'])) {
        fail_probe('The generated ownership record has no artifact roster.');
    }

    $paths = ['.waaseyaa/generated.json'];
    foreach ($metadata['artifacts'] as $row) {
        $path = is_array($row) ? ($row['path'] ?? null) : null;
        if (!is_string($path)
            || $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains('/' . $path . '/', '/../')
        ) {
            fail_probe('The generated ownership record contains an unsafe artifact path.');
        }
        if (!is_file($root . '/' . $path)) {
            fail_probe("Owned artifact is absent: {$path}");
        }
        $paths[] = $path;
    }
    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return array<string, array{mode: int, bytes: string}> */
function governed_snapshot(string $root): array
{
    $snapshot = [];
    foreach (owned_paths($root) as $path) {
        $absolute = $root . '/' . $path;
        $mode = fileperms($absolute);
        if ($mode === false) {
            fail_probe("Could not read mode for {$path}");
        }
        $snapshot[$path] = [
            'mode' => $mode & 0o777,
            'bytes' => read_bytes($absolute),
        ];
    }

    return $snapshot;
}

function digest_value(mixed $value): string
{
    return hash('sha256', serialize($value));
}

function tree_digest(string $root): string
{
    $rows = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $absolute = $entry->getPathname();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
        $mode = $entry->getPerms() & 0o777;
        if ($entry->isLink()) {
            $target = readlink($absolute);
            if (!is_string($target)) {
                fail_probe("Could not read symbolic link: {$relative}");
            }
            $rows[$relative] = ['type' => 'link', 'mode' => $mode, 'target' => $target];
        } elseif ($entry->isDir()) {
            $rows[$relative] = ['type' => 'directory', 'mode' => $mode];
        } elseif ($entry->isFile()) {
            $digest = hash_file('sha256', $absolute);
            if (!is_string($digest)) {
                fail_probe("Could not hash file: {$relative}");
            }
            $rows[$relative] = ['type' => 'file', 'mode' => $mode, 'sha256' => $digest];
        } else {
            fail_probe("Unsupported filesystem entry in consumer: {$relative}");
        }
    }
    ksort($rows, SORT_STRING);

    return digest_value($rows);
}

function assert_no_profile_key(mixed $value, string $pointer = ''): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && in_array(strtolower($key), ['preset', 'profile'], true)) {
            fail_probe("The resolved manifest persists the init-time choice at {$pointer}/{$key}");
        }
        assert_no_profile_key($child, $pointer . '/' . (string) $key);
    }
}

/** @param list<string> $paths */
function assert_paths_present(array $paths, array $owned, string $label): void
{
    foreach ($paths as $path) {
        if (!in_array($path, $owned, true)) {
            fail_probe("{$label} is missing governed artifact {$path}");
        }
    }
}

/** @param list<string> $paths */
function assert_paths_absent(string $root, array $paths, array $owned, string $label): void
{
    foreach ($paths as $path) {
        if (in_array($path, $owned, true) || file_exists($root . '/' . $path)) {
            fail_probe("{$label} unexpectedly contains governed artifact {$path}");
        }
    }
}

function assert_profile(string $root, string $profile): void
{
    if (!in_array($profile, ['minimal', 'editorial'], true)) {
        fail_probe("Unknown expected profile: {$profile}");
    }

    require_once $root . '/vendor/autoload.php';

    $yaml = read_bytes($root . '/.waaseyaa/site.yaml');
    $decoded = Symfony\Component\Yaml\Yaml::parse($yaml);
    assert_no_profile_key($decoded);
    $manifest = new Waaseyaa\SiteContract\SiteManifestParser()->parse($yaml, '.waaseyaa/site.yaml');

    $expectedGovernedState = $profile === 'editorial' ? 'active' : 'not_needed';
    if (($manifest->capabilities['published_content']->state->value ?? null) !== 'active') {
        fail_probe("{$profile} did not activate published_content.");
    }
    if (($manifest->capabilities['governed_authoring']->state->value ?? null) !== $expectedGovernedState) {
        fail_probe("{$profile} resolved governed_authoring incorrectly.");
    }
    if (($manifest->capabilities['subscription']->state->value ?? null) !== 'not_needed') {
        fail_probe("{$profile} must leave subscription not_needed.");
    }
    if ($manifest->personalDataStores !== []) {
        fail_probe("{$profile} must not declare a personal-data store.");
    }

    $recipeIds = array_keys($manifest->recipes);
    sort($recipeIds, SORT_STRING);
    $expectedRecipes = $profile === 'editorial'
        ? ['governed_authoring', 'published_content']
        : ['published_content'];
    if ($recipeIds !== $expectedRecipes) {
        fail_probe("{$profile} resolved the wrong recipe set: " . implode(', ', $recipeIds));
    }

    $owned = owned_paths($root);
    $published = [
        'composer.site-recipes.json',
        'config/waaseyaa-recipes/published-content.php',
        'src/Content/Bundle/PageBundle.php',
        'src/Content/CanonicalContentRouteResolver.php',
        'src/Controller/PublishedContentController.php',
        'src/Provider/PublishedContentServiceProvider.php',
        'templates/content/detail.html.twig',
        'templates/content/index.html.twig',
        'tests/Acceptance/PublishedContentRecipeTest.php',
    ];
    $governed = [
        'composer.governed-authoring-recipe.json',
        'config/waaseyaa-recipes/governed-authoring.php',
        'src/Authoring/GovernedPageDefinitions.php',
        'src/Authoring/GovernedPagePreviewUrlGenerator.php',
        'src/Authoring/GovernedPageRenderer.php',
        'src/Provider/GovernedAuthoringServiceProvider.php',
        'templates/page-builder/preview.html.twig',
        'tests/Acceptance/GovernedAuthoringRecipeTest.php',
    ];
    $subscription = [
        'composer.subscription-recipe.json',
        'config/waaseyaa-recipes/subscription.php',
        'migrations/2026_08_13_000001_create_subscriber_table.php',
        'src/Provider/SubscriptionServiceProvider.php',
        'tests/Acceptance/SubscriptionRecipeTest.php',
    ];
    assert_paths_present($published, $owned, $profile);
    if ($profile === 'editorial') {
        assert_paths_present($governed, $owned, $profile);
    } else {
        assert_paths_absent($root, $governed, $owned, $profile);
    }
    assert_paths_absent($root, $subscription, $owned, $profile);

    $composer = read_json_object($root . '/composer.json');
    $providers = $composer['extra']['waaseyaa']['providers'] ?? [];
    if (is_array($providers) && in_array('App\\Provider\\GovernedAuthoringServiceProvider', $providers, true)) {
        fail_probe('The literal root composer.json unexpectedly activates the generated authoring provider.');
    }
    if (isset($composer['require']['waaseyaa/page-builder'])) {
        fail_probe('The literal root composer.json unexpectedly materializes the generated authoring requirement.');
    }
    if ($profile === 'editorial') {
        $declaration = read_json_object($root . '/composer.governed-authoring-recipe.json');
        if (($declaration['extra']['waaseyaa']['providers'][0] ?? null) !== 'App\\Provider\\GovernedAuthoringServiceProvider') {
            fail_probe('Editorial did not publish the governed-authoring provider declaration.');
        }
    }
}

function assert_dry_run_json(string $path): void
{
    $document = read_json_object($path);
    if (($document['result']['outcome'] ?? null) !== 'planned') {
        fail_probe('Dry-run did not return a planned artifact result.');
    }
    if (($document['result']['changed'] ?? null) !== []) {
        fail_probe('Dry-run reported that it published artifact bytes.');
    }
    $status = $document['evaluation']['status'] ?? null;
    if (!is_array($status) || !in_array('created', $status, true)) {
        fail_probe('Dry-run contains no pending created artifact, so the no-write control is vacuous.');
    }
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'tree-digest':
        echo tree_digest(project_root($argv[2] ?? '')) . PHP_EOL;
        break;

    case 'governed-digest':
        echo digest_value(governed_snapshot(project_root($argv[2] ?? ''))) . PHP_EOL;
        break;

    case 'compare-governed':
        $left = project_root($argv[2] ?? '');
        $right = project_root($argv[3] ?? '');
        if (governed_snapshot($left) !== governed_snapshot($right)) {
            fail_probe("Governed artifact bytes differ between {$left} and {$right}");
        }
        break;

    case 'assert-profile':
        assert_profile(project_root($argv[2] ?? ''), $argv[3] ?? '');
        break;

    case 'assert-dry-run-json':
        assert_dry_run_json($argv[2] ?? '');
        break;

    default:
        fail_probe('Usage: site-init-profile-probe.php tree-digest|governed-digest|compare-governed|assert-profile|assert-dry-run-json ...');
}
