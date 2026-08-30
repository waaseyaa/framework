<?php

declare(strict_types=1);

/**
 * Split-artifact acceptance engine (#2649).
 *
 * Release Gate 2 must prove that the EXACT candidate, packaged the way the
 * split mirror packages it, installs and works for a consumer that has no
 * access to the source checkout. `tests/PackagedForm/check-fresh-install-boot`
 * (#2426) already proves the candidate boots, but it resolves every waaseyaa/*
 * through Composer PATH repositories: the consumer's vendor tree is symlinked
 * back into the checkout, so nothing about export, archive generation, or the
 * split boundary is exercised at all. This engine is the artifact-shaped
 * sibling of that proof, not a replacement for it.
 *
 * Two halves:
 *
 *  - `seal` turns the committed tree into a local Composer ARTIFACT repository:
 *    one zip per member, produced by `git archive`, so the bytes are the bytes
 *    git would export (`export-ignore` included) rather than a hand-copied
 *    directory. The only mutation is the `version` key Packagist would
 *    otherwise derive from the tag.
 *  - `assert` runs one named acceptance surface against an installed consumer.
 *
 * The surface roster is DATA, in
 * `tests/PackagedForm/fixtures/split-artifact-acceptance-surfaces.json`, so a
 * member #2649 names that cannot exist yet is recorded as reserved with its
 * blocking issue instead of being silently dropped, and fails closed the
 * moment its blocker lands. The development metapackage (#2655) was such a
 * member — it depended on #2649, so it could not be required here — and its
 * fail-closed hatch did its job: `waaseyaa/ai-development` now exists, the
 * member is live, and `assert_development_plane` asserts it instead. stdio
 * initialization (#2659) is still reserved.
 *
 * Usage:
 *   php split-artifact-acceptance.php seal <repo> <artifacts-dir> <manifest> <version>
 *   php split-artifact-acceptance.php assert <surface> <manifest> <consumer> [<nodev-consumer>]
 *   php split-artifact-acceptance.php self-test <manifest> <consumer> <nodev-consumer> <scratch>
 *   php split-artifact-acceptance.php surfaces
 */

const SURFACE_FIXTURE = __DIR__ . '/fixtures/split-artifact-acceptance-surfaces.json';

/**
 * Paths the skeleton must still export once create-project has run. Pinned
 * here rather than derived: the point is to notice a file that stopped being
 * exported, and a derived list would simply stop expecting it.
 *
 * @var list<string>
 */
const SKELETON_EXPORTED_PATHS = [
    'composer.json',
    'public/index.php',
    '.env.example',
    'bin/maintenance/waaseyaa-audit-site',
    'src/Provider/AppServiceProvider.php',
];

/**
 * The #2543 pinned fixture. These two files are CONSUMED here, never
 * regenerated: `bin/build-admin-dist` is the only producer, and a gate that
 * rebuilt them would prove nothing about what a consumer receives.
 *
 * @var list<string>
 */
const ADMIN_SURFACE_PINNED_FILES = [
    'dist.manifest.json',
    'dist.markers.json',
];

final class AcceptanceFailure extends RuntimeException {}

/**
 * @return array<string, mixed>
 */
