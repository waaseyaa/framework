<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Fixture proofs for the consumer CLI evidence of bin/native-host-evidence
 * (#2678, FW-2678-NATIVE-HOST-SKELETON-CLI-02): each lane's record binds the
 * originating checked-out candidate, the installed waaseyaa/* cohort by
 * content, the completed lifecycle, the exact CLI argv, its exit code and
 * catalogue, and the runner identity; the paired verifier accepts only one
 * passing Linux and one passing Windows record from one run with one subject
 * and an equivalent cohort.
 *
 * Git is a lexical model over a fixture checkout: `ls-tree` lists the fixture
 * files with their real blob names, so the content binding is exercised
 * without a repository.
 */
#[CoversNothing]
final class NativeHostConsumerEvidenceTest extends TestCase
{
    private const CANDIDATE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PR_HEAD_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OTHER_SHA = 'cccccccccccccccccccccccccccccccccccccccc';
    private const SCRATCH_SHA = 'dddddddddddddddddddddddddddddddddddddddd';
    private const SKELETON_TREE = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** A `list --raw` catalogue in the shape Symfony prints it. */
    private const CATALOGUE = "about                  Display information about the Waaseyaa application\n"
        . "help                   Display help for a command\n"
        . "list                   List commands\n"
        . "db:init                Initialize the database\n"
        . "install:init           Initialize a fresh installation\n"
        . "project:init           Initialize a fresh project\n"
        . "site:doctor            Verify the generated site contract\n"
        . "site:init              Generate the site contract\n";

    private static string $root;
    private ?string $scratch = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/native-host-consumer-evidence.php';
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            new Filesystem()->remove($this->scratch);
            $this->scratch = null;
        }
    }

    #[Test]
    public function the_tracked_consumer_contract_is_valid_and_pairs_the_two_consumer_lanes(): void
    {
        $contract = \nhc_load_contract(self::$root . '/tools/native-host-contract.json');
        $section = $contract['consumer_cli'];

        self::assertSame([], \nhc_validate_contract($contract));
        self::assertSame(['php', 'vendor/bin/waaseyaa', 'list', '--raw'], $section['argv']);
        self::assertSame(['list', 'db:init', 'site:init', 'site:doctor', 'install:init'], $section['required_commands']);
        self::assertSame(
            ['linux' => ['site-reference-consumer', 'harness-archive'], 'windows' => ['skeleton-create-project-windows', 'checkout']],
            array_map(static fn(array $lane): array => [$lane['job'], $lane['candidate_binding']], $section['lanes']),
        );
        self::assertSame(['app_env' => 'testing', 'source' => 'process'], $section['lanes']['linux']['boot_environment']);
        self::assertSame(['app_env' => 'local', 'source' => 'consumer-dotenv'], $section['lanes']['windows']['boot_environment']);
    }

    /**
     * The stable catalogue subset is source-backed: `list` is the console
     * application built-in, and every other entry is registered
     * unconditionally by a provider waaseyaa/cli discovers.
     */
    #[Test]
    public function every_required_catalogue_entry_is_registered_unconditionally_in_source(): void
    {
        $contract = \nhc_load_contract(self::$root . '/tools/native-host-contract.json');
        $cli = json_decode((string) file_get_contents(self::$root . '/packages/cli/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        $providers = $cli['extra']['waaseyaa']['providers'];
        $expected = [
            'db:init' => 'ConfigCacheDbAuditServiceProvider',
            'site:init' => 'SiteServiceProvider',
            'site:doctor' => 'SiteServiceProvider',
            'install:init' => 'MigrateServiceProvider',
        ];

        self::assertSame(['list', ...array_keys($expected)], array_merge(['list'], array_values(array_diff($contract['consumer_cli']['required_commands'], ['list']))));
        self::assertSame(['list', 'db:init', 'site:init', 'site:doctor', 'install:init'], $contract['consumer_cli']['required_commands']);
        foreach ($expected as $command => $provider) {
            $class = 'Waaseyaa\\CLI\\Provider\\' . $provider;
            $source = (string) file_get_contents(self::$root . "/packages/cli/src/Provider/{$provider}.php");

            self::assertContains($class, $providers, "{$command}: {$provider} must be a discovered waaseyaa/cli provider.");
            self::assertStringContainsString("name: '{$command}'", $source, "{$command} must be defined by {$provider}.");
            self::assertStringNotContainsString('RequiresOptionalPackagesInterface', $source, "{$provider} must not be gated on an optional package.");
            self::assertSame(1, preg_match('/public function consoleCommands\(\): iterable\s*\{(.*?)\n    \}/s', $source, $body), $provider);
            self::assertStringNotContainsString('if (', $body[1], "{$provider} must yield its commands unconditionally.");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function weakenedSections(): iterable
    {
        yield 'no section' => ['no-section', 'consumer_cli must be an object'];
        yield 'an empty argv' => ['empty-argv', 'consumer_cli.argv must be a non-empty list'];
        yield 'a Composer argv' => ['composer-argv', 'consumer_cli.argv[0] must be php'];
        yield 'the stop-parsing token' => ['stop-parsing', 'consumer_cli.argv[3] must be non-empty printable ASCII'];
        yield 'a double quote' => ['double-quote', 'consumer_cli.argv[3] must be non-empty printable ASCII'];
        yield 'no required commands' => ['no-required', 'required_commands must be a non-empty list'];
        yield 'a duplicated required command' => ['duplicate-required', 'required_commands contains a duplicate'];
        yield 'a traversing lifecycle artifact' => ['traversal', 'lifecycle_artifacts must be a non-empty list of relative paths'];
        yield 'a missing lane' => ['missing-lane', 'lanes must name exactly the contract hosts'];
        yield 'an unknown binding' => ['bad-binding', 'lanes.linux.candidate_binding must be one of'];
        yield 'no boot environment' => ['no-boot', 'lanes.windows.boot_environment must be {app_env, source}'];
    }

    #[Test]
    #[DataProvider('weakenedSections')]
    public function consumer_contract_validation_rejects_weakened_sections(string $mutation, string $expected): void
    {
        $contract = self::fixtureContract();
        match ($mutation) {
            'no-section' => $contract['consumer_cli'] = null,
            'empty-argv' => $contract['consumer_cli']['argv'] = [],
            'composer-argv' => $contract['consumer_cli']['argv'][0] = 'composer',
            'stop-parsing' => $contract['consumer_cli']['argv'][3] = '--%',
            'double-quote' => $contract['consumer_cli']['argv'][3] = '--raw"',
            'no-required' => $contract['consumer_cli']['required_commands'] = [],
            'duplicate-required' => $contract['consumer_cli']['required_commands'][] = 'list',
            'traversal' => $contract['consumer_cli']['lifecycle_artifacts'][] = '../outside',
            'missing-lane' => $contract['consumer_cli']['lanes'] = ['linux' => $contract['consumer_cli']['lanes']['linux']],
            'bad-binding' => $contract['consumer_cli']['lanes']['linux']['candidate_binding'] = 'scratch-commit',
            'no-boot' => $contract['consumer_cli']['lanes']['windows']['boot_environment'] = null,
        };

        self::assertStringContainsString($expected, implode("\n", \nhc_validate_contract($contract)));
    }

    #[Test]
    public function the_catalogue_is_the_first_word_of_each_listed_line(): void
    {
        self::assertSame(
            ['about', 'list', 'db:init', 'site:init'],
            \nhc_catalogue("\xEF\xBB\xBFabout    Display information\r\nlist  List commands\r\n\e[32mdb:init\e[39m  Initialize the database\n\n  indented continuation\nsite:init\n"),
        );
        self::assertSame([], \nhc_catalogue(''));
        self::assertSame(
            ['list', 'db:init'],
            \nhc_catalogue("\xFF\xFE" . (string) iconv('UTF-8', 'UTF-16LE', "list      List commands\r\ndb:init   Initialize the database\r\n")),
            'A Windows PowerShell 5.1 redirection (UTF-16LE with a byte-order mark) is decoded.',
        );
    }

    #[Test]
    public function a_checkout_lane_and_a_harness_archive_lane_bind_the_same_candidate_cohort(): void
    {
        $checkout = $this->collect('checkout');
        $archive = $this->collect('harness-archive');

        foreach (['checkout' => $checkout, 'harness-archive' => $archive] as $binding => $collected) {
            $evidence = $collected['evidence'];
            self::assertSame(\NHE_EXIT_PASS, $collected['exit'], $binding . ': ' . implode("\n", [...$evidence['violations'], ...$evidence['incomplete']]));
            self::assertSame('pass', $evidence['result']);
            self::assertSame(\NHC_EVIDENCE_SCHEMA, $evidence['schema']);
            self::assertSame(['revision' => self::CANDIDATE_SHA, 'binding' => $binding, 'skeleton_tree' => self::SKELETON_TREE], $evidence['candidate']);
            self::assertSame('waaseyaa/framework', $evidence['subject']['repository']);
            self::assertSame(self::PR_HEAD_SHA, $evidence['subject']['pull_request_head_sha']);
            self::assertSame(['waaseyaa/ai-development', 'waaseyaa/foundation', 'waaseyaa/framework'], array_column($evidence['cohort']['packages'], 'name'));
            self::assertSame(['packages/ai-development', 'packages/foundation', '.'], array_column($evidence['cohort']['packages'], 'candidate_path'));
            self::assertSame([0, 2, 6], array_column($evidence['cohort']['packages'], 'files'), 'A metapackage installs no files.');
            self::assertSame([[], [], ['.agents/README.md', '.mcp.json']], array_column($evidence['cohort']['packages'], 'withheld'), 'Only export-ignored candidate files are withheld.');
            self::assertSame([0, 0, 2], array_column($evidence['cohort']['packages'], 'export_ignored'));
            self::assertSame(PHP_OS_FAMILY === 'Windows' ? 'case-insensitive' : 'exact', $evidence['cohort']['path_comparison']);
            self::assertSame(['artifacts' => ['.waaseyaa/generated.json' => true, 'bin/maintenance/site-verify' => true], 'activated_generations' => 1], $evidence['lifecycle']);
            self::assertSame(['job' => self::fixtureContract()['consumer_cli']['lanes'][$evidence['host']]['job'], 'candidate_binding' => $binding], $evidence['lane']);
            self::assertSame("& 'php' 'vendor/bin/waaseyaa' 'list' '--raw'", $evidence['cli']['powershell']);
            self::assertSame(0, $evidence['cli']['exit_code']);
            self::assertSame([], $evidence['cli']['missing_commands']);
            self::assertSame('2.10.3', $evidence['runtime']['composer']);
            self::assertSame([['id' => 'lifecycle', 'outcome' => 'success', 'exit_code' => 0], ['id' => 'consumer-cli', 'outcome' => 'success', 'exit_code' => 0]], $evidence['steps']);
        }

        self::assertSame($checkout['evidence']['cohort'], $archive['evidence']['cohort'], 'The same candidate content is the same cohort, whatever the lane.');
        self::assertSame(['name' => 'waaseyaa/waaseyaa', 'pretty_version' => '1.0.0+no-version-set', 'reference' => null], $checkout['evidence']['root_package']);
        self::assertSame($checkout['evidence']['root_package'], $archive['evidence']['root_package'], 'Composer keeps no root reference through the update on either host.');
        self::assertSame(['relation' => \NHC_CHECKOUT_SKELETON_RELATION, 'revision' => null, 'tree' => null], $checkout['evidence']['project_source']);
        self::assertSame(
            ['relation' => \NHC_SCRATCH_RELATION, 'revision' => self::SCRATCH_SHA, 'tree' => self::SKELETON_TREE],
            $archive['evidence']['project_source'],
            'The Linux project source is the scratch commit, recorded as such and bound to the candidate by its tree.',
        );
        self::assertSame(['app_env' => 'local', 'source' => 'consumer-dotenv', 'app_secret' => 'present'], $checkout['evidence']['boot_environment']);
        self::assertSame(['app_env' => 'testing', 'source' => 'process', 'app_secret' => 'present'], $archive['evidence']['boot_environment']);
        self::assertStringNotContainsString('base64:', json_encode([$checkout, $archive], JSON_THROW_ON_ERROR), 'No application secret value is recorded.');
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function collectFailures(): iterable
    {
        yield 'the harness archived another revision' => ['harness-archive', 'other-archive', 'fail','the harness archived ' . self::OTHER_SHA . ', not the checked-out candidate'];
        yield 'the harness handed over no revision' => ['harness-archive', 'no-archive', 'incomplete','the harness did not hand over the candidate revision'];
        yield 'an installed file differs' => ['checkout', 'changed-file', 'fail','waaseyaa/foundation: 1 installed file(s) differs from the candidate: src/Kernel.php'];
        yield 'an uninstalled package manifest' => ['checkout', 'no-manifest', 'fail', 'waaseyaa/foundation: its composer.json is not the installed candidate manifest'];
        yield 'an installed file is not a candidate file' => ['checkout', 'extra-file', 'fail','waaseyaa/foundation: 1 installed file(s) is not a candidate file: src/Injected.php'];
        yield 'a non-candidate waaseyaa package' => ['checkout', 'foreign-package', 'fail','the consumer installed waaseyaa/ghost, which is not a candidate package'];
        yield 'a dirty checkout manifest' => ['checkout', 'dirty-manifest', 'fail',"the checkout's packages/foundation/composer.json is not the candidate revision's"];
        yield 'no installed metadata' => ['checkout', 'no-installed-json', 'incomplete','no readable vendor/composer/installed.json'];
        yield 'an unreadable consumer database' => ['checkout', 'no-database', 'fail', 'the consumer database has no activated configuration generation, so install:init did not complete'];
        yield 'a database without an activated generation' => ['checkout', 'empty-activation', 'fail', 'the consumer database has no activated configuration generation'];
        yield 'a missing site:init publication' => ['checkout', 'no-generated', 'fail', 'the consumer lacks .waaseyaa/generated.json, so its lifecycle did not complete'];
        yield 'a candidate file neither installed nor export-ignored' => ['checkout', 'missing-file', 'fail', 'waaseyaa/framework: 1 candidate file(s) is missing and not export-ignored: README.md'];
        yield 'another collecting job' => ['checkout', 'other-job', 'fail', 'consumer record was collected by job ci-lint, not '];
        yield 'no collecting job' => ['checkout', 'no-job', 'incomplete', 'GITHUB_JOB is not set'];
        yield 'unreadable export-ignore attributes' => ['checkout', 'no-attributes', 'incomplete', 'the export-ignore attributes of ' . self::CANDIDATE_SHA . ' could not be read'];
        yield 'a .env.local that overrides APP_ENV' => ['checkout', 'env-local', 'fail', 'the consumer booted with APP_ENV "production" from "consumer-dotenv"'];
        yield 'a skipped lifecycle' => ['checkout', 'lifecycle-skipped', 'fail','step lifecycle finished skipped'];
        yield 'a skipped CLI' => ['checkout', 'cli-skipped', 'fail','step consumer-cli finished skipped'];
        yield 'a non-zero CLI exit' => ['checkout', 'cli-failed', 'fail','step consumer-cli exited 1'];
        yield 'an uncaptured CLI output' => ['checkout', 'no-stdout', 'fail','the consumer CLI output was not captured'];
        yield 'a missing catalogue entry' => ['checkout', 'no-install-init', 'fail','the consumer catalogue does not list install:init'];
        yield 'another scratch tree' => ['harness-archive', 'other-scratch-tree', 'fail','is not the candidate skeleton tree ' . self::SKELETON_TREE];
        yield 'a scratch commit claimed to be the candidate' => ['harness-archive', 'scratch-is-candidate', 'fail', 'the scratch project commit ' . self::CANDIDATE_SHA . ' claims to be the candidate revision'];
        yield 'no scratch handoff' => ['harness-archive', 'no-scratch', 'incomplete','the harness did not hand over its scratch project commit'];
        yield 'a process APP_ENV over the consumer .env' => ['checkout', 'process-app-env', 'fail','the consumer booted with APP_ENV "production" from "process"'];
        yield 'no application secret' => ['harness-archive', 'no-secret', 'fail','and no application secret, not APP_ENV testing from process'];
        yield 'no consumer handoff' => ['checkout', 'no-consumer', 'incomplete','the lane did not hand over its consumer root'];
        yield 'no repository' => ['checkout', 'no-repository', 'incomplete','GITHUB_REPOSITORY is not set'];
        yield 'an unlistable candidate tree' => ['checkout', 'no-tree', 'incomplete','the candidate tree of ' . self::CANDIDATE_SHA . ' could not be listed'];
    }

    #[Test]
    #[DataProvider('collectFailures')]
    public function collect_fails_closed(string $binding, string $mutation, string $result, string $expected): void
    {
        $collected = $this->collect($binding, $mutation);
        $evidence = $collected['evidence'];

        self::assertSame($result === 'fail' ? \NHE_EXIT_VIOLATION : \NHE_EXIT_INCOMPLETE, $collected['exit'], implode("\n", [...$evidence['violations'], ...$evidence['incomplete']]));
        self::assertSame($result, $evidence['result']);
        self::assertStringContainsString($expected, implode("\n", [...$evidence['violations'], ...$evidence['incomplete']]));
    }

    /**
     * A Windows checkout (core.autocrlf) of an LF blob is the same content; on
     * a Linux host, which has no such checkout, CRLF is a different file.
     */
    #[Test]
    public function only_a_windows_host_normalizes_a_crlf_checkout_of_an_lf_blob(): void
    {
        $installed = $this->scratch() . '/foundation';
        self::write($installed . '/composer.json', "{}\n");
        self::write($installed . '/src/Kernel.php', "<?php\r\n\r\nfinal class Kernel {}\r\n");
        $tree = [
            'packages/foundation/composer.json' => \nhc_blob_sha("{}\n"),
            'packages/foundation/src/Kernel.php' => \nhc_blob_sha("<?php\n\nfinal class Kernel {}\n"),
        ];

        $windows = \nhc_compare_package($installed, 'packages/foundation/', $tree, [], true);
        $linux = \nhc_compare_package($installed, 'packages/foundation/', $tree, [], false);

        self::assertSame([], $windows['problems']);
        self::assertSame(1, $windows['eol_normalized']);
        self::assertSame(\nhc_package_digest(['composer.json' => $tree['packages/foundation/composer.json'], 'src/Kernel.php' => $tree['packages/foundation/src/Kernel.php']]), $windows['digest']);
        self::assertSame(['1 installed file(s) differs from the candidate: src/Kernel.php'], $linux['problems']);
        self::assertSame(0, $linux['eol_normalized']);
    }

    /**
     * `git archive` (Linux) applies the root .gitattributes to package paths;
     * Composer's Windows path mirror reads only the mirrored directory's own.
     * An export-ignored package file may therefore be installed on one host
     * and not the other: it is left out of the digest on both, so the lanes
     * still agree. A withheld file that is not export-ignored fails.
     */
    #[Test]
    public function export_ignored_files_leave_the_digest_and_nothing_else_may_be_withheld(): void
    {
        $blob = static fn(string $bytes): string => \nhc_blob_sha($bytes);
        $tree = [
            'packages/foundation/composer.json' => $blob("{}\n"),
            'packages/foundation/src/Kernel.php' => $blob("<?php\n"),
            'packages/foundation/storage/seed.sqlite' => $blob('seed'),
        ];
        $ignored = ['packages/foundation/storage/seed.sqlite' => true];
        $linux = $this->scratch() . '/linux';
        $windows = $this->scratch() . '/windows';
        foreach ([$linux, $windows] as $directory) {
            self::write($directory . '/composer.json', "{}\n");
            self::write($directory . '/src/Kernel.php', "<?php\n");
        }
        self::write($windows . '/storage/seed.sqlite', 'seed');

        $withheld = \nhc_compare_package($linux, 'packages/foundation/', $tree, $ignored, false);
        $installed = \nhc_compare_package($windows, 'packages/foundation/', $tree, $ignored, true);
        $unignored = \nhc_compare_package($linux, 'packages/foundation/', $tree, [], false);

        self::assertSame([], $withheld['problems']);
        self::assertSame([], $installed['problems']);
        self::assertSame(['storage/seed.sqlite'], $withheld['withheld']);
        self::assertSame([], $installed['withheld']);
        self::assertSame(1, $withheld['export_ignored']);
        self::assertSame($withheld['digest'], $installed['digest'], 'Both hosts agree whether or not the export-ignored file was installed.');
        self::assertSame(['1 candidate file(s) is missing and not export-ignored: storage/seed.sqlite'], $unignored['problems']);
    }

    /**
     * The export-ignore set is what `git archive` leaves out: a file whose own
     * attribute is set, or any file under a directory whose attribute is set
     * (which Git reports only for `<dir>/`). Queries are batched, and a Git
     * failure is unknown, never an empty set.
     */
    #[Test]
    public function the_export_ignore_set_follows_directory_rules_and_fails_closed(): void
    {
        $tree = ['.agents/skills/README.md' => 'a', '.mcp.json' => 'b', 'README.md' => 'c', 'packages/x/.agents/y.md' => 'd'];
        for ($index = 0; $index < 600; $index++) {
            $tree[sprintf('packages/bulk/src/File%03d.php', $index)] = 'e';
        }
        $calls = 0;
        $git = static function (array $arguments) use (&$calls): ?string {
            $calls++;
            self::assertSame(['check-attr', '-z', '--source', self::CANDIDATE_SHA, 'export-ignore', '--'], array_slice($arguments, 0, 6));
            $output = '';
            foreach (array_slice($arguments, 6) as $path) {
                $output .= $path . "\0export-ignore\0" . (in_array($path, ['.agents/', '.mcp.json'], true) ? 'set' : 'unspecified') . "\0";
            }

            return $output;
        };

        self::assertSame(['.agents/skills/README.md' => true, '.mcp.json' => true], \nhc_export_ignored($tree, $git, self::CANDIDATE_SHA));
        self::assertGreaterThan(1, $calls, 'Large trees are queried in batches.');
        self::assertNull(\nhc_export_ignored($tree, static fn(array $arguments): ?string => null, self::CANDIDATE_SHA));
    }

    /**
     * Two candidate directories that differ only in case share one directory
     * on a case-insensitive filesystem; the comparison maps each installed
     * file back to its candidate path and digests the candidate path.
     */
    #[Test]
    public function a_case_insensitive_host_matches_candidate_paths_without_case(): void
    {
        $installed = $this->scratch() . '/ssr';
        self::write($installed . '/composer.json', "{}\n");
        self::write($installed . '/tests/Fixtures/Annotated.php', "<?php\n");
        self::write($installed . '/tests/Fixtures/greeting.twig', "Hi\n");
        $tree = [
            'packages/ssr/composer.json' => \nhc_blob_sha("{}\n"),
            'packages/ssr/tests/Fixtures/Annotated.php' => \nhc_blob_sha("<?php\n"),
            'packages/ssr/tests/fixtures/greeting.twig' => \nhc_blob_sha("Hi\n"),
        ];

        $insensitive = \nhc_compare_package($installed, 'packages/ssr/', $tree, [], true);
        $exact = \nhc_compare_package($installed, 'packages/ssr/', $tree, [], false);

        self::assertSame([], $insensitive['problems']);
        self::assertSame(3, $insensitive['files']);
        self::assertSame(\nhc_package_digest(array_combine(
            ['composer.json', 'tests/Fixtures/Annotated.php', 'tests/fixtures/greeting.twig'],
            array_values($tree),
        )), $insensitive['digest'], 'The digest uses the candidate paths, so it is the same on every host.');
        self::assertSame(['1 installed file(s) is not a candidate file: tests/Fixtures/greeting.twig', '1 candidate file(s) is missing and not export-ignored: tests/fixtures/greeting.twig'], $exact['problems']);
        self::assertSame(['tests/fixtures/greeting.twig'], $exact['withheld']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function consumerSets(): iterable
    {
        yield 'one passing record per lane' => ['none', ''];
        yield 'a missing lane' => ['missing-windows', 'the windows consumer evidence is missing'];
        yield 'a duplicated lane' => ['duplicate-windows', 'the windows consumer evidence is duplicated'];
        yield 'a record under another lane' => ['duplicate-windows', 'the native-host-consumer-evidence-linux artifact carries a record for "windows"'];
        yield 'an extra artifact' => ['extra-artifact', 'unexpected consumer evidence artifact native-host-consumer-evidence-macos'];
        yield 'a record that is not JSON' => ['unreadable', 'the linux consumer evidence record is missing or not a JSON object'];
        yield 'a malformed cohort' => ['malformed-cohort', 'the windows consumer record fails the cohort check'];
        yield 'a failing record' => ['windows-fail', 'the windows consumer record fails the result pass check'];
        yield 'another run' => ['other-run', 'the windows consumer record fails the run id check'];
        yield 'a later attempt than the verifier' => ['later-attempt', 'fails the run attempt check'];
        yield 'another checkout' => ['other-head', 'the linux consumer record fails the checked-out HEAD check'];
        yield 'another candidate' => ['other-candidate', 'the linux consumer record fails the candidate revision check'];
        yield 'different pull-request heads' => ['other-pr-head', 'bind different subjects'];
        yield 'different repositories' => ['other-repository', 'bind different subjects'];
        yield 'a different cohort digest' => ['other-cohort', 'bind different installed package cohorts'];
        yield 'a different package version' => ['other-version', 'bind different installed package cohorts'];
        yield 'a non-zero CLI exit' => ['cli-exit', 'the windows consumer record fails the CLI exit code check'];
        yield 'a missing catalogue entry' => ['no-site-doctor', 'the linux consumer record fails the required catalogue entries check'];
        yield 'another argv' => ['other-argv', 'the windows consumer record fails the CLI argv check'];
        yield 'a skipped lifecycle' => ['lifecycle-skipped', 'the linux consumer record fails the lifecycle step check'];
        yield 'a skipped CLI' => ['cli-skipped', 'the windows consumer record fails the consumer-cli step check'];
        yield 'an incomplete lifecycle' => ['no-database', 'the windows consumer record fails the lifecycle artifacts check'];
        yield 'no Composer version' => ['no-composer', 'the linux consumer record fails the runtime check'];
        yield 'no hosted shell version' => ['no-shell-version', 'the windows consumer record fails the hosted shell check'];
        yield 'another runner' => ['other-runner', 'the windows consumer record fails the runner check'];
        yield 'another boot environment' => ['other-boot', 'the linux consumer record fails the boot environment check'];
        yield 'another lane job' => ['other-lane', 'the linux consumer record fails the lane check'];
        yield 'a Linux scratch commit claimed equal to the candidate' => ['scratch-equals-candidate', 'the linux consumer record fails the scratch project commit check'];
        yield 'a Windows project source that claims a scratch commit' => ['windows-scratch', 'the windows consumer record fails the project source check'];
        yield 'a Linux scratch tree that is not the skeleton' => ['scratch-tree', 'the linux consumer record fails the scratch project commit check'];
        yield 'no activated generation' => ['no-activation', 'the windows consumer record fails the lifecycle artifacts check'];
        yield 'another skeleton tree than the verifier resolves' => ['other-skeleton', 'the linux consumer record fails the candidate skeleton tree check'];
    }

    #[Test]
    #[DataProvider('consumerSets')]
    public function verify_set_accepts_one_passing_record_per_lane_bound_to_one_subject_and_cohort(string $mutation, string $expected): void
    {
        $contract = self::fixtureContract();
        $directory = $this->scratch();
        $records = ['linux' => self::passingRecord('linux', $contract), 'windows' => self::passingRecord('windows', $contract)];
        match ($mutation) {
            'none', 'missing-windows', 'duplicate-windows', 'extra-artifact', 'unreadable' => null,
            'malformed-cohort' => $records['windows']['cohort'] = 'not an object',
            'windows-fail' => $records['windows']['result'] = 'fail',
            'other-run' => $records['windows']['subject']['run_id'] = 41,
            'later-attempt' => $records['windows']['subject']['run_attempt'] = 3,
            'other-head' => $records['linux']['subject']['checked_out_head'] = self::OTHER_SHA,
            'other-candidate' => $records['linux']['candidate']['revision'] = self::OTHER_SHA,
            'other-pr-head' => $records['windows']['subject']['pull_request_head_sha'] = self::OTHER_SHA,
            'other-repository' => $records['windows']['subject']['repository'] = 'someone/fork',
            'other-cohort' => $records['windows']['cohort']['digest'] = str_repeat('f', 64),
            'other-version' => $records['windows']['cohort']['packages'][0]['version'] = 'dev-other',
            'cli-exit' => $records['windows']['cli']['exit_code'] = 1,
            'no-site-doctor' => $records['linux']['cli']['catalogue'] = ['list', 'db:init', 'site:init', 'install:init'],
            'other-argv' => $records['windows']['cli']['argv'] = ['php', 'vendor/bin/waaseyaa', 'list'],
            'lifecycle-skipped' => $records['linux']['steps'][0]['outcome'] = 'skipped',
            'cli-skipped' => $records['windows']['steps'][1] = ['id' => 'consumer-cli', 'outcome' => 'skipped', 'exit_code' => null],
            'no-database' => $records['windows']['lifecycle']['artifacts']['storage/waaseyaa.sqlite'] = false,
            'no-composer' => $records['linux']['runtime']['composer'] = null,
            'no-shell-version' => $records['windows']['hosted_shell']['version'] = null,
            'other-runner' => $records['windows']['runner']['label'] = 'windows-2022',
            'other-boot' => $records['linux']['boot_environment']['app_env'] = 'production',
            'other-lane' => $records['linux']['lane']['job'] = 'skeleton-create-project',
            'scratch-equals-candidate' => $records['linux']['project_source']['revision'] = self::CANDIDATE_SHA,
            'scratch-tree' => $records['linux']['project_source']['tree'] = self::OTHER_SHA,
            'no-activation' => $records['windows']['lifecycle']['activated_generations'] = 0,
            'other-skeleton' => $records['linux']['candidate']['skeleton_tree'] = $records['linux']['project_source']['tree'] = self::OTHER_SHA,
            'windows-scratch' => $records['windows']['project_source'] = $records['linux']['project_source'],
        };
        foreach ($records as $host => $record) {
            if ($mutation === 'missing-windows' && $host === 'windows') {
                continue;
            }
            $artifact = $directory . '/' . \NHC_ARTIFACT_PREFIX . $host;
            mkdir($artifact);
            file_put_contents(
                $artifact . '/evidence.json',
                match (true) {
                    $mutation === 'unreadable' && $host === 'linux' => '{not json',
                    $mutation === 'duplicate-windows' && $host === 'linux' => json_encode($records['windows'], JSON_THROW_ON_ERROR),
                    default => json_encode($record, JSON_THROW_ON_ERROR),
                },
            );
        }
        if ($mutation === 'extra-artifact') {
            mkdir($directory . '/' . \NHC_ARTIFACT_PREFIX . 'macos');
        }

        $verified = \nhc_verify_set($directory, ['linux', 'windows'], $contract, ['GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '2'], self::CANDIDATE_SHA, self::verifierGit());

        if ($expected === '') {
            self::assertSame(\NHE_EXIT_PASS, $verified['exit'], implode("\n", $verified['violations']));
            self::assertSame(['linux' => 'pass', 'windows' => 'pass'], $verified['hosts']);
        } else {
            self::assertSame(\NHE_EXIT_VIOLATION, $verified['exit']);
            self::assertStringContainsString($expected, implode("\n", $verified['violations']));
        }
    }

    #[Test]
    public function verify_set_rejects_a_host_list_that_is_not_the_consumer_lanes(): void
    {
        $verified = \nhc_verify_set($this->scratch(), ['linux'], self::fixtureContract(), ['GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '1'], self::CANDIDATE_SHA, self::verifierGit());

        self::assertSame(\NHE_EXIT_VIOLATION, $verified['exit']);
        self::assertStringContainsString('the verified hosts must be exactly the consumer lanes: linux, windows', implode("\n", $verified['violations']));
    }

    #[Test]
    public function the_cli_reports_unusable_consumer_arguments_as_a_harness_error(): void
    {
        foreach ([['consumer-collect', '--host=solaris', '--out=x.json'], ['consumer-collect', '--host=linux'], ['consumer-verify-set', '--dir=x']] as $arguments) {
            $process = new Process([PHP_BINARY, self::$root . '/bin/native-host-evidence', ...$arguments], self::$root);
            $process->run();

            self::assertSame(\NHE_EXIT_HARNESS, $process->getExitCode(), implode(' ', $arguments) . ': ' . $process->getErrorOutput());
        }
    }

    /** The verifier's Git: it resolves the candidate skeleton tree itself. */
    private static function verifierGit(): \Closure
    {
        return static fn(array $arguments): ?string => $arguments === ['rev-parse', '--verify', self::CANDIDATE_SHA . ':skeleton'] ? self::SKELETON_TREE . "\n" : null;
    }

    /**
     * Collect one lane's evidence from a fixture checkout and consumer, with
     * the current host's lane bound as $binding.
     *
     * @return array{evidence: array<string, mixed>, exit: int}
     */
    private function collect(string $binding, string $mutation = 'none'): array
    {
        $host = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
        $contract = self::fixtureContract();
        $contract['consumer_cli']['lanes'][$host]['candidate_binding'] = $binding;
        $contract['consumer_cli']['lanes'][$host]['boot_environment'] = $binding === 'harness-archive'
            ? ['app_env' => 'testing', 'source' => 'process']
            : ['app_env' => 'local', 'source' => 'consumer-dotenv'];

        $scratch = $this->scratch() . '/' . $binding . '-' . $mutation;
        $checkout = $scratch . '/checkout';
        $consumer = $scratch . '/consumer';
        $candidate = [
            'composer.json' => "{\"name\": \"waaseyaa/framework\"}\n",
            'README.md' => "# Waaseyaa\n",
            '.mcp.json' => "{}\n",
            '.agents/README.md' => "# Agents\n",
            'packages/ai-development/composer.json' => "{\"name\": \"waaseyaa/ai-development\", \"type\": \"metapackage\"}\n",
            'packages/foundation/composer.json' => "{\"name\": \"waaseyaa/foundation\"}\n",
            'packages/foundation/src/Kernel.php' => "<?php\n\nfinal class Kernel {}\n",
            'skeleton/composer.json' => "{\"name\": \"waaseyaa/waaseyaa\"}\n",
        ];
        $tree = array_map(\nhc_blob_sha(...), $candidate);
        // The candidate export-ignores .mcp.json and the .agents/ directory,
        // so neither is mirrored into the framework package.
        $exportIgnored = ['.mcp.json', '.agents/'];
        foreach ($candidate as $path => $bytes) {
            self::write("{$checkout}/{$path}", $bytes);
            if ($path !== '.mcp.json' && !str_starts_with($path, '.agents/')) {
                self::write("{$consumer}/vendor/waaseyaa/framework/{$path}", $bytes);
            }
            if (str_starts_with($path, 'packages/foundation/')) {
                self::write("{$consumer}/vendor/waaseyaa/foundation/" . substr($path, strlen('packages/foundation/')), $bytes);
            }
        }
        $installed = ['packages' => [
            ['name' => 'symfony/console', 'version' => 'v8.0.0', 'type' => 'library', 'install-path' => '../symfony/console'],
            ['name' => 'waaseyaa/ai-development', 'version' => 'dev-main', 'type' => 'metapackage', 'dist' => ['type' => 'path', 'reference' => '098348f'], 'install-path' => null],
            ['name' => 'waaseyaa/foundation', 'version' => 'dev-main', 'type' => 'library', 'dist' => ['type' => 'path', 'reference' => '94e3f4b'], 'install-path' => '../waaseyaa/foundation'],
            ['name' => 'waaseyaa/framework', 'version' => 'dev-main', 'type' => 'project', 'dist' => ['type' => 'path', 'reference' => '9ec6754'], 'install-path' => '../waaseyaa/framework'],
        ]];
        foreach (['.waaseyaa/generated.json' => '{}', 'bin/maintenance/site-verify' => '<?php'] as $path => $bytes) {
            self::write("{$consumer}/{$path}", $bytes);
        }
        $activations = 1;
        self::write("{$consumer}/.env", "APP_ENV=local\nAPP_DEBUG=true\nWAASEYAA_APP_SECRET=base64:c2VjcmV0\n");
        self::write("{$scratch}/list-raw.stdout", self::CATALOGUE);
        // A Windows host resolves Composer's batch shim from PATH.
        self::write("{$scratch}/path/composer.bat", '');
        $scratchTree = self::SKELETON_TREE;
        $git = static function (array $arguments) use (&$tree, &$scratchTree, &$exportIgnored): ?string {
            if (($arguments[0] ?? null) === 'check-attr') {
                if ($exportIgnored === null) {
                    return null;
                }
                $output = '';
                foreach (array_slice($arguments, 6) as $path) {
                    $output .= $path . "\0export-ignore\0" . (in_array($path, $exportIgnored, true) ? 'set' : 'unspecified') . "\0";
                }

                return $output;
            }
            if ($arguments === ['ls-tree', '-r', '-z', '--full-tree', self::CANDIDATE_SHA]) {
                $listing = '';
                foreach ($tree as $path => $blob) {
                    $listing .= "100644 blob {$blob}\t{$path}\0";
                }

                return $listing === '' ? null : $listing;
            }
            if ($arguments === ['rev-parse', '--verify', self::CANDIDATE_SHA . ':skeleton']) {
                return self::SKELETON_TREE . "\n";
            }
            if (($arguments[0] ?? null) === '-C' && $arguments[2] === 'rev-parse' && str_ends_with($arguments[4], '^{tree}')) {
                return $scratchTree . "\n";
            }

            return null;
        };

        $env = ['PATH' => "{$scratch}/path"] + self::hostEnvironment($host) + [
            'GITHUB_REPOSITORY' => 'waaseyaa/framework',
            'GITHUB_JOB' => $contract['consumer_cli']['lanes'][$host]['job'],
            'WAASEYAA_CONSUMER_ROOT' => $consumer,
            'NATIVE_HOST_CLI_STDOUT' => "{$scratch}/list-raw.stdout",
            'NATIVE_HOST_STEP_RESULTS' => "lifecycle success 0\nconsumer-cli success 0\n",
        ];
        if ($binding === 'harness-archive') {
            $env += [
                'WAASEYAA_CONSUMER_CANDIDATE_REVISION' => self::CANDIDATE_SHA,
                'WAASEYAA_CONSUMER_PROJECT_SOURCE' => "{$scratch}/project-source",
                'WAASEYAA_CONSUMER_PROJECT_REVISION' => self::SCRATCH_SHA,
                'APP_ENV' => 'testing',
                'WAASEYAA_APP_SECRET' => 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=',
            ];
        }

        match ($mutation) {
            'none' => null,
            'other-archive' => $env['WAASEYAA_CONSUMER_CANDIDATE_REVISION'] = self::OTHER_SHA,
            'no-archive' => $env['WAASEYAA_CONSUMER_CANDIDATE_REVISION'] = '',
            'changed-file' => self::write("{$consumer}/vendor/waaseyaa/foundation/src/Kernel.php", "<?php\n\nfinal class Kernel { public const TAMPERED = true; }\n"),
            'missing-file' => unlink("{$consumer}/vendor/waaseyaa/framework/README.md"),
            'no-manifest' => unlink("{$consumer}/vendor/waaseyaa/foundation/composer.json"),
            'extra-file' => self::write("{$consumer}/vendor/waaseyaa/foundation/src/Injected.php", "<?php\n"),
            'foreign-package' => $installed['packages'][] = ['name' => 'waaseyaa/ghost', 'version' => 'v1.0.0', 'install-path' => '../waaseyaa/ghost'],
            'dirty-manifest' => self::write("{$checkout}/packages/foundation/composer.json", "{\"name\": \"waaseyaa/foundation\", \"dirty\": true}\n"),
            'no-installed-json' => $installed = null,
            'no-database' => $activations = null,
            'empty-activation' => $activations = 0,
            'no-generated' => unlink("{$consumer}/.waaseyaa/generated.json"),
            'other-job' => $env['GITHUB_JOB'] = 'ci-lint',
            'no-job' => $env['GITHUB_JOB'] = '',
            'no-attributes' => $exportIgnored = null,
            'env-local' => self::write("{$consumer}/.env.local", "APP_ENV=production\n"),
            'lifecycle-skipped' => $env['NATIVE_HOST_STEP_RESULTS'] = "lifecycle skipped 0\nconsumer-cli success 0\n",
            'cli-skipped' => $env['NATIVE_HOST_STEP_RESULTS'] = "lifecycle success 0\nconsumer-cli skipped 0\n",
            'cli-failed' => $env['NATIVE_HOST_STEP_RESULTS'] = "lifecycle success 0\nconsumer-cli failure 1\n",
            'no-stdout' => unlink("{$scratch}/list-raw.stdout"),
            'no-install-init' => self::write("{$scratch}/list-raw.stdout", str_replace("install:init           Initialize a fresh installation\n", '', self::CATALOGUE)),
            'other-scratch-tree' => $scratchTree = self::OTHER_SHA,
            'scratch-is-candidate' => $env['WAASEYAA_CONSUMER_PROJECT_REVISION'] = self::CANDIDATE_SHA,
            'no-scratch' => $env['WAASEYAA_CONSUMER_PROJECT_REVISION'] = '',
            'process-app-env' => $env['APP_ENV'] = 'production',
            'no-secret' => $env['WAASEYAA_APP_SECRET'] = '',
            'no-consumer' => $env['WAASEYAA_CONSUMER_ROOT'] = '',
            'no-repository' => $env['GITHUB_REPOSITORY'] = '',
            'no-tree' => $tree = [],
        };
        if ($installed !== null) {
            self::write("{$consumer}/vendor/composer/installed.json", json_encode($installed, JSON_THROW_ON_ERROR));
        }
        self::write(
            "{$consumer}/vendor/composer/installed.php",
            '<?php return ' . var_export(['root' => ['name' => 'waaseyaa/waaseyaa', 'pretty_version' => '1.0.0+no-version-set', 'reference' => null]], true) . ';',
        );

        return \nhc_collect($contract, $host, $checkout, $env, self::childModel($checkout, $consumer, $activations), $git, self::CANDIDATE_SHA);
    }

    /**
     * A passing record for $host in the shape nhc_collect() writes.
     *
     * @param array<string, mixed> $contract
     *
     * @return array<string, mixed>
     */
    private static function passingRecord(string $host, array $contract): array
    {
        $lane = $contract['consumer_cli']['lanes'][$host];
        $definition = $contract['hosts'][$host];
        $record = [
            'schema' => \NHC_EVIDENCE_SCHEMA,
            'schema_version' => \NHE_SCHEMA_VERSION,
            'result' => 'pass',
            'host' => $host,
            'lane' => ['job' => $lane['job'], 'candidate_binding' => $lane['candidate_binding']],
            'contract' => ['path' => 'tools/native-host-contract.json', 'section' => 'consumer_cli', 'sha256' => \nhe_contract_digest($contract)],
            'subject' => [
                'profile' => 'merge-ref-sha',
                'checked_out_head' => self::CANDIDATE_SHA,
                'github_sha' => self::CANDIDATE_SHA,
                'dispatch_sha' => null,
                'pull_request_head_sha' => self::PR_HEAD_SHA,
                'event_name' => 'pull_request',
                'run_id' => 42,
                'run_attempt' => 1,
                'repository' => 'waaseyaa/framework',
            ],
            'candidate' => ['revision' => self::CANDIDATE_SHA, 'binding' => $lane['candidate_binding'], 'skeleton_tree' => self::SKELETON_TREE],
            'root_package' => ['name' => 'waaseyaa/waaseyaa', 'pretty_version' => '1.0.0+no-version-set', 'reference' => null],
            'project_source' => ['relation' => \NHC_CHECKOUT_SKELETON_RELATION, 'revision' => null, 'tree' => null],
            'cohort' => [
                'digest' => str_repeat('1', 64),
                'package_count' => 1,
                'packages' => [['name' => 'waaseyaa/framework', 'version' => 'dev-main', 'type' => 'project', 'dist_type' => 'path', 'dist_reference' => '9ec6754', 'candidate_path' => '.', 'files' => 3, 'content_digest' => str_repeat('2', 64), 'eol_normalized' => 0]],
            ],
            'lifecycle' => ['artifacts' => array_fill_keys($contract['consumer_cli']['lifecycle_artifacts'], true), 'activated_generations' => 1],
            'cli' => [
                'argv' => $contract['consumer_cli']['argv'],
                'powershell' => \nhe_render_powershell($contract['consumer_cli']['argv']),
                'outcome' => 'success',
                'exit_code' => 0,
                'required_commands' => $contract['consumer_cli']['required_commands'],
                'missing_commands' => [],
                'catalogue' => \nhc_catalogue(self::CATALOGUE),
            ],
            'boot_environment' => $lane['boot_environment'] + ['app_secret' => 'present'],
            'runner' => [
                'label' => $definition['runner'],
                'runner_os' => $definition['runner_os'],
                'runner_arch' => 'X64',
                'runner_environment' => 'github-hosted',
                'image_os' => $host === 'windows' ? 'win25' : 'ubuntu24',
                'image_version' => '20260922.1',
                'os_family' => $definition['php_os_family'],
                'os' => $host === 'windows' ? 'Windows NT 10.0 build 26100' : 'Linux 6.8.0 #1 SMP',
                'architecture' => 'x86_64',
            ],
            'hosted_shell' => ['name' => 'pwsh', 'version' => '7.6.5', 'role' => 'hosted-harness-only'],
            'runtime' => ['php' => '8.5.10', 'composer' => '2.10.3', 'sqlite' => '3.45.1', 'node' => null, 'node_required' => false],
            'steps' => [['id' => 'lifecycle', 'outcome' => 'success', 'exit_code' => 0], ['id' => 'consumer-cli', 'outcome' => 'success', 'exit_code' => 0]],
            'violations' => [],
            'incomplete' => [],
        ];
        if ($lane['candidate_binding'] === 'harness-archive') {
            $record['project_source'] = ['relation' => \NHC_SCRATCH_RELATION, 'revision' => self::SCRATCH_SHA, 'tree' => self::SKELETON_TREE];
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private static function fixtureContract(): array
    {
        $architecture = \nhe_normalize_architecture(php_uname('m'));

        return [
            'schema' => \NHE_CONTRACT_SCHEMA,
            'schema_version' => \NHE_SCHEMA_VERSION,
            'harness_shell' => 'pwsh',
            'php_extensions' => ['sqlite3'],
            'hosts' => [
                'linux' => ['runner' => 'ubuntu-24.04', 'runner_os' => 'Linux', 'php_os_family' => PHP_OS_FAMILY === 'Windows' ? 'Linux' : PHP_OS_FAMILY, 'architecture' => $architecture, 'replay_shell' => 'posix_sh'],
                'windows' => ['runner' => 'windows-2025', 'runner_os' => 'Windows', 'php_os_family' => 'Windows', 'architecture' => $architecture, 'replay_shell' => 'powershell'],
            ],
            'runtime' => [
                'php' => ['min' => '8.5.0', 'below' => '8.6.0'],
                'composer' => ['min' => '2.10.0', 'below' => '2.11.0'],
                'sqlite' => ['min' => '3.40.0', 'below' => '4.0.0'],
            ],
            'commands' => [['id' => 'gate', 'kind' => 'gate', 'argv' => ['php', 'bin/check-fixture']]],
            'consumer_cli' => [
                'argv' => ['php', 'vendor/bin/waaseyaa', 'list', '--raw'],
                'required_commands' => ['list', 'db:init', 'site:init', 'site:doctor', 'install:init'],
                'lifecycle_artifacts' => ['.waaseyaa/generated.json', 'bin/maintenance/site-verify'],
                'lanes' => [
                    'linux' => ['job' => 'site-reference-consumer', 'candidate_binding' => 'harness-archive', 'boot_environment' => ['app_env' => 'testing', 'source' => 'process']],
                    'windows' => ['job' => 'skeleton-create-project-windows', 'candidate_binding' => 'checkout', 'boot_environment' => ['app_env' => 'local', 'source' => 'consumer-dotenv']],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private static function hostEnvironment(string $host): array
    {
        return [
            'PATH' => '',
            'PATHEXT' => '.COM;.EXE;.BAT;.CMD',
            'RUNNER_OS' => $host === 'windows' ? 'Windows' : 'Linux',
            'NATIVE_HOST_RUNNER_LABEL' => $host === 'windows' ? 'windows-2025' : 'ubuntu-24.04',
            'RUNNER_ARCH' => 'X64',
            'RUNNER_ENVIRONMENT' => 'github-hosted',
            'ImageOS' => $host === 'windows' ? 'win25' : 'ubuntu24',
            'ImageVersion' => '20260922.1',
            'NATIVE_HOST_SHELL' => 'pwsh',
            'NATIVE_HOST_SHELL_VERSION' => '7.6.5',
            'GITHUB_EVENT_NAME' => 'pull_request',
            'GITHUB_SHA' => self::CANDIDATE_SHA,
            'GITHUB_RUN_ID' => '42',
            'GITHUB_RUN_ATTEMPT' => '1',
            'NATIVE_HOST_PR_HEAD_SHA' => self::PR_HEAD_SHA,
        ];
    }

    /**
     * The collector's child processes besides Git: Composer's version, and
     * the reference-consumer helper's read-only generation state. A null
     * activation count models a helper that cannot read the database.
     *
     * @return \Closure(list<string>): ?string
     */
    private static function childModel(string $checkout = '', string $consumer = '', ?int $activations = 1): \Closure
    {
        return static function (array $command) use ($checkout, $consumer, $activations): ?string {
            if (in_array('--version', $command, true)) {
                return "Composer version 2.10.3 2026-09-01 00:00:00\n";
            }
            if ($command === [PHP_BINARY, $checkout . '/tests/ReferenceConsumer/prepare.php', 'generation-state', $checkout, $consumer]) {
                return $activations === null ? null : json_encode(['generation_count' => $activations, 'activation_count' => $activations, 'generations' => [], 'activations' => []], JSON_THROW_ON_ERROR) . "\n";
            }

            return null;
        };
    }

    private static function write(string $path, string $bytes): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $bytes);
    }

    private function scratch(): string
    {
        if ($this->scratch === null) {
            $this->scratch = sys_get_temp_dir() . '/waaseyaa_native_host_consumer_' . bin2hex(random_bytes(6));
            mkdir($this->scratch, 0o777, true);
        }

        return $this->scratch;
    }
}
