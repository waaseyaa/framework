<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Regression proof for #2926: a stale local vendor/ must surface as an
 * actionable precondition failure with its own exit code, never as a PHP
 * fatal (exit 255 + stack trace) and never as a repository defect.
 *
 * Every case builds a deliberately stale or incomplete vendor fixture under a
 * fresh temp directory — a fake composer.lock / vendor/composer/installed.json
 * pair, a fake dumped PSR-4 map, and a fake vendor/autoload.php — and exercises
 * the shared precondition (bin/lib/vendor-freshness.php) directly, then through
 * the two gate scripts the issue named and through bin/check-pr-preflight.
 */
#[CoversNothing]
final class VendorFreshnessPreconditionTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/bin/lib/vendor-freshness.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            new Filesystem()->remove($fixture);
        }
        $this->fixtures = [];
    }

    // ── the shared precondition itself ───────────────────────────────────────

    #[Test]
    public function the_precondition_exit_code_is_distinct_from_defect_and_infrastructure_codes(): void
    {
        self::assertTrue(defined('VENDOR_FRESHNESS_EXIT_CODE'));
        self::assertNotContains(VENDOR_FRESHNESS_EXIT_CODE, [0, 1, 2, 255], 'The precondition must not collide with pass, defect, infrastructure, or PHP fatal exit codes.');
    }

    #[Test]
    public function a_fresh_vendor_reports_no_problem(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa'), self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('phpunit/phpunit', '10.5.0', 'cccc')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa'), self::pkg('waaseyaa/cli', 'dev-main', 'bbbb'), self::pkg('phpunit/phpunit', '10.5.0', 'cccc')],
            declaredNamespaces: ['Waaseyaa\\CLI\\Io\\'],
            dumpedNamespaces: ['Waaseyaa\\CLI\\Io\\'],
        );

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_locked_package_absent_from_installed_json_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('opis/json-schema', $problem['detail']);
    }

    #[Test]
    public function a_name_matched_but_version_mismatched_package_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.5.0', 'aaaa')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('opis/json-schema', $problem['detail']);
        self::assertStringContainsString('2.6.0', $problem['detail']);
        self::assertStringContainsString('2.5.0', $problem['detail']);
    }

    #[Test]
    public function a_name_and_version_matched_but_reference_mismatched_package_is_stale(): void
    {
        // The monorepo's own path-repo packages are always dev-main; only the
        // locked reference tells one checkout's package bytes from another's.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'ffff')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('waaseyaa/cli', $problem['detail']);
    }

    #[Test]
    public function an_installed_package_absent_from_the_lock_is_stale(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb'), self::pkg('acme/leftover', '1.0.0', 'dddd')],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('acme/leftover', $problem['detail']);
    }

    #[Test]
    public function a_declared_psr4_namespace_missing_from_the_dumped_map_is_stale(): void
    {
        // The #2926 StdinSource shape: root composer.json autoload-dev maps
        // Waaseyaa\CLI\Io\ but the dumped autoloader predates the mapping.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            declaredNamespaces: ['Waaseyaa\\CLI\\Io\\'],
            dumpedNamespaces: [],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer dump-autoload', $problem['fix']);
        self::assertStringContainsString('Waaseyaa\\CLI\\Io\\', $problem['detail']);
    }

    #[Test]
    public function a_locked_package_psr4_namespace_missing_from_the_dumped_map_is_stale(): void
    {
        // Composer dumps every LOCKED package's `autoload` PSR-4 roots (never
        // its autoload-dev), so a missing one means the map predates the
        // install — the same fix as a missing root namespace.
        $locked = self::pkg('waaseyaa/cli', 'dev-main', 'bbbb') + [
            'autoload' => ['psr-4' => ['Waaseyaa\\CLI\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Waaseyaa\\CLI\\Tests\\' => 'tests/']],
        ];
        $root = $this->fixture(locked: [$locked], installed: [$locked], dumpedNamespaces: []);

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer dump-autoload', $problem['fix']);
        self::assertStringContainsString('Waaseyaa\\CLI\\ (waaseyaa/cli)', $problem['detail']);
        self::assertStringNotContainsString('Waaseyaa\\CLI\\Tests\\', $problem['detail'], 'A dependency autoload-dev root is never dumped, so its absence is not staleness.');

        $fresh = $this->fixture(locked: [$locked], installed: [$locked], dumpedNamespaces: ['Waaseyaa\\CLI\\']);
        self::assertNull(vendor_freshness_problem($fresh));
    }

    #[Test]
    public function equal_package_metadata_cannot_hide_a_runtime_static_psr4_map_bound_to_a_donor_checkout(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-vendor-donor-');
        new Filesystem()->mkdir($donor . '/src');
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            declaredNamespaces: ['Waaseyaa\\Candidate\\'],
            dumpedNamespaces: ['Waaseyaa\\Candidate\\'],
        );
        $this->writeAutoloadMaps(
            $root,
            psr4: ['Waaseyaa\\Candidate\\' => [$root . '/x']],
            staticPsr4: ['Waaseyaa\\Candidate\\' => [$donor . '/src']],
        );

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
        self::assertStringContainsString('runtime-static PSR-4', $problem['detail']);
        self::assertStringContainsString($donor . '/src', $problem['detail']);
        self::assertStringContainsString($root, $problem['detail']);
    }

    #[Test]
    public function an_ordered_compatibility_psr4_fallback_cannot_include_a_donor_path(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-compat-donor-');
        new Filesystem()->mkdir($donor . '/src');
        $root = $this->fixture(
            locked: [],
            installed: [],
            declaredNamespaces: ['Waaseyaa\\Candidate\\'],
            dumpedNamespaces: ['Waaseyaa\\Candidate\\'],
        );
        $this->writeAutoloadMaps(
            $root,
            psr4: ['Waaseyaa\\Candidate\\' => [$root . '/x', $donor . '/src']],
            staticPsr4: ['Waaseyaa\\Candidate\\' => [$root . '/x']],
        );

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString('compatibility PSR-4', $problem['detail']);
        self::assertStringContainsString($donor . '/src', $problem['detail']);
    }

    #[Test]
    public function a_more_specific_generated_prefix_cannot_shadow_an_owned_prefix_with_a_donor(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-prefix-donor-');
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        $this->writeAutoloadMaps($root, psr4: [
            'Project\\' => [$root . '/x'],
            'Project\\Feature\\' => [$donor],
        ]);

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString('PSR-4 Project\\Feature\\', $problem['detail']);
        self::assertStringContainsString($donor, $problem['detail']);
    }

    #[Test]
    public function a_more_specific_prefix_declared_by_an_external_package_remains_third_party(): void
    {
        $donor = $this->scratchDirectory('acme-feature-package-');
        new Filesystem()->mkdir($donor . '/src');
        $package = self::pathPkg('acme/feature', $donor, 'Project\\Feature\\');
        $root = $this->fixture(
            locked: [$package],
            installed: [$package],
            declaredNamespaces: ['Project\\'],
            dumpedNamespaces: ['Project\\'],
        );
        $this->writeAutoloadMaps($root, psr4: [
            'Project\\' => [$root . '/x'],
            'Project\\Feature\\' => [$donor . '/src'],
        ]);

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function an_optimized_first_party_classmap_entry_cannot_override_psr4_with_a_donor_file(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-classmap-donor-');
        new Filesystem()->dumpFile($donor . '/Candidate.php', "<?php\n");
        $root = $this->fixture(
            locked: [],
            installed: [],
            declaredNamespaces: ['Waaseyaa\\Candidate\\'],
            dumpedNamespaces: ['Waaseyaa\\Candidate\\'],
        );
        new Filesystem()->dumpFile($root . '/x/Candidate.php', "<?php\n");
        $this->writeAutoloadMaps(
            $root,
            psr4: ['Waaseyaa\\Candidate\\' => [$root . '/x']],
            classmap: ['Waaseyaa\\Candidate\\Candidate' => $root . '/x/Candidate.php'],
            staticClassmap: ['Waaseyaa\\Candidate\\Candidate' => $donor . '/Candidate.php'],
        );

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString('runtime-static classmap Waaseyaa\\Candidate\\Candidate', $problem['detail']);
        self::assertStringContainsString($donor . '/Candidate.php', $problem['detail']);
    }

    #[Test]
    public function a_first_party_autoload_file_cannot_be_loaded_from_a_donor_checkout(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-files-donor-');
        new Filesystem()->dumpFile($donor . '/bootstrap.php', "<?php\n");
        $root = $this->fixture(locked: [], installed: [], declaredFiles: ['bootstrap.php']);
        $identifier = md5('waaseyaa/framework:bootstrap.php');
        $this->writeAutoloadMaps(
            $root,
            psr4: [],
            files: [$identifier => $root . '/bootstrap.php'],
            staticFiles: [$identifier => $donor . '/bootstrap.php'],
        );

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString('runtime-static autoload-file', $problem['detail']);
        self::assertStringContainsString($donor . '/bootstrap.php', $problem['detail']);
    }

    #[Test]
    public function candidate_local_psr4_classmap_and_autoload_files_are_accepted_together(): void
    {
        $root = $this->fixture(
            locked: [],
            installed: [],
            declaredNamespaces: ['Waaseyaa\\Candidate\\'],
            dumpedNamespaces: ['Waaseyaa\\Candidate\\'],
            declaredFiles: ['bootstrap.php'],
        );
        new Filesystem()->dumpFile($root . '/x/Candidate.php', "<?php\n");
        $identifier = md5('waaseyaa/framework:bootstrap.php');
        $this->writeAutoloadMaps(
            $root,
            psr4: ['Waaseyaa\\Candidate\\' => [$root . '/x']],
            classmap: ['Waaseyaa\\Candidate\\Candidate' => $root . '/x/Candidate.php'],
            files: [$identifier => $root . '/bootstrap.php'],
        );

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function composer_may_omit_the_compatibility_files_map_when_no_autoload_files_exist(): void
    {
        $root = $this->fixture(locked: [], installed: []);
        unlink($root . '/vendor/composer/autoload_files.php');

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_missing_compatibility_files_map_is_refused_when_a_file_is_declared(): void
    {
        $root = $this->fixture(locked: [], installed: [], declaredFiles: ['bootstrap.php']);
        unlink($root . '/vendor/composer/autoload_files.php');

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertSame('composer dump-autoload', $problem['fix']);
        self::assertStringContainsString('autoload_files.php', $problem['detail']);
    }

    #[Test]
    public function a_lexically_candidate_owned_path_package_symlinked_to_a_donor_is_rejected(): void
    {
        $donor = $this->scratchDirectory('waaseyaa-path-package-donor-');
        new Filesystem()->mkdir($donor . '/src');
        $package = self::pathPkg('waaseyaa/candidate', 'packages/candidate', 'Waaseyaa\\Candidate\\');
        $root = $this->fixture(
            locked: [$package],
            installed: [$package],
            dumpedNamespaces: ['Waaseyaa\\Candidate\\'],
        );
        new Filesystem()->mkdir($root . '/packages');
        self::assertTrue(symlink($donor, $root . '/packages/candidate'));

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString('path package waaseyaa/candidate', $problem['what']);
        self::assertStringContainsString($root . '/packages/candidate', $problem['detail']);
        self::assertStringContainsString($donor, $problem['detail']);
    }

    #[Test]
    public function canonical_paths_allow_candidate_internal_symlinks_but_reject_external_ones(): void
    {
        $fs = new Filesystem();
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Waaseyaa\\Candidate\\'], dumpedNamespaces: ['Waaseyaa\\Candidate\\']);
        $fs->remove($root . '/x');
        $fs->mkdir($root . '/source');
        self::assertTrue(symlink($root . '/source', $root . '/x'));
        self::assertNull(vendor_freshness_problem($root), 'An alias that still resolves inside the candidate is safe.');

        $donor = $this->scratchDirectory('waaseyaa-external-target-');
        $external = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Waaseyaa\\Candidate\\'], dumpedNamespaces: ['Waaseyaa\\Candidate\\']);
        $fs->remove($external . '/x');
        self::assertTrue(symlink($donor, $external . '/x'));

        $problem = vendor_freshness_problem($external);
        self::assertNotNull($problem);
        self::assertStringContainsString($donor, $problem['detail']);
    }

    #[Test]
    public function a_missing_psr4_leaf_under_a_candidate_ancestor_is_valid_composer_output(): void
    {
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        new Filesystem()->remove($root . '/x');

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_missing_psr4_leaf_under_a_donor_symlink_ancestor_is_rejected(): void
    {
        $fs = new Filesystem();
        $donor = $this->scratchDirectory('waaseyaa-missing-leaf-donor-');
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        $fs->remove($root . '/x');
        self::assertTrue(symlink($donor, $root . '/x'));
        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => ['Project\\' => 'x/missing/']],
        ], JSON_THROW_ON_ERROR));
        $this->writeAutoloadMaps($root, psr4: ['Project\\' => [$root . '/x/missing']]);

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString($donor . '/missing', $problem['detail']);
    }

    #[Test]
    public function a_missing_psr4_leaf_beneath_a_dangling_symlink_is_unverifiable_and_rejected(): void
    {
        $fs = new Filesystem();
        $donor = $this->scratchDirectory('waaseyaa-dangling-target-');
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        $fs->remove($root . '/x');
        self::assertTrue(symlink($donor . '/absent', $root . '/x'));
        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => ['Project\\' => 'x/missing/']],
        ], JSON_THROW_ON_ERROR));
        $this->writeAutoloadMaps($root, psr4: ['Project\\' => [$root . '/x/missing']]);

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString($root . '/x/missing', $problem['detail']);
    }

    #[Test]
    public function parent_traversal_after_a_symlink_is_resolved_by_the_filesystem_before_containment(): void
    {
        $fs = new Filesystem();
        $donor = $this->scratchDirectory('waaseyaa-parent-traversal-donor-');
        $fs->mkdir($donor . '/subdir');
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => ['Project\\' => 'missing/']],
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(symlink($donor . '/subdir', $root . '/link'));
        $this->writeAutoloadMaps($root, psr4: ['Project\\' => [$root . '/link/../missing']]);

        $problem = vendor_freshness_problem($root);

        self::assertNotNull($problem);
        self::assertStringContainsString($donor . '/missing', $problem['detail']);
    }

    #[Test]
    public function ordinary_parent_traversal_without_a_symlink_stays_candidate_local(): void
    {
        $fs = new Filesystem();
        $root = $this->fixture(locked: [], installed: [], declaredNamespaces: ['Project\\'], dumpedNamespaces: ['Project\\']);
        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => ['Project\\' => 'missing/']],
        ], JSON_THROW_ON_ERROR));
        $fs->mkdir($root . '/nested');
        $this->writeAutoloadMaps($root, psr4: ['Project\\' => [$root . '/nested/../missing']]);

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_whole_vendor_symlink_to_an_equal_lock_donor_is_rejected_without_rewriting_the_donor(): void
    {
        $fs = new Filesystem();
        $package = self::pkg('acme/external', '1.0.0', 'aaaa');
        $donor = $this->fixture(locked: [$package], installed: [$package]);
        $candidate = $this->fixture(locked: [$package], installed: [$package]);
        $fs->remove($candidate . '/vendor');
        self::assertTrue(symlink($donor . '/vendor', $candidate . '/vendor'));

        $problem = vendor_freshness_problem($candidate);

        self::assertNotNull($problem);
        self::assertSame('unlink vendor && composer install', $problem['fix']);
        self::assertStringContainsString($donor . '/vendor/autoload.php', $problem['detail']);
        self::assertFileExists($donor . '/vendor/autoload.php', 'Detection must not mutate the donor.');
    }

    #[Test]
    public function a_nested_composer_metadata_symlink_is_unlinked_before_install_is_suggested(): void
    {
        $fs = new Filesystem();
        $donor = $this->fixture(locked: [], installed: []);
        $candidate = $this->fixture(locked: [], installed: []);
        $fs->remove($candidate . '/vendor/composer');
        self::assertTrue(symlink($donor . '/vendor/composer', $candidate . '/vendor/composer'));

        $problem = vendor_freshness_problem($candidate);

        self::assertNotNull($problem);
        self::assertSame('unlink vendor/composer && composer install', $problem['fix']);
        self::assertStringContainsString($donor . '/vendor/composer', $problem['detail']);
        self::assertFileExists($donor . '/vendor/composer/autoload_static.php', 'Detection must not mutate the donor metadata.');
    }

    #[Test]
    public function an_external_third_party_mapping_is_not_misclassified_as_candidate_owned(): void
    {
        $donor = $this->scratchDirectory('acme-third-party-');
        new Filesystem()->dumpFile($donor . '/External.php', "<?php\n");
        $package = self::pathPkg('acme/external', $donor, 'Acme\\External\\');
        $root = $this->fixture(locked: [$package], installed: [$package], dumpedNamespaces: ['Acme\\External\\']);
        $this->writeAutoloadMaps(
            $root,
            psr4: ['Acme\\External\\' => [$donor]],
            classmap: ['Acme\\External\\External' => $donor . '/External.php'],
        );

        self::assertNull(vendor_freshness_problem($root));
    }

    #[Test]
    public function a_missing_vendor_directory_is_reported_not_fatal(): void
    {
        $root = $this->fixture(locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')], installed: [], withVendor: false);

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertSame('composer install', $problem['fix']);
    }

    #[Test]
    public function a_missing_composer_lock_is_reported_not_fatal(): void
    {
        $root = $this->fixture(locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')], installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')]);
        unlink($root . '/composer.lock');

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        self::assertStringContainsString('composer.lock', $problem['detail']);
    }

    #[Test]
    public function the_rendered_message_is_actionable_and_names_the_calling_tool(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );

        $problem = vendor_freshness_problem($root);
        self::assertNotNull($problem);
        $message = vendor_freshness_message($problem, 'some-gate');

        self::assertStringContainsString('some-gate:', $message);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $message);
        self::assertStringContainsString('run `composer install`', $message);
        self::assertStringContainsString('opis/json-schema', $message);
    }

    // ── bin/check-delivery-agent-events ──────────────────────────────────────

    #[Test]
    public function delivery_agent_gate_reports_a_stale_vendor_instead_of_a_fatal_when_opis_is_missing(): void
    {
        // opis/json-schema is locked (root require-dev) but absent from
        // installed.json — the exact #2926 condition. Before the fix this was
        // `Error: Class "Opis\JsonSchema\Validator" not found`, exit 255.
        $root = $this->fixture(
            locked: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
            lockedDev: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('waaseyaa/cli', 'dev-main', 'bbbb')],
        );
        $this->seedDeliveryGate($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-delivery-agent-events', '--self-test'], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringContainsString('opis/json-schema', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('Uncaught', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('PASS', $result['stdout']);
    }

    #[Test]
    public function delivery_agent_gate_reports_an_unloadable_validator_even_when_vendor_metadata_looks_fresh(): void
    {
        // Metadata agrees with the lock, but the autoloader cannot provide the
        // validator class (e.g. the package directory was deleted by hand).
        // The gate must still refuse with the precondition code, never fatal.
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
        );
        $this->seedDeliveryGate($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-delivery-agent-events', '--self-test'], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('opis/json-schema', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('Uncaught', $result['stderr'] . $result['stdout']);
    }

    #[Test]
    public function a_freshness_check_does_not_poison_a_later_composer_static_map_load(): void
    {
        $root = $this->fixture(locked: [], installed: []);
        $probe = <<<'PHP'
            <?php
            require $argv[2];
            $problem = vendor_freshness_problem($argv[1]);
            if ($problem !== null) {
                fwrite(STDERR, $problem['what'] . ': ' . $problem['detail']);
                exit(2);
            }
            require $argv[1] . '/vendor/composer/autoload_static.php';
            fwrite(STDOUT, "autoload-static-ok\n");
            PHP;
        $probePath = $root . '/freshness-then-autoload.php';
        new Filesystem()->dumpFile($probePath, $probe);

        $result = $this->runProcess([PHP_BINARY, $probePath, $root, $this->root . '/bin/lib/vendor-freshness.php'], $root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertSame("autoload-static-ok\n", $result['stdout']);
        self::assertStringNotContainsString('Cannot redeclare class', $result['stderr']);
    }

    // ── bin/check-pr-preflight ───────────────────────────────────────────────

    #[Test]
    public function preflight_short_circuits_with_the_precondition_code_before_running_any_gate(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--manifest=' . $manifest], $root);

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('vendor/ is stale relative to composer.lock', $result['stderr']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertStringNotContainsString('gate-ran', $result['stdout'], 'No gate may run against a stale vendor/.');
        self::assertStringNotContainsString('FAIL', $result['stdout'], 'A stale vendor/ is not a gate failure.');
    }

    #[Test]
    public function preflight_runs_its_gates_when_vendor_is_fresh(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--manifest=' . $manifest], $root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        // Preflight prints a gate's captured output only on failure; a green
        // gate shows as its "ok" roster line.
        self::assertStringContainsString('ok   synthetic-gate', $result['stdout']);
        self::assertStringNotContainsString('vendor/ is stale', $result['stderr']);
    }

    #[Test]
    public function preflight_list_mode_does_not_require_a_fresh_vendor(): void
    {
        $root = $this->fixture(
            locked: [self::pkg('opis/json-schema', '2.6.0', 'aaaa')],
            installed: [],
        );
        $this->seedPreflight($root);
        $manifest = $this->writeManifest($root);

        $result = $this->runProcess([PHP_BINARY, $root . '/bin/check-pr-preflight', '--list', '--manifest=' . $manifest], $root);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('synthetic-gate', $result['stdout']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{name: string, version: string, dist: array{type: string, reference: string}} */
    private static function pkg(string $name, string $version, string $reference): array
    {
        return ['name' => $name, 'version' => $version, 'dist' => ['type' => 'zip', 'reference' => $reference]];
    }

    /** @return array<string, mixed> */
    private static function pathPkg(string $name, string $url, string $namespace): array
    {
        return [
            'name' => $name,
            'version' => 'dev-main',
            'dist' => ['type' => 'path', 'url' => $url, 'reference' => 'bbbb'],
            'autoload' => ['psr-4' => [$namespace => 'src/']],
        ];
    }

    /**
     * @param list<array<string, mixed>> $locked
     * @param list<array<string, mixed>> $lockedDev
     * @param list<array<string, mixed>> $installed
     * @param list<string> $declaredNamespaces
     * @param list<string> $dumpedNamespaces
     * @param list<string> $declaredFiles
     */
    private function fixture(
        array $locked,
        array $installed,
        array $lockedDev = [],
        array $declaredNamespaces = [],
        array $dumpedNamespaces = [],
        array $declaredFiles = [],
        bool $withVendor = true,
    ): string {
        $root = sys_get_temp_dir() . '/waaseyaa-vendor-fresh-' . bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir($root);
        $this->fixtures[] = $root;

        $fs->mkdir($root . '/x');
        foreach ($declaredFiles as $file) {
            $fs->dumpFile($root . '/' . $file, "<?php\n");
        }
        $fs->dumpFile($root . '/composer.json', json_encode([
            'name' => 'waaseyaa/framework',
            'autoload' => ['psr-4' => (object) [], 'files' => $declaredFiles],
            'autoload-dev' => ['psr-4' => (object) array_fill_keys($declaredNamespaces, 'x/')],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $fs->dumpFile($root . '/composer.lock', json_encode([
            'packages' => $locked,
            'packages-dev' => $lockedDev,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        if (!$withVendor) {
            return $root;
        }

        $fs->mkdir($root . '/vendor/composer');
        // A fake autoloader: nothing is actually loadable from this vendor/.
        $fs->dumpFile($root . '/vendor/autoload.php', "<?php\nreturn null;\n");
        $fs->dumpFile($root . '/vendor/composer/installed.json', json_encode([
            'packages' => $installed,
            'dev' => true,
            'dev-package-names' => array_column($lockedDev, 'name'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $psr4 = [];
        foreach ($dumpedNamespaces as $namespace) {
            $psr4[$namespace] = [$root . '/x'];
        }
        $files = [];
        foreach ($declaredFiles as $file) {
            $files[md5('waaseyaa/framework:' . $file)] = $root . '/' . $file;
        }
        $this->writeAutoloadMaps($root, psr4: $psr4, files: $files);

        return $root;
    }

    /**
     * @param array<string, list<string>> $psr4
     * @param array<string, string> $classmap
     * @param array<string, string> $files
     * @param array<string, list<string>>|null $staticPsr4
     * @param array<string, string>|null $staticClassmap
     * @param array<string, string>|null $staticFiles
     */
    private function writeAutoloadMaps(
        string $root,
        array $psr4,
        array $classmap = [],
        array $files = [],
        ?array $staticPsr4 = null,
        ?array $staticClassmap = null,
        ?array $staticFiles = null,
    ): void {
        $staticPsr4 ??= $psr4;
        $staticClassmap ??= $classmap;
        $staticFiles ??= $files;
        $suffix = bin2hex(random_bytes(8));
        $class = 'ComposerStaticInitFixture' . $suffix;
        $fs = new Filesystem();
        $fs->dumpFile($root . '/vendor/composer/autoload_psr4.php', "<?php\nreturn " . var_export($psr4, true) . ";\n");
        $fs->dumpFile($root . '/vendor/composer/autoload_classmap.php', "<?php\nreturn " . var_export($classmap, true) . ";\n");
        $fs->dumpFile($root . '/vendor/composer/autoload_files.php', "<?php\nreturn " . var_export($files, true) . ";\n");
        $fs->dumpFile($root . '/vendor/composer/autoload_static.php', sprintf(
            "<?php\nnamespace Composer\\Autoload;\nclass %s\n{\n    public static \$files = %s;\n    public static \$prefixDirsPsr4 = %s;\n    public static \$classMap = %s;\n    public static function getInitializer(object \$loader): \\Closure { return static function (): void {}; }\n}\n",
            $class,
            var_export($staticFiles, true),
            var_export($staticPsr4, true),
            var_export($staticClassmap, true),
        ));
        $fs->dumpFile($root . '/vendor/composer/autoload_real.php', sprintf(
            "<?php\ncall_user_func(\\Composer\\Autoload\\%s::getInitializer(\$loader));\n\$filesToLoad = \\Composer\\Autoload\\%s::\$files;\n",
            $class,
            $class,
        ));
    }

    private function scratchDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        new Filesystem()->mkdir($path);
        $this->fixtures[] = $path;

        return $path;
    }

    private function seedDeliveryGate(string $root): void
    {
        $fs = new Filesystem();
        $fs->mkdir($root . '/bin/lib');
        $fs->mkdir($root . '/ops/observability');
        foreach ([
            'bin/check-delivery-agent-events',
            'bin/lib/delivery-agent-event-set.php',
            'bin/lib/vendor-freshness.php',
            'bin/git',
            'ops/observability/delivery-agent-event-v1.schema.json',
        ] as $path) {
            $fs->copy($this->root . '/' . $path, $root . '/' . $path);
        }
        chmod($root . '/bin/check-delivery-agent-events', 0o755);
        chmod($root . '/bin/git', 0o755);
    }

    private function seedPreflight(string $root): void
    {
        $fs = new Filesystem();
        $fs->mkdir($root . '/bin/lib');
        foreach (['bin/check-pr-preflight', 'bin/lib/vendor-freshness.php'] as $path) {
            $fs->copy($this->root . '/' . $path, $root . '/' . $path);
        }
        chmod($root . '/bin/check-pr-preflight', 0o755);
    }

    private function writeManifest(string $root): string
    {
        $manifest = $root . '/preflight-manifest.json';
        file_put_contents($manifest, json_encode([
            'schema_version' => 1,
            'gates' => [
                ['id' => 'synthetic-gate', 'run' => 'echo gate-ran', 'repair' => 'n/a', 'profile' => 'default', 'enforced_by' => 'workflow:ci.yml'],
            ],
        ], JSON_THROW_ON_ERROR));

        return $manifest;
    }

    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd, null, null, 120);
        $exit = $process->run();

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
}