function read_json(string $path): array
{
    if (!is_file($path)) {
        throw new AcceptanceFailure("Required JSON file is missing: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new AcceptanceFailure("Expected a JSON object: {$path}");
    }

    return $decoded;
}

function write_json(string $path, mixed $value): void
{
    file_put_contents(
        $path,
        json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
}

function fail(string $message): never
{
    throw new AcceptanceFailure($message);
}

/**
 * Sorted `path\0size\0sha256` roster over every file under a directory, and
 * the SHA-256 of that roster. Identical in shape to the roster
 * `packages/admin-surface/dist.manifest.json` documents for `published.treeDigest`,
 * so the same helper serves both the whole-package comparison and the #2543
 * consumer procedure.
 *
 * @return array{digest: string, files: int, bytes: int}
 */
function tree_digest(string $directory): array
{
    if (!is_dir($directory)) {
        fail("Cannot digest a missing directory: {$directory}");
    }

    $rows = [];
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            fail('Refusing to digest a tree containing a symbolic link: ' . $entry->getPathname());
        }
        if (!$entry->isFile()) {
            continue;
        }
        $relative = substr($entry->getPathname(), strlen($directory) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $size = (int) $entry->getSize();
        $bytes += $size;
        $rows[] = $relative . "\0" . $size . "\0" . hash_file('sha256', $entry->getPathname());
    }

    sort($rows, SORT_STRING);

    return [
        'digest' => hash('sha256', implode("\n", $rows)),
        'files' => count($rows),
        'bytes' => $bytes,
    ];
}

/**
 * The same roster, computed over a zip's entries instead of a directory. The
 * seal records this so `exported-files` can compare what a consumer installed
 * against what the archive actually carried, without keeping the extracted
 * tree around.
 *
 * @return array{digest: string, files: int, bytes: int}
 */
function archive_digest(string $archive): array
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        fail("Cannot open artifact archive: {$archive}");
    }

    $rows = [];
    $bytes = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if ($stat === false) {
            fail("Unreadable entry {$index} in {$archive}");
        }
        $name = (string) $stat['name'];
        if (str_ends_with($name, '/')) {
            continue;
        }
        $contents = $zip->getFromIndex($index);
        if ($contents === false) {
            fail("Unreadable entry {$name} in {$archive}");
        }
        $bytes += strlen($contents);
        $rows[] = $name . "\0" . strlen($contents) . "\0" . hash('sha256', $contents);
    }
    $zip->close();

    sort($rows, SORT_STRING);

    return [
        'digest' => hash('sha256', implode("\n", $rows)),
        'files' => count($rows),
        'bytes' => $bytes,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function surfaces(): array
{
    $fixture = read_json(SURFACE_FIXTURE);
    $surfaces = $fixture['surfaces'] ?? null;
    if (!is_array($surfaces) || $surfaces === []) {
        fail('The surface roster fixture declares no surfaces.');
    }

    /** @var list<array<string, mixed>> $surfaces */
    return array_values($surfaces);
}

// ---------------------------------------------------------------------------
// seal
// ---------------------------------------------------------------------------

/**
 * @return array{name: string, require: array<string, string>, require_dev: array<string, string>}
 */
function stamp_archive(string $archive, string $version, string $commit): array
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        fail("Cannot open artifact archive for stamping: {$archive}");
    }

    $raw = $zip->getFromName('composer.json');
    if ($raw === false) {
        fail("Artifact archive has no composer.json at its root: {$archive}");
    }

    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (isset($manifest['version'])) {
        fail(sprintf(
            'Package %s already declares a version; the seal must be the only thing that stamps one.',
            (string) ($manifest['name'] ?? $archive),
        ));
    }

    // The ONLY mutation. Packagist derives the version from the release tag;
    // an artifact repository reads it from the manifest, so a seal without it
    // is unresolvable. Everything else — including `repositories`, which
    // Composer ignores outside the root package — is left exactly as exported,
    // so `exported-files` compares honest bytes.
    $stamped = [];
    foreach ($manifest as $key => $value) {
        $stamped[$key] = $value;
        if ($key === 'name') {
            $stamped['version'] = $version;
        }
    }
    if (!isset($stamped['version'])) {
        $stamped['version'] = $version;
    }

    $zip->addFromString(
        'composer.json',
        json_encode($stamped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    $zip->setArchiveComment(sprintf('waaseyaa split-artifact seal %s @ %s', $version, $commit));
    $zip->close();

    /** @var array<string, string> $require */
    $require = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
    /** @var array<string, string> $requireDev */
    $requireDev = is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [];

    return ['name' => (string) $manifest['name'], 'require' => $require, 'require_dev' => $requireDev];
}

/**
 * Transitive `waaseyaa/*` closure over sealed `require` metadata.
 *
 * @param list<string>                       $seeds
 * @param array<string, array<string, string>> $requires package => its require block
 *
 * @return list<string> sorted
 */
function waaseyaa_closure(array $seeds, array $requires): array
{
    $seen = [];
    $queue = $seeds;
    while ($queue !== []) {
        $name = array_shift($queue);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        foreach (array_keys($requires[$name] ?? []) as $dependency) {
            if (str_starts_with((string) $dependency, 'waaseyaa/')) {
                $queue[] = (string) $dependency;
            }
        }
    }
    $closure = array_keys($seen);
    sort($closure);

    return $closure;
}

function git_archive(string $repo, string $treeish, string $target): void
{
    $command = sprintf(
        'git -C %s archive --format=zip -o %s %s',
        escapeshellarg($repo),
        escapeshellarg($target),
        escapeshellarg($treeish),
    );
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fail("git archive failed for {$treeish}: " . implode("\n", $output));
    }
}

