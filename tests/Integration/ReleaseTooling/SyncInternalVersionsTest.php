<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ReleaseTooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../bin/lib/internal-version-sync.php';

/**
 * Integration tests for bin/sync-internal-versions and bin/lib/internal-version-sync.php.
 *
 * All filesystem operations use ephemeral temp directories cleaned up in tearDown().
 */
#[CoversNothing]
final class SyncInternalVersionsTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        $this->tempDirs = [];
    }

    // ── validateVersionInput ──────────────────────────────────────────────────

    #[Test]
    public function validate_input_accepts_clean_versions_with_or_without_v_prefix(): void
    {
        self::assertSame('0.1.0-alpha.176', validateVersionInput('0.1.0-alpha.176'));
        self::assertSame('0.1.0-alpha.176', validateVersionInput('v0.1.0-alpha.176'));
        self::assertSame('1.2.3', validateVersionInput('1.2.3'));
        self::assertSame('1.2.3', validateVersionInput('v1.2.3'));
        self::assertSame('0.1.0-alpha.999', validateVersionInput('0.1.0-alpha.999'));
        self::assertSame('2.0.0-beta.1', validateVersionInput('2.0.0-beta.1'));
        self::assertSame('10.20.30', validateVersionInput('10.20.30'));
    }

    /** @return array<string, array{string}> */
    public static function invalidVersionProvider(): array
    {
        return [
            'empty string'     => [''],
            'whitespace only'  => ['   '],
            'dev-main'         => ['dev-main'],
            'dev-feature'      => ['dev-feature/something'],
            'self.version'     => ['self.version'],
            'caret-wildcard'   => ['^*'],
            'wildcard star'    => ['0.1.*'],
            'question mark'    => ['0.1.?'],
            'x placeholder'    => ['0.1.x'],
            'X placeholder'    => ['0.1.X'],
            'leading space'    => [' 0.1.0'],
            'embedded tab'     => ["0.1.0\t"],
            'no patch'         => ['0.1'],
            'letters only'     => ['stable'],
            'caret version'    => ['^0.1.0'],
        ];
    }

    #[Test]
    #[DataProvider('invalidVersionProvider')]
    public function validate_input_rejects_empty_dev_self_version_wildcards_and_placeholders(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validateVersionInput($input);
    }

    // ── findInternalDeps ──────────────────────────────────────────────────────

    #[Test]
    public function find_internal_deps_returns_waaseyaa_keys_from_require_and_require_dev(): void
    {
        $manifest = [
            'name' => 'waaseyaa/test',
            'require' => [
                'php' => '>=8.5',
                'waaseyaa/foundation' => '^0.1.0-alpha.150',
                'symfony/console' => '^7.0',
                'waaseyaa/entity' => '^0.1.0-alpha.150',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^13.0',
                'waaseyaa/testing' => '^0.1.0-alpha.150',
            ],
        ];

        $deps = findInternalDeps($manifest);

        self::assertSame([
            'waaseyaa/entity',
            'waaseyaa/foundation',
            'waaseyaa/testing',
        ], $deps);
    }

    #[Test]
    public function find_internal_deps_returns_empty_when_no_waaseyaa_deps(): void
    {
        $manifest = [
            'name' => 'waaseyaa/test',
            'require' => [
                'php' => '>=8.5',
                'symfony/console' => '^7.0',
            ],
        ];

        self::assertSame([], findInternalDeps($manifest));
    }

    #[Test]
    public function find_internal_deps_deduplicates_keys_present_in_both_sections(): void
    {
        $manifest = [
            'require' => ['waaseyaa/foundation' => '^0.1.0-alpha.150'],
            'require-dev' => ['waaseyaa/foundation' => '^0.1.0-alpha.150'],
        ];

        $deps = findInternalDeps($manifest);
        self::assertSame(['waaseyaa/foundation'], $deps);
    }

    // ── expectedConstraint ────────────────────────────────────────────────────

    #[Test]
    public function expected_constraint_caret_prefix(): void
    {
        self::assertSame('^0.1.0-alpha.176', expectedConstraint('0.1.0-alpha.176'));
        self::assertSame('^1.2.3', expectedConstraint('1.2.3'));
        self::assertSame('^0.1.0-alpha.999', expectedConstraint('0.1.0-alpha.999'));
    }

    // ── sync via JsonFile (integration) ───────────────────────────────────────

    #[Test]
    public function sync_rewrites_internal_constraints_to_target_version(): void
    {
        $dir = $this->makeTempPackageDir([
            'packages/mypackage/composer.json' => $this->fixtureContent([
                'waaseyaa/foundation' => '^0.1.0-alpha.150',
                'waaseyaa/entity' => '^0.1.0-alpha.150',
            ]),
        ]);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $manifest = $this->readManifest($dir . '/packages/mypackage/composer.json');
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/foundation']);
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/entity']);
    }

    #[Test]
    public function sync_includes_the_create_project_skeleton(): void
    {
        // #1622: the skeleton is not a packages/* manifest, so the release sync
        // used to skip it — its waaseyaa/framework constraint drifted behind the
        // line (it sat at alpha.199 while the line was alpha.242), and a
        // `composer create-project` could resolve a stale set. It must now be
        // synced alongside the packages.
        $dir = $this->makeTempPackageDir([
            'packages/mypackage/composer.json' => $this->fixtureContent([
                'waaseyaa/foundation' => '^0.1.0-alpha.150',
            ]),
            'skeleton/composer.json' => $this->fixtureContent([
                'waaseyaa/framework' => '^0.1.0-alpha.150',
            ]),
        ]);

        // discoverSyncManifests() — the single source of truth — must surface the
        // skeleton (separator-normalised for cross-platform path comparison).
        $discovered = array_map(
            static fn(string $p): string => str_replace('\\', '/', $p),
            discoverSyncManifests($dir),
        );
        self::assertContains(
            str_replace('\\', '/', $dir) . '/skeleton/composer.json',
            $discovered,
            'the create-project skeleton must be in the synced manifest set',
        );

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $skeleton = $this->readManifest($dir . '/skeleton/composer.json');
        self::assertSame(
            '^0.1.0-alpha.999',
            $skeleton['require']['waaseyaa/framework'],
            'the skeleton framework constraint must track the released line so create-project installs the current set',
        );
    }

    #[Test]
    public function sync_is_idempotent(): void
    {
        $dir = $this->makeTempPackageDir([
            'packages/mypackage/composer.json' => $this->fixtureContent([
                'waaseyaa/foundation' => '^0.1.0-alpha.150',
            ]),
        ]);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $mtimeBefore = filemtime($dir . '/packages/mypackage/composer.json');

        // Small sleep to ensure mtime would differ if file was rewritten
        usleep(10000);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $mtimeAfter = filemtime($dir . '/packages/mypackage/composer.json');

        self::assertSame($mtimeBefore, $mtimeAfter, 'Second run must not touch already-current files.');
    }

    #[Test]
    public function sync_preserves_json_formatting_trailing_comma_and_key_order(): void
    {
        $fixtureSource = __DIR__ . '/../../Fixtures/release-tooling/manifest-unusual-key-order.json';
        $dir = $this->makeTempPackageDir([]);

        mkdir($dir . '/packages/unusual', 0o755, true);
        copy($fixtureSource, $dir . '/packages/unusual/composer.json');

        $originalContent = (string) file_get_contents($fixtureSource);
        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $updatedContent = (string) file_get_contents($dir . '/packages/unusual/composer.json');

        // Key order must be preserved: description appears before name in fixture
        $descPos = strpos($updatedContent, '"description"');
        $namePos = strpos($updatedContent, '"name"');
        self::assertNotFalse($descPos);
        self::assertNotFalse($namePos);
        self::assertLessThan($namePos, $descPos, '"description" must still appear before "name".');

        // php key must still be present and unchanged
        self::assertStringContainsString('"php"', $updatedContent);

        // waaseyaa constraints updated
        $manifest = $this->readManifest($dir . '/packages/unusual/composer.json');
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/access']);
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/foundation']);
    }

    #[Test]
    public function sync_skips_non_waaseyaa_dependencies(): void
    {
        $dir = $this->makeTempPackageDir([
            'packages/mypkg/composer.json' => json_encode([
                'name' => 'waaseyaa/mypkg',
                'require' => [
                    'php' => '>=8.5',
                    'symfony/console' => '^7.0',
                    'waaseyaa/foundation' => '^0.1.0-alpha.150',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        ]);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $manifest = $this->readManifest($dir . '/packages/mypkg/composer.json');
        self::assertSame('>=8.5', $manifest['require']['php']);
        self::assertSame('^7.0', $manifest['require']['symfony/console']);
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/foundation']);
    }

    #[Test]
    public function sync_rewrites_require_dev_waaseyaa_deps(): void
    {
        $fixtureSource = __DIR__ . '/../../Fixtures/release-tooling/manifest-with-require-dev.json';
        $dir = $this->makeTempPackageDir([]);

        mkdir($dir . '/packages/withdev', 0o755, true);
        copy($fixtureSource, $dir . '/packages/withdev/composer.json');

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $manifest = $this->readManifest($dir . '/packages/withdev/composer.json');
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/foundation']);
        self::assertSame('^0.1.0-alpha.999', $manifest['require-dev']['waaseyaa/testing']);
        // Non-waaseyaa require-dev must be untouched
        self::assertSame('^13.0', $manifest['require-dev']['phpunit/phpunit']);
    }

    #[Test]
    public function sync_updates_root_lock_path_package_metadata_without_touching_unrelated_packages(): void
    {
        $dir = $this->makeTempPackageDir([
            'packages/agent/composer.json' => json_encode([
                'name' => 'waaseyaa/agent',
                'require' => [
                    'php' => '>=8.5',
                    'waaseyaa/foundation' => '^0.1.0-alpha.150',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'composer.lock' => json_encode([
                '_readme' => ['fixture'],
                'content-hash' => 'unchanged-root-hash',
                'packages' => [
                    [
                        'name' => 'third-party/example',
                        'version' => '1.2.3',
                        'require' => ['php' => '>=8.2'],
                    ],
                    [
                        'name' => 'waaseyaa/agent',
                        'version' => 'dev-main',
                        'require' => [
                            'php' => '>=8.5',
                            'waaseyaa/foundation' => '^0.1.0-alpha.150',
                        ],
                    ],
                ],
                'packages-dev' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        ]);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $lock = $this->readManifest($dir . '/composer.lock');
        self::assertSame('unchanged-root-hash', $lock['content-hash']);
        self::assertSame(
            ['php' => '>=8.2'],
            $lock['packages'][0]['require'],
            'Third-party lock metadata must not be rewritten.',
        );
        self::assertSame(
            [
                'php' => '>=8.5',
                'waaseyaa/foundation' => '^0.1.0-alpha.999',
            ],
            $lock['packages'][1]['require'],
        );

        $first = (string) file_get_contents($dir . '/composer.lock');
        $this->runSyncScript($dir, '0.1.0-alpha.999');
        self::assertSame($first, file_get_contents($dir . '/composer.lock'));
    }

    #[Test]
    public function sync_refuses_to_hide_structural_manifest_lock_drift(): void
    {
        $dir = $this->makeTempPackageDir([
            'packages/agent/composer.json' => json_encode([
                'name' => 'waaseyaa/agent',
                'require' => [
                    'php' => '>=8.5',
                    'waaseyaa/foundation' => '^0.1.0-alpha.150',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'composer.lock' => json_encode([
                'packages' => [[
                    'name' => 'waaseyaa/agent',
                    'version' => 'dev-main',
                    'require' => ['php' => '>=8.5'],
                ]],
                'packages-dev' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dependency-key drift');

        $this->runSyncScript($dir, '0.1.0-alpha.999');
    }

    #[Test]
    public function sync_preserves_internal_suggest_descriptions(): void
    {
        $description = 'Enables the optional CLI command integrations.';
        $dir = $this->makeTempPackageDir([
            'packages/with-suggest/composer.json' => json_encode([
                'name' => 'waaseyaa/with-suggest',
                'require' => [
                    'waaseyaa/foundation' => '^0.1.0-alpha.150',
                ],
                'suggest' => [
                    'waaseyaa/cli' => $description,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        ]);

        $this->runSyncScript($dir, '0.1.0-alpha.999');

        $manifest = $this->readManifest($dir . '/packages/with-suggest/composer.json');
        self::assertSame('^0.1.0-alpha.999', $manifest['require']['waaseyaa/foundation']);
        self::assertSame($description, $manifest['suggest']['waaseyaa/cli']);
    }

    // ── resolveCurrentVersion (live) ──────────────────────────────────────────

    #[Test]
    public function resolve_current_version_returns_tag_minus_v_prefix(): void
    {
        $repoRoot = dirname(__DIR__, 3);

        try {
            $version = resolveCurrentVersion($repoRoot);
        } catch (\RuntimeException $e) {
            self::markTestSkipped('No semver git tags found: ' . $e->getMessage());
        }

        // Must be a valid semver string without leading 'v'
        self::assertMatchesRegularExpression(
            '/^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$/',
            $version,
            'Resolved version must match semver shape without v-prefix.',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a temporary directory structure with the given files.
     *
     * @param array<string, string> $files Map of relative path → content.
     */
    private function makeTempPackageDir(array $files): string
    {
        $base = sys_get_temp_dir() . '/waaseyaa_sync_test_' . uniqid('', true);
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        foreach ($files as $relPath => $content) {
            $absPath = $base . '/' . $relPath;
            if (!is_dir(dirname($absPath))) {
                mkdir(dirname($absPath), 0o755, true);
            }
            file_put_contents($absPath, $content);
        }

        return $base;
    }

    /**
     * Run the sync logic in-process against the SAME manifest set the script
     * syncs — `discoverSyncManifests()` (packages/(*) + the skeleton), the single
     * source of truth — so the test cannot drift from the script's discovery.
     */
    private function runSyncScript(string $repoRoot, string $version): void
    {
        $normalized = validateVersionInput($version);
        $constraint = expectedConstraint($normalized);

        foreach (discoverSyncManifests($repoRoot) as $manifestPath) {
            syncManifestFile($manifestPath, $constraint);
        }

        $lockPath = $repoRoot . '/composer.lock';
        if (is_file($lockPath)) {
            syncRootLockFile($lockPath, discoverSyncManifests($repoRoot));
        }
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content, "Could not read manifest: {$path}");
        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data, "Manifest is not a JSON object: {$path}");
        return $data;
    }

    /**
     * @param array<string, string> $requireEntries Map of package => constraint.
     */
    private function fixtureContent(array $requireEntries): string
    {
        $require = array_merge(['php' => '>=8.5'], $requireEntries);

        return json_encode([
            'name' => 'waaseyaa/fixture-package',
            'require' => $require,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $dir . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($dir);
    }
}