function seal(string $repo, string $artifacts, string $manifestPath, string $version): void
{
    if (!is_dir($artifacts)) {
        mkdir($artifacts, 0o777, true);
    }

    exec(sprintf('git -C %s rev-parse HEAD 2>&1', escapeshellarg($repo)), $head, $code);
    if ($code !== 0) {
        fail('Cannot resolve the candidate commit: ' . implode("\n", $head));
    }
    $commit = trim($head[0]);

    $members = [];
    $requires = [];
    $requireDevs = [];

    // Roles are named so the seal manifest answers #2649's first acceptance
    // bullet directly: skeleton, root framework dist, split packages. The
    // development metapackage (#2655) needed no builder change — it is a
    // packages/* directory, so it was sealed the day it existed.
    $plan = [['root-framework-dist', '', 'HEAD']];
    $plan[] = ['skeleton', 'skeleton', 'HEAD:skeleton'];
    foreach (glob($repo . '/packages/*/composer.json') ?: [] as $packageManifest) {
        $directory = basename(dirname($packageManifest));
        $plan[] = ['split-package', 'packages/' . $directory, 'HEAD:packages/' . $directory];
    }

    foreach ($plan as [$role, $tree, $treeish]) {
        $slug = $tree === '' ? 'root' : str_replace('/', '-', $tree);
        $archive = $artifacts . '/waaseyaa-' . $slug . '-' . $version . '.zip';
        git_archive($repo, $treeish, $archive);
        $stamped = stamp_archive($archive, $version, $commit);
        $digest = archive_digest($archive);

        $members[] = [
            'role' => $role,
            'name' => $stamped['name'],
            'tree' => $tree,
            'archive' => $archive,
            'archive_sha256' => hash_file('sha256', $archive),
            'tree_digest' => $digest['digest'],
            'file_count' => $digest['files'],
            'byte_count' => $digest['bytes'],
        ];
        $requires[$stamped['name']] = $stamped['require'];
        $requireDevs[$stamped['name']] = $stamped['require_dev'];
    }

    // Transitive waaseyaa/* closure of the root framework dist. `composition`
    // asserts the installed set equals this EXACTLY, so a package silently
    // added to or dropped from the graph is a failure rather than a shrug.
    $closure = waaseyaa_closure(['waaseyaa/framework'], $requires);

    // The development plane (#2655, ADR-022 D-1). The skeleton installs
    // `waaseyaa/ai-development` under `require-dev` ONLY, so a dev consumer
    // gets it and a `--no-dev` consumer must not. Seeding from the skeleton's
    // own require-dev rather than from a hardcoded name means the seal
    // describes what a consumer actually receives, and a package added to or
    // dropped from that bundle moves the expectation instead of going unseen.
    $developmentSeeds = [];
    foreach (array_keys($requireDevs['waaseyaa/waaseyaa'] ?? []) as $dependency) {
        if (str_starts_with((string) $dependency, 'waaseyaa/')) {
            $developmentSeeds[] = (string) $dependency;
        }
    }
    $developmentClosure = waaseyaa_closure($developmentSeeds, $requires);
    $developmentOnly = array_values(array_diff($developmentClosure, $closure));
    sort($developmentOnly);

    write_json($manifestPath, [
        'schema_version' => 1,
        'commit' => $commit,
        'version' => $version,
        'artifacts_dir' => $artifacts,
        'members' => $members,
        'framework_closure' => $closure,
        'development_closure' => $developmentClosure,
        'development_only' => $developmentOnly,
    ]);

    printf(
        "Sealed %d artifact members at %s (version %s).\n",
        count($members),
        substr($commit, 0, 12),
        $version,
    );
}

// ---------------------------------------------------------------------------
// installation views
// ---------------------------------------------------------------------------

/**
 * An installed consumer, addressed by explicit paths so the negative controls
 * can point the same assertions at a deliberately corrupted overlay.
 */
final class Installation
{
    public function __construct(
        public readonly string $project,
        public readonly string $vendor,
        public readonly string $installedJson,
    ) {}

    public static function of(string $project): self
    {
        return new self($project, $project . '/vendor', $project . '/vendor/composer/installed.json');
    }

    public function withInstalledJson(string $path): self
    {
        return new self($this->project, $this->vendor, $path);
    }

    public function withVendor(string $vendor): self
    {
        return new self($this->project, $vendor, $this->installedJson);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function packages(): array
    {
        $document = read_json($this->installedJson);
        $packages = $document['packages'] ?? null;
        if (!is_array($packages)) {
            fail('installed.json carries no package list: ' . $this->installedJson);
        }

        $indexed = [];
        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name'])) {
                $indexed[(string) $package['name']] = $package;
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function waaseyaaPackages(): array
    {
        return array_filter(
            $this->packages(),
            static fn(string $name): bool => str_starts_with($name, 'waaseyaa/'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}

// ---------------------------------------------------------------------------
// surface: composition
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $seal
 */
function assert_composition(array $seal, Installation $installation): void
{
    /** @var list<string> $frameworkClosure */
    $frameworkClosure = $seal['framework_closure'];
    /** @var list<string> $developmentOnly */
    $developmentOnly = $seal['development_only'];
    $expected = array_values(array_unique(array_merge($frameworkClosure, $developmentOnly)));
    sort($expected);
    $installed = array_keys($installation->waaseyaaPackages());
    sort($installed);

    $missing = array_values(array_diff($expected, $installed));
    $unexpected = array_values(array_diff($installed, $expected));
    if ($missing !== [] || $unexpected !== []) {
        fail(sprintf(
            'Installed waaseyaa/* composition does not equal the sealed framework closure plus the '
            . "development plane.\n  missing: %s\n  unexpected: %s",
            $missing === [] ? '(none)' : implode(', ', $missing),
            $unexpected === [] ? '(none)' : implode(', ', $unexpected),
        ));
    }

    $version = (string) $seal['version'];
    $artifacts = (string) $seal['artifacts_dir'];
    foreach ($installation->waaseyaaPackages() as $name => $package) {
        if ((string) ($package['version'] ?? '') !== $version) {
            fail(sprintf(
                'Installed %s is version %s, not the sealed candidate %s.',
                $name,
                (string) ($package['version'] ?? '(none)'),
                $version,
            ));
        }

        // Artifact ORIGIN is asserted for every member, metapackage or not:
        // the dist Composer recorded must be a zip inside the local artifact
        // repository, so nothing was satisfied from a registry.
        $distType = (string) ($package['dist']['type'] ?? '');
        if ($distType !== 'zip') {
            fail(sprintf('Installed %s has dist type "%s", not "zip".', $name, $distType));
        }
        $distUrl = (string) ($package['dist']['url'] ?? '');
        if (!str_starts_with($distUrl, $artifacts)) {
            fail(sprintf(
                'Installed %s resolved dist %s, which is outside the local artifact repository %s.',
                $name,
                $distUrl,
                $artifacts,
            ));
        }

        assert_installation_shape($name, $package, $installation);
    }

    assert_no_source_symlinks($installation);

    assert_development_plane($seal, $installation);
}

/**
 * What Composer must have DONE with a sealed member, keyed on package type.
 *
 * A `type: metapackage` package is never downloaded and never extracted:
 * Composer resolves it, records its dist, and installs nothing. Its
 * `installed.json` entry therefore carries no `installation-source` and a null
 * `install-path`, and no directory appears under `vendor/`. That is correct
 * behaviour, not a defect, and it was invisible here until #2655 put the first
 * metapackage into the INSTALLED set — `core`, `cms` and `full` are sealed and
 * resolvable but deliberately absent from the framework closure, so no
 * metapackage had ever reached this loop before.
 *
 * The relaxation is keyed on TYPE, never on name. A name-keyed exemption would
 * silently admit the next metapackage, and would keep passing if
 * `waaseyaa/ai-development` ever stopped being one. And it is not an
 * exemption in any case: the metapackage branch asserts its own invariant
 * positively — nothing installed, nothing extracted — so a metapackage that
 * suddenly grew an installation is a finding, exactly like a code-bearing
 * package that lost one.
 *
 * @param array<string, mixed> $package the installed.json record
 */
function assert_installation_shape(string $name, array $package, Installation $installation): void
{
    $type = (string) ($package['type'] ?? '');
    $source = $package['installation-source'] ?? null;
    $installedPath = $installation->vendor . '/' . $name;

    if ($type !== 'metapackage') {
        if ((string) ($source ?? '') !== 'dist') {
            fail(sprintf(
                'Installed %s came from "%s", not from a distributable artifact.',
                $name,
                (string) ($source ?? '(none)'),
            ));
        }

        return;
    }

    if ($source !== null) {
        fail(sprintf(
            'Metapackage %s records installation-source "%s"; Composer installs no bytes for a '
            . 'metapackage, so a recorded source means this is no longer one.',
            $name,
            (string) $source,
        ));
    }
    if (($package['install-path'] ?? null) !== null) {
        fail(sprintf(
            'Metapackage %s records install-path "%s"; a metapackage is never extracted.',
            $name,
            (string) $package['install-path'],
        ));
    }
    if (is_dir($installedPath)) {
        fail(sprintf('Metapackage %s must own no vendor directory, but %s exists.', $name, $installedPath));
    }
}

/**
 * The development metapackage, packaged (#2655, ADR-022 D-1 and D-2).
 *
 * This was a reserved surface that failed closed until `packages/ai-development`
 * existed. It exists, so the claim is asserted rather than deferred: the plane
 * must be sealed as a split artifact, must arrive in a dev consumer as a
 * DEVELOPMENT dependency, must carry the two members D-2 makes mandatory, and
 * must not have dragged `waaseyaa/mcp` in behind it (D-1.4 — that package
 * registers `/mcp/write` unconditionally, so requiring it would put an HTTP
 * route in every application that installed a development tool).
 *
 * `assert_no_dev_exclusion` owns the other half: `--no-dev` removes all of it.
 *
 * @param array<string, mixed> $seal
 */
function assert_development_plane(array $seal, Installation $installation): void
{
    $sealedNames = array_column((array) $seal['members'], 'name');
    if (!in_array('waaseyaa/ai-development', $sealedNames, true)) {
        fail('waaseyaa/ai-development is not in the seal: the development plane was never packaged.');
    }

    /** @var list<string> $frameworkClosure */
    $frameworkClosure = $seal['framework_closure'];
    /** @var list<string> $developmentClosure */
    $developmentClosure = $seal['development_closure'];
    /** @var list<string> $developmentOnly */
    $developmentOnly = $seal['development_only'];

    // D-1.2 — the plane is in no production require closure. The manifest-level
    // form of this is CP009 in bin/check-composer-policy; here it is checked
    // against the bytes a consumer actually resolved.
    foreach (['waaseyaa/ai-development', 'waaseyaa/ai-agent'] as $contained) {
        if (in_array($contained, $frameworkClosure, true)) {
            fail(sprintf(
                '%s is inside the production require closure of waaseyaa/framework (ADR-022 D-1.2 / D-3.0).',
                $contained,
            ));
        }
    }

    // D-2 — both members are mandatory, and both are development-only.
    foreach (['waaseyaa/ai-development', 'waaseyaa/ai-agent', 'waaseyaa/testing'] as $member) {
        if (!in_array($member, $developmentOnly, true)) {
            fail(sprintf(
                '%s is not a development-only member of the local plane (ADR-022 D-2). development_only: %s',
                $member,
                implode(', ', $developmentOnly),
            ));
        }
    }

    // D-1.4 — the plane must not reach the HTTP MCP package.
    if (in_array('waaseyaa/mcp', $developmentClosure, true)) {
        fail('waaseyaa/ai-development reaches waaseyaa/mcp, which registers /mcp/write unconditionally (ADR-022 D-1.4).');
    }

    // D-1.3 — Composer must have classified it as a development dependency.
    // The consumer's own installed.json is the authority on that, not the
    // manifest we sealed it from.
    $document = read_json($installation->installedJson);
    $devNames = (array) ($document['dev-package-names'] ?? []);
    foreach ($developmentOnly as $member) {
        if (!in_array($member, $devNames, true)) {
            fail(sprintf(
                '%s was installed, but the consumer does not record it as a development dependency '
                . '(ADR-022 D-1.3). A production-scoped install would survive --no-dev.',
                $member,
            ));
        }
    }
}

/**
 * "Installation uses no path/source symlinks" (#2649). Stated precisely: no
 * link anywhere under vendor/waaseyaa, and no link anywhere in vendor that
 * escapes the consumer — vendor/bin shims are ordinary Composer output and
 * point back into vendor, which is not the failure mode this gate exists for.
 */
function assert_no_source_symlinks(Installation $installation): void
{
    $project = realpath($installation->project);
    if ($project === false) {
        fail('Cannot resolve the consumer project directory: ' . $installation->project);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($installation->vendor, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $waaseyaaRoot = $installation->vendor . '/waaseyaa';
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if (!$entry->isLink()) {
            continue;
        }
        $path = $entry->getPathname();
        if (str_starts_with($path, $waaseyaaRoot)) {
            fail(sprintf(
                'A packaged install must extract bytes, but %s is a symbolic link to %s.',
                $path,
                (string) readlink($path),
            ));
        }
        $target = realpath($path);
        if ($target === false || !str_starts_with($target, $project . DIRECTORY_SEPARATOR)) {
            fail(sprintf(
                'Installed link %s escapes the consumer (resolves to %s); the install is reading source, not artifacts.',
                $path,
                $target === false ? '(broken)' : $target,
            ));
        }
    }
}

// ---------------------------------------------------------------------------
// surface: exported-files
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $seal
 * @param list<string>         $only  Restrict to these package names (negative controls).
 */
function assert_exported_files(array $seal, Installation $installation, array $only = []): void
{
    /** @var list<string> $frameworkClosure */
    $frameworkClosure = $seal['framework_closure'];
    /** @var list<string> $developmentOnly */
    $developmentOnly = $seal['development_only'];
    $installedSet = array_merge($frameworkClosure, $developmentOnly);
    $records = $installation->packages();

    foreach ((array) $seal['members'] as $member) {
        $name = (string) $member['name'];
        if ($only !== [] && !in_array($name, $only, true)) {
            continue;
        }
        if ($member['role'] === 'skeleton') {
            continue;
        }
        // The seal REPRESENTS every split package — that is acceptance bullet
        // one, and it is what makes the repository canonical for waaseyaa/*.
        // Only what a consumer INSTALLS is byte-compared, though: the
        // consumer-facing metapackages and the opt-in distribution extensions
        // (DIR-004) are resolvable but deliberately absent. `composition` owns
        // that boundary. The development plane (#2655) IS installed — as a
        // development dependency — so its code-bearing members are compared
        // here too rather than growing the installed set without growing the
        // byte proof.
        if (!in_array($name, $installedSet, true)) {
            continue;
        }
        // A metapackage is resolved and never extracted, so there are no
        // installed bytes to compare. `assert_installation_shape` asserts that
        // absence positively; comparing a directory that must not exist is
        // what would be wrong here.
        if ((string) ($records[$name]['type'] ?? '') === 'metapackage') {
            continue;
        }

        $installedPath = $installation->vendor . '/' . $name;
        if (!is_dir($installedPath)) {
            fail(sprintf('Sealed member %s is not installed at %s.', $name, $installedPath));
        }

        $actual = tree_digest($installedPath);
        if ($actual['digest'] !== (string) $member['tree_digest']) {
            fail(sprintf(
                "Installed %s does not carry the exported bytes.\n  archive: %s (%d files, %d bytes)\n  installed: %s (%d files, %d bytes)",
                $name,
                (string) $member['tree_digest'],
                (int) $member['file_count'],
                (int) $member['byte_count'],
                $actual['digest'],
                $actual['files'],
                $actual['bytes'],
            ));
        }
    }

    if ($only !== [] && !in_array('waaseyaa/admin-surface', $only, true)) {
        return;
    }

    assert_admin_surface_pinned_fixture($installation);

    if ($only !== []) {
        return;
    }

    foreach (SKELETON_EXPORTED_PATHS as $relative) {
        if (!is_file($installation->project . '/' . $relative)) {
            fail(sprintf(
                'The created project is missing exported skeleton path %s. If the skeleton genuinely stopped '
                . 'shipping it, remove it from SKELETON_EXPORTED_PATHS in the same change.',
                $relative,
            ));
        }
    }
}

/**
 * #2543's consumer procedure, executed against INSTALLED bytes.
 *
 * `packages/admin-surface/contract/README.md` § "Accepting a released Admin
 * bundle" documents four steps for a downstream distribution. Steps 1-3 are
 * mechanical and are performed here verbatim. The two manifests are a PINNED
 * FIXTURE: they are read, never regenerated. `bin/build-admin-dist` is their
 * only producer, and a gate that rebuilt them would be checking its own
 * arithmetic instead of checking what a consumer receives.
 */
function assert_admin_surface_pinned_fixture(Installation $installation): void
{
    $package = $installation->vendor . '/waaseyaa/admin-surface';

    foreach (ADMIN_SURFACE_PINNED_FILES as $relative) {
        if (!is_file($package . '/' . $relative)) {
            fail(sprintf(
                '#2543 pinned fixture %s did not reach the installed package. A consumer following '
                . 'packages/admin-surface/contract/README.md cannot accept this release.',
                $relative,
            ));
        }
    }

    $manifest = read_json($package . '/dist.manifest.json');

    // Step 2: recompute identityDigest over the canonicalised document with
    // identityDigest and every identityExcludes key removed.
    $identity = $manifest;
    unset($identity['identityDigest']);
    foreach ((array) ($manifest['identityExcludes'] ?? []) as $excluded) {
        unset($identity[(string) $excluded]);
    }
    $canonical = canonicalise($identity);
    $recomputed = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if ($recomputed !== (string) ($manifest['identityDigest'] ?? '')) {
        fail(sprintf(
            "The installed admin-surface manifest fails its own identity check.\n  declared: %s\n  recomputed: %s",
            (string) ($manifest['identityDigest'] ?? '(none)'),
            $recomputed,
        ));
    }

    // Step 3: walk the installed dist and require published.treeDigest.
    $distPath = $package . '/' . (string) ($manifest['release']['distPath'] ?? 'dist');
    $actual = tree_digest($distPath);
    if ($actual['digest'] !== (string) ($manifest['published']['treeDigest'] ?? '')) {
        fail(sprintf(
            "Installed admin dist does not match published.treeDigest (#2543).\n  manifest: %s (%d files, %d bytes)\n  installed: %s (%d files, %d bytes)",
            (string) ($manifest['published']['treeDigest'] ?? '(none)'),
            (int) ($manifest['published']['fileCount'] ?? 0),
            (int) ($manifest['published']['byteCount'] ?? 0),
            $actual['digest'],
            $actual['files'],
            $actual['bytes'],
        ));
    }
}

function canonicalise(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map(canonicalise(...), $value);
    }
    ksort($value);

    return array_map(canonicalise(...), $value);
}

// ---------------------------------------------------------------------------
// surface: no-dev-exclusion
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $seal
 */
function assert_no_dev_exclusion(array $seal, Installation $installation): void
{
    $document = read_json($installation->installedJson);
    if (($document['dev'] ?? null) !== false) {
        fail('The --no-dev consumer\'s installed.json still reports a dev installation.');
    }
    if (($document['dev-package-names'] ?? []) !== []) {
        fail('The --no-dev consumer still records dev package names.');
    }

    // ADR-022 D-1.3 is asserted in the same breath as the third-party dev
    // dependencies, because it is the same property: `composer install
    // --no-dev` must remove the development metapackage AND everything it
    // pulls. `development_only` is derived from the seal rather than named
    // here, so growing the plane grows this assertion.
    /** @var list<string> $developmentOnly */
    $developmentOnly = $seal['development_only'];
    if ($developmentOnly === []) {
        fail('The seal records no development-only packages, so this assertion would prove nothing (#2655).');
    }

    foreach ([...$developmentOnly, 'phpunit/phpunit', 'wikimedia/composer-merge-plugin'] as $devOnly) {
        if (isset($installation->packages()[$devOnly])) {
            fail(sprintf('--no-dev install still carries the development dependency %s.', $devOnly));
        }
        if (is_dir($installation->vendor . '/' . $devOnly)) {
            fail(sprintf('--no-dev vendor tree still contains %s.', $devOnly));
        }
    }

    // The runtime half of the same property: dropping dev must not drop any
    // package the framework needs. alpha.106 -> alpha.107 shipped a class under
    // src/ that extended a dev-only symbol; a consumer's --no-dev install then
    // crashed at manifest compilation. Composition equality catches the
    // converse, and the harness boots this consumer for the rest.
    /** @var list<string> $expected */
    $expected = $seal['framework_closure'];
    $installed = array_keys($installation->waaseyaaPackages());
    sort($installed);
    $missing = array_values(array_diff($expected, $installed));
    if ($missing !== []) {
        fail('--no-dev dropped runtime framework packages: ' . implode(', ', $missing));
    }

    $psr4 = $installation->vendor . '/composer/autoload_psr4.php';
    if (is_file($psr4)) {
        /** @var array<string, mixed> $map */
        $map = require $psr4;
        foreach (array_keys($map) as $prefix) {
            if (str_starts_with((string) $prefix, 'Waaseyaa\\') && str_contains((string) $prefix, '\\Tests\\')) {
                fail(sprintf('--no-dev autoloader still registers the test namespace %s.', (string) $prefix));
            }
        }
    }
}

// ---------------------------------------------------------------------------
// negative controls
// ---------------------------------------------------------------------------

/**
 * Seeded negative controls (#2649 evidence standard).
 *
 * A gate that has only ever passed is not evidence. Every live assertion is
 * re-run here against a deliberately corrupted overlay and is REQUIRED to
 * fail. The overlays are surgical copies — one package directory, one
 * installed.json — so proving the teeth costs seconds, not another install.
 *
 * @param array<string, mixed> $seal
 */
function self_test(array $seal, Installation $dev, Installation $noDev, string $scratch): void
{
    if (!is_dir($scratch)) {
        mkdir($scratch, 0o777, true);
    }

    $controls = [];

    // #2543's exact failure mode: a manifest that silently stops reaching the
    // consumer while every in-repo gate stays green.
    $controls['pinned-fixture-removed'] = static function () use ($dev, $scratch): void {
        $overlay = clone_package($dev, 'waaseyaa/admin-surface', $scratch . '/pinned-fixture-removed');
        unlink($overlay->vendor . '/waaseyaa/admin-surface/dist.manifest.json');
        assert_admin_surface_pinned_fixture($overlay);
    };

    $controls['exported-file-removed'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = clone_package($dev, 'waaseyaa/admin-surface', $scratch . '/exported-file-removed');
        unlink($overlay->vendor . '/waaseyaa/admin-surface/dist.markers.json');
        assert_exported_files($seal, $overlay, ['waaseyaa/admin-surface']);
    };

    $controls['exported-byte-drift'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = clone_package($dev, 'waaseyaa/foundation', $scratch . '/exported-byte-drift');
        file_put_contents(
            $overlay->vendor . '/waaseyaa/foundation/composer.json',
            "\n",
            FILE_APPEND,
        );
        assert_exported_files($seal, $overlay, ['waaseyaa/foundation']);
    };

    $controls['admin-dist-byte-tampered'] = static function () use ($dev, $scratch): void {
        $overlay = clone_package($dev, 'waaseyaa/admin-surface', $scratch . '/admin-dist-tampered');
        $manifestPath = $overlay->vendor . '/waaseyaa/admin-surface/dist.manifest.json';
        $manifest = read_json($manifestPath);
        $distPath = $overlay->vendor . '/waaseyaa/admin-surface/'
            . (string) ($manifest['release']['distPath'] ?? 'dist');
        $victim = first_file($distPath);
        file_put_contents($victim, (string) file_get_contents($victim) . ' ');
        // Restrict to the #2543 procedure: the whole-package digest would also
        // fire, and this control exists to prove the manifest check itself
        // notices, not merely that something noticed.
        assert_admin_surface_pinned_fixture($overlay);
    };

    $controls['composition-package-dropped'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/composition-dropped.json', static function (array $document): array {
                $document['packages'] = array_values(array_filter(
                    $document['packages'],
                    static fn(array $package): bool => ($package['name'] ?? '') !== 'waaseyaa/entity',
                ));

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    $controls['composition-foreign-version'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/composition-version.json', static function (array $document): array {
                foreach ($document['packages'] as $index => $package) {
                    if (($package['name'] ?? '') === 'waaseyaa/entity') {
                        $document['packages'][$index]['version'] = '0.0.1';
                    }
                }

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    $controls['dist-outside-artifact-repo'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/composition-dist.json', static function (array $document): array {
                foreach ($document['packages'] as $index => $package) {
                    if (($package['name'] ?? '') === 'waaseyaa/entity') {
                        $document['packages'][$index]['dist']['url'] = 'https://registry.example.invalid/entity.zip';
                    }
                }

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    // The invariant #2655 relaxed for metapackages must still BITE for every
    // code-bearing package. A metapackage legitimately records no
    // installation-source; a library that records none was never extracted,
    // which is the packaging failure this surface exists to catch.
    $controls['code-bearing-package-without-dist'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/no-dist.json', static function (array $document): array {
                foreach ($document['packages'] as $index => $package) {
                    if (($package['name'] ?? '') === 'waaseyaa/entity') {
                        unset($document['packages'][$index]['installation-source']);
                    }
                }

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    // The relaxation is keyed on TYPE, so it must stop applying the moment the
    // package stops being a metapackage. This is the regression a name-keyed
    // exemption would have masked.
    $controls['metapackage-reclassified-as-library'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/reclassified.json', static function (array $document): array {
                foreach ($document['packages'] as $index => $package) {
                    if (($package['name'] ?? '') === 'waaseyaa/ai-development') {
                        $document['packages'][$index]['type'] = 'library';
                    }
                }

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    // And the metapackage branch is an assertion, not a skip: a metapackage
    // that suddenly reports an installation is a finding too.
    $controls['metapackage-reports-an-installation'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/meta-installed.json', static function (array $document): array {
                foreach ($document['packages'] as $index => $package) {
                    if (($package['name'] ?? '') === 'waaseyaa/ai-development') {
                        $document['packages'][$index]['installation-source'] = 'dist';
                    }
                }

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    // ADR-022 D-1.2, seeded. The development plane entering a production
    // require closure is the failure this whole design exists to prevent, and
    // "it has never happened" is not evidence that it would be caught.
    $controls['development-plane-in-production-closure'] = static function () use ($seal, $dev): void {
        $seal['framework_closure'][] = 'waaseyaa/ai-development';
        sort($seal['framework_closure']);
        assert_composition($seal, $dev);
    };

    // ADR-022 D-1.3, seeded at the consumer end: the plane installed, but
    // production-scoped. Everything above would still be green — the package
    // is present, the closure matches — and only the dev-scope check notices.
    $controls['development-plane-production-scoped'] = static function () use ($seal, $dev, $scratch): void {
        $overlay = $dev->withInstalledJson(
            mutate_installed_json($dev, $scratch . '/dev-scoped.json', static function (array $document): array {
                $document['dev-package-names'] = array_values(array_filter(
                    (array) ($document['dev-package-names'] ?? []),
                    static fn(mixed $name): bool => $name !== 'waaseyaa/ai-development',
                ));

                return $document;
            }),
        );
        assert_composition($seal, $overlay);
    };

    // The other half of D-1.3: `--no-dev` must actually remove it.
    $controls['development-package-retained-after-no-dev'] = static function () use ($seal, $noDev, $scratch): void {
        $overlay = $noDev->withInstalledJson(
            mutate_installed_json($noDev, $scratch . '/nodev-development.json', static function (array $document): array {
                $document['packages'][] = ['name' => 'waaseyaa/ai-development', 'version' => '0.0.0'];

                return $document;
            }),
        );
        assert_no_dev_exclusion($seal, $overlay);
    };

    $controls['source-symlink-installed'] = static function () use ($dev, $scratch): void {
        $root = $scratch . '/source-symlink';
        mkdir($root . '/vendor/waaseyaa', 0o777, true);
        symlink($dev->project, $root . '/vendor/waaseyaa/entity');
        assert_no_source_symlinks(new Installation($root, $root . '/vendor', $dev->installedJson));
    };

    $controls['dev-package-retained'] = static function () use ($seal, $noDev, $scratch): void {
        $overlay = $noDev->withInstalledJson(
            mutate_installed_json($noDev, $scratch . '/nodev-retained.json', static function (array $document): array {
                $document['packages'][] = ['name' => 'phpunit/phpunit', 'version' => '13.0.0'];

                return $document;
            }),
        );
        assert_no_dev_exclusion($seal, $overlay);
    };

    $controls['dev-flag-retained'] = static function () use ($seal, $noDev, $scratch): void {
        $overlay = $noDev->withInstalledJson(
            mutate_installed_json($noDev, $scratch . '/nodev-flag.json', static function (array $document): array {
                $document['dev'] = true;

                return $document;
            }),
        );
        assert_no_dev_exclusion($seal, $overlay);
    };

    $failures = [];
    foreach ($controls as $id => $control) {
        try {
            $control();
        } catch (AcceptanceFailure $caught) {
            printf("  negative control %-28s caught: %s\n", $id, first_line($caught->getMessage()));
            continue;
        }
        $failures[] = $id;
    }

    if ($failures !== []) {
        fail(
            "The acceptance harness did NOT fail on seeded corruption; it cannot be trusted when it passes.\n"
            . '  undetected: ' . implode(', ', $failures),
        );
    }

    printf("All %d seeded negative controls were detected.\n", count($controls));
}

function clone_package(Installation $source, string $package, string $target): Installation
{
    $vendor = $target . '/vendor';
    mkdir($vendor . '/' . dirname($package), 0o777, true);
    copy_tree($source->vendor . '/' . $package, $vendor . '/' . $package);

    return new Installation($target, $vendor, $source->installedJson);
}

function copy_tree(string $from, string $to): void
{
    mkdir($to, 0o777, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        $destination = $to . '/' . substr($entry->getPathname(), strlen($from) + 1);
        if ($entry->isDir()) {
            mkdir($destination, 0o777, true);
            continue;
        }
        copy($entry->getPathname(), $destination);
    }
}

/**
 * @param callable(array<string, mixed>): array<string, mixed> $mutate
 */
function mutate_installed_json(Installation $source, string $target, callable $mutate): string
{
    $document = read_json($source->installedJson);
    write_json($target, $mutate($document));

    return $target;
}

function first_file(string $directory): string
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    $paths = [];
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $paths[] = $entry->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    if ($paths === []) {
        fail("No file to tamper with under {$directory}");
    }

    return $paths[0];
}

function first_line(string $message): string
{
    $line = strtok($message, "\n");

    return $line === false ? $message : $line;
}

// ---------------------------------------------------------------------------
// entry point
// ---------------------------------------------------------------------------

function main(array $argv): int
{
    $command = $argv[1] ?? '';

    try {
        switch ($command) {
            case 'seal':
                seal($argv[2], $argv[3], $argv[4], $argv[5]);

                return 0;

            case 'surfaces':
                foreach (surfaces() as $surface) {
                    printf(
                        "  %-24s %-8s %s\n",
                        (string) $surface['id'],
                        strtoupper((string) $surface['status']),
                        (string) $surface['status'] === 'reserved'
                            ? 'reserved for ' . (string) $surface['blocked_by'] . ' — ' . (string) $surface['note']
                            : (string) $surface['implementation'],
                    );
                }

                return 0;

            case 'assert':
                $surface = (string) $argv[2];
                $seal = read_json($argv[3]);
                $consumer = Installation::of($argv[4]);
                match ($surface) {
                    'composition' => assert_composition($seal, $consumer),
                    'exported-files' => assert_exported_files($seal, $consumer),
                    'no-dev-exclusion' => assert_no_dev_exclusion($seal, $consumer),
                    default => fail("Unknown acceptance surface: {$surface}"),
                };
                printf("  surface %-24s PASS\n", $surface);

                return 0;

            case 'self-test':
                self_test(
                    read_json($argv[2]),
                    Installation::of($argv[3]),
                    Installation::of($argv[4]),
                    $argv[5],
                );

                return 0;

            default:
                fwrite(STDERR, "Unknown command: {$command}\n");

                return 2;
        }
    } catch (AcceptanceFailure $failure) {
        fwrite(STDERR, '::error::split-artifact acceptance: ' . $failure->getMessage() . "\n");

        return 1;
    }
}

exit(main($argv));
