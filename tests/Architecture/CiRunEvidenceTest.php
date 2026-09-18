<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use CiRunEvidenceFailure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Focused proof for the CI measurement baseline pipeline
 * (FW-CI-CHECK-ROSTER-AUDIT-01, Task 4; measurement owner #2869; mirror #3087).
 *
 * Every case here is fixture-driven. The GitHub transport is a callable, so the
 * test injects a fixture response map and drives collection, classification and
 * reporting end to end with **no live GitHub call** — the pipeline's one live
 * pass belongs to the operator who froze the dataset, not to the suite.
 *
 * The fingerprint cases do shell out to `bin/generate-ci-workflow-inventory`,
 * because the fingerprint is defined as a reduction of THAT generator's output
 * and a test that reimplemented the parse would prove nothing about the real
 * comparability rule.
 */
#[CoversNothing]
final class CiRunEvidenceTest extends TestCase
{
    private const REPOSITORY = 'waaseyaa/framework';

    /** @var list<string> */
    private array $temporaryRoots = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/lib/ci-run-evidence.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            self::removeTree($root);
        }
        $this->temporaryRoots = [];
    }

    // -----------------------------------------------------------------------
    // Fingerprint: comparability is job structure, not workflow bytes
    // -----------------------------------------------------------------------

    #[Test]
    public function a_reformat_or_a_comment_leaves_the_fingerprint_unchanged(): void
    {
        $base = self::fixtureWorkflow();
        $reformatted = "# a leading comment the fingerprint must ignore\n"
            . str_replace("    steps:\n", "\n    steps:\n", $base)
            . "\n# a trailing comment\n";

        self::assertNotSame($base, $reformatted, 'The fixture variants must differ in bytes.');
        self::assertSame(
            $this->fingerprint($base),
            $this->fingerprint($reformatted),
            'Comparability is job structure; whitespace and comments must not move the fingerprint.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function structuralDriftProvider(): iterable
    {
        yield 'a renamed job changes its visible context' => [
            str_replace('name: fx/prepare', 'name: fx/setup', self::fixtureWorkflow()),
        ];
        yield 'a wider matrix changes the bounded expansion' => [
            str_replace('id: [1, 2]', 'id: [1, 2, 3]', self::fixtureWorkflow()),
        ];
        yield 'an added needs edge changes the dependency graph' => [
            str_replace('    needs: [shard]', '    needs: [shard, prepare]', self::fixtureWorkflow()),
        ];
        yield 'an added job changes the job set' => [
            self::fixtureWorkflow() . <<<'YAML'

                  extra:
                    name: fx/extra
                    runs-on: ubuntu-24.04
                    steps:
                      - run: bash tools/extra.sh
                YAML,
        ];
    }

    #[Test]
    #[DataProvider('structuralDriftProvider')]
    public function structural_drift_changes_the_fingerprint(string $drifted): void
    {
        self::assertNotSame(
            $this->fingerprint(self::fixtureWorkflow()),
            $this->fingerprint($drifted),
            'A structural change must not be admitted into a comparable cohort.',
        );
    }

    // -----------------------------------------------------------------------
    // Derived scalars
    // -----------------------------------------------------------------------

    #[Test]
    public function queue_and_wall_are_whole_seconds_and_never_negative(): void
    {
        self::assertSame(10, cre_seconds_between('2026-09-18T12:00:00Z', '2026-09-18T12:00:10Z'));
        self::assertSame(0, cre_seconds_between('2026-09-18T12:00:10Z', '2026-09-18T12:00:00Z'));
        self::assertNull(cre_seconds_between(null, '2026-09-18T12:00:10Z'));
        self::assertNull(cre_seconds_between('2026-09-18T12:00:00Z', null));
        self::assertNull(cre_seconds_between('', ''));
    }

    #[Test]
    public function the_runner_multiplier_is_matched_by_os_and_never_invents_one(): void
    {
        self::assertSame(
            ['multiplier' => 1, 'matched' => true, 'os' => 'ubuntu'],
            cre_runner_multiplier(['ubuntu-24.04']),
        );
        self::assertSame(
            ['multiplier' => 2, 'matched' => true, 'os' => 'windows'],
            cre_runner_multiplier(['windows-2025']),
        );
        self::assertSame(
            ['multiplier' => 1, 'matched' => false, 'os' => null],
            cre_runner_multiplier(['self-hosted-mystery']),
            'An unrecognised runner must inflate nothing and stay visible as unmatched.',
        );
    }

    #[Test]
    public function the_percentile_is_nearest_rank_and_never_interpolates(): void
    {
        $sample = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

        // ceil(0.5 * 10) = 5 -> the fifth smallest.
        self::assertSame(50, cre_nearest_rank($sample, 0.5));
        // ceil(0.95 * 10) = 10 -> the largest; never 95, which does not occur.
        self::assertSame(100, cre_nearest_rank($sample, 0.95));
        self::assertSame(10, cre_nearest_rank($sample, 0.0));
        self::assertSame(7, cre_nearest_rank([7], 0.95));
        self::assertNull(cre_nearest_rank([], 0.5));

        foreach ([0.5, 0.9, 0.95, 0.99] as $quantile) {
            self::assertContains(
                cre_nearest_rank($sample, $quantile),
                $sample,
                'A nearest-rank percentile must always be a value that occurred.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // The PHPUnit log parser
    // -----------------------------------------------------------------------

    #[Test]
    public function the_log_parser_reads_a_failure_block_through_timestamps_and_ansi(): void
    {
        $parsed = cre_parse_phpunit_log(self::fixtureLog(
            "There was 1 failure:\n"
            . "\n"
            . "1) Waaseyaa\\AdminSurface\\Tests\\Unit\\AdminDistContentTest::shipped_bundle_is_consistent\n"
            . "Failed asserting that false is true.\n"
            . "\n"
            . "\x1b[37;41mFAILURES!\x1b[0m\n"
            . "\x1b[37;41mTests: 8649, Assertions: 224965, Failures: 1.\x1b[0m\n",
        ));

        self::assertSame(
            ['Waaseyaa\AdminSurface\Tests\Unit\AdminDistContentTest::shipped_bundle_is_consistent'],
            $parsed['identities'],
        );
        self::assertSame(1, $parsed['reported_total']);
        self::assertSame('high', $parsed['confidence']);
        self::assertCount(1, $parsed['raw_lines']);
        self::assertStringContainsString('AdminDistContentTest', $parsed['raw_lines'][0]);
        self::assertStringNotContainsString("\x1b", $parsed['raw_lines'][0], 'ANSI escapes must be stripped.');
        self::assertStringNotContainsString('2026-09-18T', $parsed['raw_lines'][0], 'The runner stamp must be stripped.');
    }

    #[Test]
    public function the_log_parser_collects_failure_and_error_blocks_which_both_number_from_one(): void
    {
        $parsed = cre_parse_phpunit_log(self::fixtureLog(
            "There was 1 failure:\n"
            . "1) Acme\\OneTest::testAlpha\n"
            . "\n"
            . "There was 1 error:\n"
            . "1) Acme\\TwoTest::testBeta\n"
            . "Tests: 3, Assertions: 4, Errors: 1, Failures: 1.\n",
        ));

        self::assertSame(['Acme\OneTest::testAlpha', 'Acme\TwoTest::testBeta'], $parsed['identities']);
        self::assertSame(2, $parsed['reported_total']);
        self::assertSame('high', $parsed['confidence']);
    }

    #[Test]
    public function unrelated_log_noise_yields_no_identity_and_never_a_false_positive(): void
    {
        $parsed = cre_parse_phpunit_log(self::fixtureLog(
            "Downloading composer packages\n"
            . "1) this line is numbered but names no test\n"
            . "Warning: something::something happened, but not as a numbered entry\n"
            . "OK (8649 tests, 224965 assertions)\n",
        ));

        self::assertSame([], $parsed['identities']);
        self::assertSame([], $parsed['raw_lines']);
        self::assertNull($parsed['reported_total']);
        self::assertSame('low', $parsed['confidence']);
    }

    #[Test]
    public function a_truncated_log_parses_what_it_has_and_reports_low_confidence(): void
    {
        $parsed = cre_parse_phpunit_log(self::fixtureLog(
            "There were 3 failures:\n"
            . "1) Acme\\OneTest::testAlpha\n"
            . 'Failed asserting that false is',
        ));

        self::assertSame(['Acme\OneTest::testAlpha'], $parsed['identities']);
        self::assertNull($parsed['reported_total'], 'A truncated log has no summary line to count against.');
        self::assertSame(
            'low',
            $parsed['confidence'],
            'One parsed identity against a three-failure header must not read as a complete parse.',
        );
    }

    #[Test]
    public function the_junit_parser_reads_only_failing_test_cases(): void
    {
        $identities = cre_parse_junit(
            '<?xml version="1.0"?><testsuites><testsuite name="s">'
            . '<testcase name="passes" class="Acme\GreenTest" classname="Acme.GreenTest"/>'
            . '<testcase name="fails" class="Acme\RedTest" classname="Acme.RedTest"><failure>boom</failure></testcase>'
            . '<testcase name="errors" classname="Acme.ErrTest"><error>bang</error></testcase>'
            . '</testsuite></testsuites>',
        );

        self::assertSame(['Acme\ErrTest::errors', 'Acme\RedTest::fails'], $identities);
    }

    #[Test]
    public function an_unparsable_junit_document_fails_closed_instead_of_reading_as_no_failures(): void
    {
        // This is the sharpest failure mode in the whole pipeline. An empty
        // ordinary set makes every random-order failure look unique, so a
        // corrupt or truncated JUnit file must never be indistinguishable from
        // a green shard -- it would manufacture exactly the finding this
        // measurement is most likely to be quoted for.
        self::assertNull(cre_parse_junit('<?xml version="1.0"?><testsuites><testsuite>'));
        self::assertNull(cre_parse_junit('not xml at all'));
        self::assertNull(cre_parse_junit(''));

        // A well-formed document with no failing test is a different fact, and
        // must stay distinguishable from an unreadable one.
        self::assertSame(
            [],
            cre_parse_junit('<?xml version="1.0"?><testsuites><testsuite name="s">'
                . '<testcase name="passes" class="Acme\GreenTest"/></testsuite></testsuites>'),
        );
    }

    #[Test]
    public function an_unreadable_shard_artifact_is_a_parser_miss_and_never_an_empty_ordinary_set(): void
    {
        $callLog = [];
        $result = cre_ordinary_shard_identities(
            // A body that is not a zip at all: nothing can extract it.
            static fn(): array => ['status' => 200, 'body' => 'this is not a zip archive'],
            self::REPOSITORY,
            [['id' => 7, 'name' => 'php-test-shard-1', 'expired' => false, 'size_in_bytes' => 25]],
            $this->temporaryRoot('artifact'),
            '2026-09-18T00:00:00Z',
            $callLog,
        );

        self::assertNotSame('ok', $result['status']);
        self::assertStringStartsWith('parser_miss', $result['status']);
        self::assertSame([], $result['identities']);
        // The fetch still happened and is still recorded, so the failure is
        // evidenced rather than merely asserted.
        self::assertCount(1, $result['observations']);
        self::assertSame(7, $result['observations'][0]['artifact_id']);
    }

    #[Test]
    public function identity_normalisation_strips_the_data_set_suffix_only(): void
    {
        self::assertSame('Acme\Test::method', cre_normalise_identity('Acme\Test::method with data set #3'));
        self::assertSame('Acme\Test::method', cre_normalise_identity('Acme\Test::method'));
        self::assertSame('Acme\Test::method', cre_normalise_identity('Acme\Test::method  '));
    }

    // -----------------------------------------------------------------------
    // Collection against a fixture transport
    // -----------------------------------------------------------------------

    #[Test]
    public function collection_resolves_the_cohort_and_records_every_skip_with_a_reason(): void
    {
        $dataset = $this->collectFixture();
        $resolved = $dataset['cohort']['resolved']['pull_request'];

        self::assertSame([1001, 1002, 1003, 1005], $resolved['run_ids']);
        self::assertSame(4, $resolved['run_count']);
        self::assertSame(3, $resolved['failure_count']);
        self::assertTrue($resolved['target_met']);

        $skipped = [];
        foreach ($dataset['cohort']['skipped_runs'] as $entry) {
            $skipped[$entry['run_id']] = $entry['reason'];
        }
        self::assertSame(
            [1004 => 'cancelled-or-superseded', 1006 => 'fingerprint-mismatch'],
            $skipped,
            'A cancelled run and a structurally different run are both skipped, and both are named.',
        );
    }

    #[Test]
    public function an_exhaustive_cohort_takes_every_run_and_is_complete_only_when_the_listing_ends(): void
    {
        $dataset = $this->collectFixture();
        $nightly = $dataset['cohort']['resolved']['nightly'];

        self::assertTrue($nightly['exhaustive']);
        self::assertTrue(
            $nightly['listing_exhausted'],
            'The short single page is the last page, so the listing — not a page budget — ended the walk.',
        );
        self::assertTrue($nightly['target_met']);

        // An ordinary cohort stops the moment its floor is met, so it does not
        // claim to have exhausted anything.
        self::assertFalse($dataset['cohort']['resolved']['pull_request']['exhaustive']);
        self::assertTrue($dataset['cohort']['resolved']['pull_request']['target_met']);
    }

    #[Test]
    public function an_exhaustive_cohort_truncated_by_its_page_budget_is_never_complete(): void
    {
        // listing_exhausted false plus an unmet floor must both fail closed.
        $cohort = ['exhaustive' => true, 'target_runs' => 1, 'target_failures' => 0];

        self::assertFalse(
            cre_cohort_floor_met($cohort, 5, 0, false),
            'A cohort the page budget cut short has not taken "all of them", however many it took.',
        );
        self::assertTrue(cre_cohort_floor_met($cohort, 5, 0, true));
        self::assertFalse(cre_cohort_floor_met($cohort, 0, 0, true), 'An empty exhaustive cohort misses its floor.');
        self::assertFalse(
            cre_cohort_satisfied($cohort, 99, 0),
            'An exhaustive walk is never satisfied early, however many runs it has already kept.',
        );

        // An ordinary cohort ignores exhaustion entirely.
        $ordinary = ['target_runs' => 3, 'target_failures' => 2];
        self::assertTrue(cre_cohort_floor_met($ordinary, 3, 2, false));
        self::assertFalse(cre_cohort_floor_met($ordinary, 3, 1, true));
    }

    #[Test]
    public function the_pull_request_number_falls_back_to_the_commit_pulls_endpoint(): void
    {
        $dataset = $this->collectFixture();
        $sources = [];
        foreach ($dataset['runs'] as $run) {
            $sources[$run['run_id']] = [$run['pr_number'], $run['pr_source']];
        }

        self::assertSame([501, 'run_payload'], $sources[1001], 'An open run carries its own pull_requests[].');
        self::assertSame(
            [503, 'commit_pulls'],
            $sources[1003],
            'run.pull_requests[] empties once the pull request closes, so the commit endpoint answers.',
        );
        self::assertSame([null, 'none'], $sources[1005], 'A head with no pull request stays null, never invented.');
    }

    #[Test]
    public function run_queue_and_wall_are_derived_from_the_attempts_own_job_timestamps(): void
    {
        $dataset = $this->collectFixture();
        $run = self::runRecord($dataset, 1001, 1);

        // prepare starts at 12:00:10 against a 12:00:00 creation.
        self::assertSame(10, $run['queue_seconds']);
        // The aggregate completes at 12:03:20 against that same creation.
        self::assertSame(200, $run['wall_seconds']);
        self::assertSame(5, $run['job_count']);
    }

    #[Test]
    public function every_attempt_of_a_rerun_is_collected(): void
    {
        $dataset = $this->collectFixture();
        $attempts = [];
        foreach ($dataset['runs'] as $run) {
            if ($run['run_id'] === 1002) {
                $attempts[] = $run['run_attempt'];
            }
        }

        self::assertSame([1, 2], $attempts, 'jobs?filter=all returns every attempt, and all of them are kept.');
    }

    #[Test]
    public function nothing_derived_rests_on_a_value_that_was_not_observed(): void
    {
        $dataset = $this->collectFixture();
        $provenance = $dataset['provenance'];

        self::assertContains('conclusion', $provenance['observed']['jobs']);
        self::assertContains('wall_seconds', $provenance['derived']['jobs']);
        self::assertContains('queue_seconds', $provenance['derived']['runs']);
        self::assertArrayHasKey('billed_runner_minutes', $provenance['unavailable']);
        self::assertStringContainsString('COST PROXY', $provenance['unavailable']['billed_runner_minutes']);
        self::assertNotContains(
            'billed_minutes',
            array_merge($provenance['observed']['jobs'], $provenance['derived']['jobs']),
            'Billed minutes are structurally unavailable and must never appear as a field.',
        );
    }

    /**
     * @return iterable<string, array{list<int>, int|null, list<int>, bool}>
     */
    public static function listingPageProvider(): iterable
    {
        yield 'the first page of a walk is always consistent' => [[900, 800, 700], null, [], true];
        yield 'a page strictly below the previous one continues it' => [[600, 500], 700, [900, 800, 700], true];
        yield 'a page that repeats a scanned run is not advancing' => [[700, 600], 700, [900, 800, 700], false];
        yield 'a page whose top overlaps the previous page went backwards' => [[750, 650], 700, [900, 800], false];
        yield 'a page equal to the previous minimum is an overlap' => [[700, 690], 700, [], false];
        yield 'an empty page is not a violation' => [[], 700, [900, 800, 700], true];
    }

    #[Test]
    #[DataProvider('listingPageProvider')]
    public function a_run_listing_page_must_strictly_advance(
        array $ids,
        ?int $previousMinimum,
        array $seen,
        bool $consistent,
    ): void {
        $candidates = array_map(static fn(int $id): array => ['id' => $id], $ids);
        $violation = cre_listing_page_violation($candidates, $previousMinimum, array_fill_keys($seen, true));

        self::assertSame($consistent, $violation === null, (string) $violation);
    }

    #[Test]
    public function a_listing_that_serves_the_wrong_page_fails_closed_instead_of_losing_runs(): void
    {
        // Observed in the wild: a `page=1` request answered with page 2's
        // content. Every other signal looked healthy — exit status, call count,
        // page count — and the cohort silently lost its most recent hundred
        // runs. Only the run ids gave it away, so the run ids are what guard it.
        $page = 0;
        $runner = static function (string $path) use (&$page): array {
            if (str_contains($path, '/runs?')) {
                ++$page;
                // Every page answers with the same full block of runs. A full
                // page is what keeps the walk going: a short page would end it
                // before a second request could expose the repeat.
                $runs = [];
                foreach (range(1000, 901) as $id) {
                    $runs[] = [
                        'id' => $id, 'run_attempt' => 1, 'head_sha' => str_repeat('a', 40),
                        'conclusion' => 'success', 'event' => 'push', 'path' => '.github/workflows/ci.yml',
                        'created_at' => '2026-09-18T12:00:00Z', 'run_started_at' => '2026-09-18T12:00:00Z',
                        'updated_at' => '2026-09-18T12:03:20Z', 'pull_requests' => [],
                    ];
                }

                return ['status' => 200, 'body' => json_encode(['workflow_runs' => $runs], JSON_THROW_ON_ERROR)];
            }
            if (str_contains($path, '/contents/')) {
                return [
                    'status' => 200,
                    'body' => json_encode(
                        ['sha' => 'blobbase', 'content' => base64_encode(self::fixtureWorkflow())],
                        JSON_THROW_ON_ERROR,
                    ),
                ];
            }
            if (str_contains($path, '/jobs?')) {
                return ['status' => 200, 'body' => '{"jobs":[]}'];
            }
            if (str_contains($path, '/artifacts?')) {
                return ['status' => 200, 'body' => '{"artifacts":[]}'];
            }

            return ['status' => 200, 'body' => '[]'];
        };

        $root = $this->temporaryRoot('pagination');
        @mkdir($root . '/.github/workflows', 0o777, true);
        file_put_contents($root . '/.github/workflows/ci.yml', self::fixtureWorkflow());

        $spec = [
            'repository' => self::REPOSITORY,
            'fingerprint' => ['workflows' => ['ci.yml']],
            'cohorts' => [
                'main_push' => [
                    'workflow' => 'ci.yml', 'event' => 'push', 'branch' => 'main', 'status' => 'completed',
                    'skip_conclusions' => [], 'enforce_fingerprint' => true, 'exhaustive' => false,
                    // A target one full page can never satisfy, so the walk is
                    // forced onto a second page, where the repeat shows up.
                    'target_runs' => 150, 'target_failures' => 0, 'max_pages' => 5, 'resolved_run_ids' => [],
                ],
            ],
        ];

        $this->expectException(CiRunEvidenceFailure::class);
        $this->expectExceptionMessageMatches('/inconsistent run listing for cohort main_push at page 2/');

        cre_collect($spec, $runner, [
            'repository_root' => $root,
            'scratch' => $this->temporaryRoot('pagination-scratch'),
            'collected_at' => '2026-09-18T00:00:00Z',
            'refetch' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Classification
    // -----------------------------------------------------------------------

    #[Test]
    public function each_job_takes_the_classification_its_evidence_forces(): void
    {
        $classified = $this->classifyFixture();
        $labels = self::labels($classified, 1001, 1);

        self::assertSame('success', $labels['fx/prepare']);
        self::assertSame(
            'root_execution_failure',
            $labels['fx/shard-1'],
            'A failure whose first failed step is the job\'s own execution owns the failure.',
        );
        self::assertSame('success', $labels['fx/shard-2']);
        self::assertSame(
            'expected_conditional_skip',
            $labels['fx/optional'],
            'A skipped job that declares its own `if` skipped on purpose.',
        );
        self::assertSame(
            'derivative_aggregate_failure',
            $labels['fx/aggregate'],
            'An aggregate red because its prerequisite is red must not be counted as a second defect.',
        );
    }

    #[Test]
    public function a_setup_step_failure_is_separated_from_an_execution_failure(): void
    {
        $classified = $this->classifyFixture();
        $labels = self::labels($classified, 1003, 1);

        self::assertSame('setup_or_infrastructure_failure', $labels['fx/prepare']);
        self::assertSame(
            'unexpected_skip_or_missing_prerequisite',
            $labels['fx/shard-1'],
            'A job with no condition of its own that skips did so because a prerequisite failed.',
        );

        $record = self::jobRecord($classified, 1003, 1, 'fx/shard-1');
        self::assertSame(['fx/prepare'], $record['derivative_of'], 'The root must be named, so it can be deduped.');
    }

    #[Test]
    public function an_always_aggregate_never_counts_as_an_expected_conditional_skip(): void
    {
        $classified = $this->classifyFixture();
        $record = self::jobRecord($classified, 1003, 1, 'fx/aggregate');

        self::assertNotSame(
            'expected_conditional_skip',
            $record['classification'],
            'The inventory derivation rule is explicit: `always()` is the one condition that cannot skip a job, '
            . 'so it can never explain a skip.',
        );
        self::assertSame('unexpected_skip_or_missing_prerequisite', $record['classification']);
        self::assertSame(['fx/shard-1'], $record['derivative_of']);
    }

    #[Test]
    public function a_cancellation_is_classified_as_such_and_never_as_a_defect(): void
    {
        $classified = $this->classifyFixture();
        $record = self::jobRecord($classified, 1005, 1, 'fx/optional');

        self::assertSame('cancellation', $record['classification']);
        self::assertStringContainsString('not a defect', $record['reason']);
    }

    #[Test]
    public function the_classification_precedence_is_recorded_with_the_result(): void
    {
        $classified = $this->classifyFixture();

        self::assertSame(
            [
                'cancellation',
                'expected_conditional_skip',
                'unexpected_skip_or_missing_prerequisite',
                'derivative_aggregate_failure',
                'setup_or_infrastructure_failure',
                'root_execution_failure',
                'publication_only',
                'success',
                'unclassified',
            ],
            $classified['classifications']['precedence'],
        );
        self::assertContains(
            'Set up job',
            $classified['classifications']['infrastructure_step_names'],
            'The bounded infrastructure step list travels with the dataset so the label can be audited.',
        );
    }

    #[Test]
    public function a_red_to_green_flip_on_one_head_is_a_flake(): void
    {
        $classified = $this->classifyFixture();
        $rerun = self::rerunRecord($classified, 1002);

        self::assertSame([1, 2], $rerun['attempts']);
        self::assertTrue($rerun['same_head']);
        self::assertSame('flake', $rerun['outcome']);
        self::assertSame(['fx/shard-1'], $rerun['flipped_red_to_green']);
        self::assertSame([], $rerun['still_red']);
        self::assertStringContainsString('new head is a different run id', $rerun['rule']);
    }

    #[Test]
    public function a_differing_head_across_attempts_is_not_a_rerun(): void
    {
        // GitHub cannot produce this, because a new head starts a new run id.
        // The guard must still be provable, so the property is forced here and
        // the classifier must refuse to call it a flake.
        $dataset = $this->collectFixture();
        foreach ($dataset['runs'] as $index => $run) {
            if ($run['run_id'] === 1002 && $run['run_attempt'] === 2) {
                $dataset['runs'][$index]['head_sha'] = str_repeat('f', 40);
            }
        }
        $classified = cre_classify($dataset, self::inventory(), null, [
            'with_logs' => false,
            'scratch' => $this->temporaryRoot('classify'),
            'classified_at' => '2026-09-18T00:00:00Z',
        ]);
        $rerun = self::rerunRecord($classified, 1002);

        self::assertFalse($rerun['same_head']);
        self::assertSame('not_a_rerun_new_head', $rerun['outcome']);
        self::assertNotSame('flake', $rerun['outcome']);
    }

    #[Test]
    public function the_critical_path_is_the_longest_needs_chain_by_actual_timestamps(): void
    {
        $classified = $this->classifyFixture();
        $record = self::runClassification($classified, 1001, 1);

        self::assertSame(
            ['fx/prepare', 'fx/shard-1', 'fx/aggregate'],
            array_column($record['critical_path']['chain'], 'name'),
            'shard-1 finishes after shard-2, so it — not shard-2 — gated the aggregate.',
        );
        self::assertSame(200, $record['critical_path']['seconds']);
        self::assertSame(
            [50, 120, 10],
            array_column($record['critical_path']['chain'], 'wall_seconds'),
        );
    }

    #[Test]
    public function the_cost_proxy_weights_job_wall_by_the_runner_multiplier_and_says_so(): void
    {
        $classified = $this->classifyFixture();
        $record = self::runClassification($classified, 1001, 1);

        // 50 + 120 + 60 + 0 + 10 seconds, every job on a x1 ubuntu runner.
        self::assertSame(240, $record['cost_proxy_seconds']);
        self::assertTrue($record['cost_proxy_complete']);
        self::assertStringContainsString('not billed spend', $record['cost_proxy_basis']);
        self::assertSame(200, $record['latency_seconds'], 'PR latency stays separate from the cost proxy.');
    }

    #[Test]
    public function failure_ownership_separates_roots_from_derivatives(): void
    {
        $classified = $this->classifyFixture();
        $record = self::runClassification($classified, 1001, 1);

        self::assertSame(['fx/shard-1'], $record['failure_ownership']['roots']);
        self::assertSame(['fx/aggregate'], $record['failure_ownership']['derivative']);
    }

    #[Test]
    public function random_order_uniqueness_is_not_classifiable_without_logs(): void
    {
        $classified = $this->classifyFixture();
        $records = $classified['classifications']['random_order_uniqueness']['records'];

        foreach ($records as $record) {
            self::assertSame('not_classifiable', $record['verdict']);
            self::assertSame('logs_not_fetched', $record['reason']);
        }
        self::assertStringContainsString(
            'upload no JUnit',
            $classified['classifications']['random_order_uniqueness']['statement'],
        );
    }

    #[Test]
    public function log_availability_distinguishes_not_required_from_not_probed(): void
    {
        $states = [];
        foreach ($this->classifyFixture()['evidence_state'] as $entry) {
            $states[$entry['run_id']] = $entry['logs_available'];
        }

        // The fixture runs have no random-order shard at all, so no log was
        // ever needed. That must not read the same as "we did not look".
        foreach ($states as $runId => $state) {
            self::assertSame('not-required', $state, "Run {$runId} reports the wrong log availability.");
        }
        self::assertNotEmpty($states);
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    #[Test]
    public function two_renders_of_one_dataset_are_byte_identical(): void
    {
        $classified = $this->classifyFixture();

        self::assertSame(
            cre_render_report($classified),
            cre_render_report($classified),
            'The report reads the dataset and nothing else — no clock, no environment, no ordering luck.',
        );
    }

    #[Test]
    public function the_report_states_the_floor_against_the_evidence(): void
    {
        $report = cre_render_report($this->classifyFixture());

        self::assertStringContainsString('## 8. Acceptance floor', $report);
        self::assertStringContainsString('| >= 10 comparable runs |', $report);
        self::assertStringContainsString('one green pull-request run', $report);
        self::assertStringContainsString('one green post-merge `main` run', $report);
        self::assertStringContainsString('one green complete nightly proof', $report);
        // The fixture cohort holds 4 comparable pull-request runs plus 1 push
        // run, which is below the ten-run floor, so the report must say so.
        self::assertStringContainsString('**not met**', $report);
        self::assertStringContainsString('satisfied only in proxy form', $report);
    }

    #[Test]
    public function the_report_leads_the_uniqueness_section_with_what_it_could_not_classify(): void
    {
        $report = cre_render_report($this->classifyFixture());
        $section = substr($report, (int) strpos($report, '## 7. Random-order uniqueness'));

        self::assertStringContainsString('**`not_classifiable`:', $section);
        self::assertLessThan(
            (int) strpos($section, '| `unique_first_pass_detection` |'),
            (int) strpos($section, '**`not_classifiable`:'),
            'What could not be decided comes before what could.',
        );
    }

    #[Test]
    public function a_classification_that_never_occurred_renders_as_zero_rather_than_vanishing(): void
    {
        $report = cre_render_report($this->classifyFixture());

        // The fixture cohort contains no publication-role job, so the term has
        // no instance. An absent row would be indistinguishable from a term the
        // pass forgot to apply, so it must read as an explicit zero.
        self::assertStringContainsString('| `publication_only` | 0 |', $report);
        self::assertStringContainsString('| `unclassified` | 0 |', $report);
    }

    #[Test]
    public function the_report_refuses_to_make_a_cadence_recommendation(): void
    {
        $report = cre_render_report($this->classifyFixture());

        self::assertStringContainsString('## 10. What this baseline does NOT say', $report);
        self::assertStringContainsString('**no cadence recommendation**', $report);
        self::assertStringContainsString('is Task 8', $report);
        self::assertStringContainsString('The cost proxy is **not** money', $report);
        self::assertStringContainsString('## 9. Missing evidence', $report);
        self::assertStringContainsString('Billed runner minutes: 0 of', $report);
    }

    #[Test]
    public function a_missing_duration_renders_as_a_dash_and_never_as_zero(): void
    {
        self::assertSame('--', cre_format_seconds(null));
        self::assertSame('0 s (0m 00s)', cre_format_seconds(0));
        self::assertSame('125 s (2m 05s)', cre_format_seconds(125));
    }

    // -----------------------------------------------------------------------
    // Fail-closed transport and atomic writes
    // -----------------------------------------------------------------------

    #[Test]
    public function a_non_2xx_response_aborts_instead_of_producing_a_partial_dataset(): void
    {
        $this->expectException(CiRunEvidenceFailure::class);
        $this->expectExceptionMessageMatches('/status 404/');

        cre_api_json(static fn(): array => ['status' => 404, 'body' => 'HTTP 404: Not Found'], 'repos/x/y/z');
    }

    #[Test]
    public function malformed_json_aborts_rather_than_decoding_to_null(): void
    {
        $this->expectException(CiRunEvidenceFailure::class);
        $this->expectExceptionMessageMatches('/malformed JSON/');

        cre_api_json(static fn(): array => ['status' => 200, 'body' => '{not json'], 'repos/x/y/z');
    }

    #[Test]
    public function an_exhausted_rate_limit_aborts_with_its_own_message(): void
    {
        $this->expectException(CiRunEvidenceFailure::class);
        $this->expectExceptionMessageMatches('/rate limit exhausted/');

        cre_api_raw(
            static fn(): array => ['status' => 403, 'body' => 'API rate limit exceeded for user ID 1.'],
            'repos/x/y/logs',
        );
    }

    #[Test]
    public function an_expired_log_is_a_null_body_rather_than_a_failure(): void
    {
        self::assertNull(cre_api_raw(
            static fn(): array => ['status' => 410, 'body' => 'HTTP 410: Gone'],
            'repos/x/y/logs',
        ));
    }

    #[Test]
    public function a_transient_failure_is_retried_and_the_retry_is_recorded(): void
    {
        $attempts = 0;
        $callLog = [];
        // A single 502 in a several-hundred-call collection is the server
        // saying "not now". It must not sink the cohort, and it must not
        // disappear either.
        $decoded = cre_api_json(
            static function () use (&$attempts): array {
                ++$attempts;

                return $attempts === 1
                    ? ['status' => 502, 'body' => 'gh: Server Error (HTTP 502)']
                    : ['status' => 200, 'body' => '{"ok":true}'];
            },
            'repos/x/y/runs/1/jobs',
            $callLog,
        );

        self::assertSame(['ok' => true], $decoded);
        self::assertSame(2, $attempts);
        self::assertSame(
            ['api_calls' => 2, 'retried_requests' => 1, 'retried_paths' => ['repos/x/y/runs/1/jobs']],
            cre_transport_summary($callLog),
            'A retry is a real extra API call, and the dataset must say the collection needed one.',
        );
    }

    #[Test]
    public function a_definite_answer_is_never_retried(): void
    {
        $attempts = 0;
        try {
            cre_api_json(
                static function () use (&$attempts): array {
                    ++$attempts;

                    return ['status' => 404, 'body' => 'HTTP 404: Not Found'];
                },
                'repos/x/y/nope',
            );
            self::fail('A 404 must abort rather than resolve.');
        } catch (CiRunEvidenceFailure $failure) {
            self::assertStringContainsString('status 404', $failure->getMessage());
        }

        self::assertSame(1, $attempts, 'A 404 is the server answering; retrying it would turn a fact into noise.');
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function transienceProvider(): iterable
    {
        yield 'a bad gateway is transient' => [502, 'gh: Server Error (HTTP 502)', true];
        yield 'a service outage is transient' => [503, 'HTTP 503: Service Unavailable', true];
        yield 'a dropped connection with no status is transient' => [0, 'connection reset by peer', true];
        yield 'an unexpected EOF is transient' => [0, 'unexpected EOF', true];
        yield 'a not-found is definite' => [404, 'HTTP 404: Not Found', false];
        yield 'a gone artifact is definite' => [410, 'HTTP 410: Gone', false];
        yield 'a forbidden response is definite' => [403, 'HTTP 403: Forbidden', false];
        yield 'an exhausted rate limit is never transient' => [403, 'API rate limit exceeded', false];
        yield 'a 5xx that mentions the rate limit is still a hard stop' => [503, 'API rate limit exceeded', false];
    }

    #[Test]
    #[DataProvider('transienceProvider')]
    public function only_transient_failures_are_worth_another_attempt(int $status, string $body, bool $transient): void
    {
        self::assertSame($transient, cre_is_transient_failure($status, $body));
    }

    #[Test]
    public function a_persistent_transient_failure_is_never_recorded_as_an_expiry(): void
    {
        $this->expectException(CiRunEvidenceFailure::class);
        $this->expectExceptionMessageMatches('/is not an expiry/');

        // A log that keeps 502-ing is not gone. Returning null here would put a
        // `log_expired` reason into the dataset for something that never
        // expired.
        cre_api_raw(static fn(): array => ['status' => 502, 'body' => 'gh: Server Error (HTTP 502)'], 'repos/x/y/logs');
    }

    #[Test]
    public function a_failed_collection_never_overwrites_an_existing_dataset(): void
    {
        $root = $this->temporaryRoot('partial');
        $output = $root . '/dataset.json';
        file_put_contents($output, "PREVIOUS GOOD DATASET\n");

        // An empty root has no reference workflow, so the reference-fingerprint
        // step fails before any listing call and before any write.
        $result = self::runCli('collect-ci-run-evidence', [
            '--cohort=' . dirname(__DIR__, 2) . '/tools/ci-measurement/cohort-2026-09.json',
            '--output=' . $output,
            '--root=' . $root,
        ]);

        self::assertSame(1, $result['exit'], $result['error']);
        self::assertSame("PREVIOUS GOOD DATASET\n", file_get_contents($output));
        self::assertSame(
            [],
            glob($root . '/*.tmp-*') ?: [],
            'A temp file must never be left behind next to the target.',
        );
    }

    #[Test]
    public function an_atomic_write_refuses_a_missing_parent_directory(): void
    {
        $this->expectException(CiRunEvidenceFailure::class);

        $root = $this->temporaryRoot('atomic');
        // A path whose parent is an existing FILE cannot be created.
        file_put_contents($root . '/blocker', 'x');
        cre_write_atomic($root . '/blocker/nested/dataset.json', '{}');
    }

    // -----------------------------------------------------------------------
    // CLI contracts
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, list<string>, int}>
     */
    public static function cliFailureProvider(): iterable
    {
        $missing = sys_get_temp_dir() . '/waaseyaa_ci_evidence_absent_' . getmypid() . '.json';

        yield 'collect rejects an unknown option' => ['collect-ci-run-evidence', ['--nope'], 2];
        yield 'collect requires a cohort' => ['collect-ci-run-evidence', ['--dry-run'], 2];
        yield 'collect rejects an unreadable cohort' => [
            'collect-ci-run-evidence',
            ['--cohort=' . $missing, '--dry-run'],
            2,
        ];
        yield 'classify rejects an unknown option' => ['classify-ci-run-evidence', ['--nope'], 2];
        yield 'classify rejects an unreadable dataset' => [
            'classify-ci-run-evidence',
            ['--dataset=' . $missing],
            2,
        ];
        yield 'report rejects an unknown option' => ['report-ci-measurement', ['--nope'], 2];
        yield 'report requires a dataset' => ['report-ci-measurement', [], 2];
        yield 'report rejects an unreadable dataset' => ['report-ci-measurement', ['--dataset=' . $missing], 2];
    }

    #[Test]
    #[DataProvider('cliFailureProvider')]
    public function the_clis_reject_bad_usage_with_exit_two(string $tool, array $arguments, int $expected): void
    {
        $result = self::runCli($tool, $arguments);

        self::assertSame($expected, $result['exit'], $result['error'] . $result['output']);
    }

    #[Test]
    public function the_report_refuses_an_unclassified_dataset(): void
    {
        $root = $this->temporaryRoot('unclassified');
        $path = $root . '/dataset.json';
        file_put_contents($path, cre_render_json($this->collectFixture()));

        $result = self::runCli('report-ci-measurement', ['--dataset=' . $path]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('carries no classifications', $result['error']);
    }

    #[Test]
    public function the_dry_run_prints_a_call_estimate_and_makes_no_call(): void
    {
        $result = self::runCli('collect-ci-run-evidence', [
            '--cohort=' . dirname(__DIR__, 2) . '/tools/ci-measurement/cohort-2026-09.json',
            '--dry-run',
        ]);

        self::assertSame(0, $result['exit'], $result['error']);
        self::assertStringContainsString('estimated GitHub API calls:', $result['output']);
        self::assertStringContainsString('no calls were made.', $result['output']);
        self::assertMatchesRegularExpression('/target=60 runs \(>=15 red\)/', $result['output']);
    }

    // -----------------------------------------------------------------------
    // The tracked cohort spec
    // -----------------------------------------------------------------------

    #[Test]
    public function the_tracked_cohort_spec_freezes_the_numbers_this_baseline_claims(): void
    {
        $spec = cre_read_json(dirname(__DIR__, 2) . '/tools/ci-measurement/cohort-2026-09.json');

        self::assertSame('ci-measurement-cohort-spec', $spec['kind']);
        self::assertSame(self::REPOSITORY, $spec['repository']);
        self::assertSame(60, $spec['cohorts']['pull_request']['target_runs']);
        self::assertSame(15, $spec['cohorts']['pull_request']['target_failures']);
        self::assertTrue($spec['cohorts']['pull_request']['enforce_fingerprint']);
        self::assertSame(20, $spec['cohorts']['main_push']['target_runs']);
        self::assertFalse(
            $spec['cohorts']['nightly']['enforce_fingerprint'],
            'nightly.yml is a different workflow, so its fingerprint is recorded, not used as a filter.',
        );
        self::assertTrue(
            $spec['cohorts']['nightly']['exhaustive'],
            'The nightly cohort is "all of them", so it must not carry a hard-coded run count.',
        );
        self::assertSame(1, $spec['cohorts']['nightly']['target_runs']);
        self::assertFalse($spec['cohorts']['pull_request']['exhaustive']);
        self::assertFalse($spec['cohorts']['main_push']['exhaustive']);
        self::assertContains('cancelled', $spec['cohorts']['pull_request']['skip_conclusions']);
        self::assertArrayHasKey('billed_runner_minutes', $spec['unavailable']);
    }

    // -----------------------------------------------------------------------
    // The tracked evidence: the drift gate
    // -----------------------------------------------------------------------

    #[Test]
    public function the_tracked_dataset_is_frozen_classified_evidence_that_meets_its_own_cohort_floors(): void
    {
        $dataset = cre_read_json(self::trackedDatasetPath());

        self::assertSame(1, $dataset['schema_version']);
        self::assertSame('ci-run-evidence-dataset', $dataset['kind']);
        self::assertSame('FW-CI-CHECK-ROSTER-AUDIT-01', $dataset['change_record']);
        self::assertArrayHasKey('classifications', $dataset);
        self::assertTrue(
            $dataset['classifications']['classifier']['with_logs'],
            'The committed dataset must be the one that could answer the random-order question.',
        );
        self::assertContains(
            $dataset['cohort']['collection_mode'],
            ['walk', 'refetch'],
            'The dataset must say how it was built, because a refetch cannot re-derive the walk.',
        );
        if ($dataset['cohort']['collection_mode'] === 'walk') {
            self::assertNotSame(
                [],
                $dataset['cohort']['skipped_runs'],
                'A walk over this cohort passed over cancelled and action_required runs; an empty '
                . 'skip list would mean the accounting was lost.',
            );
        }

        foreach ($dataset['cohort']['resolved'] as $cohortId => $resolved) {
            self::assertTrue($resolved['target_met'], "Cohort {$cohortId} did not meet its floor.");
        }
        self::assertGreaterThanOrEqual(60, $dataset['cohort']['resolved']['pull_request']['run_count']);
        self::assertGreaterThanOrEqual(15, $dataset['cohort']['resolved']['pull_request']['failure_count']);

        // Every run in a fingerprint-enforced cohort carries that cohort's
        // reference fingerprint; comparability is a property of the data, not
        // only a claim in the prose.
        $cohortOf = [];
        foreach ($dataset['cohort']['resolved'] as $cohortId => $resolved) {
            foreach ($resolved['run_ids'] as $runId) {
                $cohortOf[$runId] = $cohortId;
            }
        }
        foreach ($dataset['runs'] as $run) {
            $resolved = $dataset['cohort']['resolved'][$cohortOf[$run['run_id']]];
            if (!$resolved['enforce_fingerprint']) {
                continue;
            }
            self::assertSame(
                $resolved['reference_fingerprint'],
                $run['fingerprint'],
                "Run {$run['run_id']} is in an enforced cohort but carries a different fingerprint.",
            );
        }
    }

    #[Test]
    public function no_resolved_run_vanishes_from_the_tracked_evidence(): void
    {
        self::assertNoResolvedRunIsMissing(cre_read_json(self::trackedDatasetPath()));
    }

    #[Test]
    public function no_resolved_run_vanishes_from_a_collected_cohort(): void
    {
        self::assertNoResolvedRunIsMissing($this->collectFixture());
    }

    /**
     * Every id the walk resolved into a cohort must be describable: it has at
     * least one first-attempt run record, or it is named in `incomplete_runs`
     * with a reason. A resolved id that appears in neither is a run that
     * silently left the evidence while still being counted in the cohort.
     *
     * @param array<string, mixed> $dataset
     */
    private static function assertNoResolvedRunIsMissing(array $dataset): void
    {
        $firstAttempts = [];
        foreach ($dataset['runs'] as $run) {
            if ($run['run_attempt'] === 1) {
                $firstAttempts[$run['run_id']] = true;
            }
        }
        $incomplete = [];
        foreach ((array) ($dataset['cohort']['incomplete_runs'] ?? []) as $entry) {
            $incomplete[$entry['run_id']] = true;
            self::assertNotSame('', (string) $entry['reason'], 'An incomplete run must carry a reason.');
        }

        foreach ($dataset['cohort']['resolved'] as $cohortId => $resolved) {
            foreach ($resolved['run_ids'] as $runId) {
                self::assertTrue(
                    isset($firstAttempts[$runId]) || isset($incomplete[$runId]),
                    "Run {$runId} is resolved into cohort {$cohortId} but has no first-attempt run record "
                    . 'and no incomplete_runs entry, so it left the evidence silently.',
                );
            }
        }
    }

    #[Test]
    public function a_run_github_lists_no_jobs_for_is_recorded_rather_than_dropped(): void
    {
        $runner = static function (string $path): array {
            if (str_contains($path, '/runs?')) {
                return ['status' => 200, 'body' => json_encode(['workflow_runs' => [[
                    'id' => 7001, 'run_attempt' => 1, 'head_sha' => str_repeat('a', 40),
                    'conclusion' => 'failure', 'event' => 'pull_request',
                    'path' => '.github/workflows/ci.yml', 'created_at' => '2026-09-18T12:00:00Z',
                    'run_started_at' => '2026-09-18T12:00:00Z', 'updated_at' => '2026-09-18T12:05:00Z',
                    'pull_requests' => [],
                ]]], JSON_THROW_ON_ERROR)];
            }
            if (str_contains($path, '/contents/')) {
                return ['status' => 200, 'body' => json_encode(
                    ['sha' => 'blobbase', 'content' => base64_encode(self::fixtureWorkflow())],
                    JSON_THROW_ON_ERROR,
                )];
            }
            // The run completed and failed, yet GitHub lists no jobs for it.
            if (str_contains($path, '/jobs?')) {
                return ['status' => 200, 'body' => '{"total_count":0,"jobs":[]}'];
            }
            if (str_contains($path, '/artifacts?')) {
                return ['status' => 200, 'body' => '{"artifacts":[]}'];
            }

            return ['status' => 200, 'body' => '[]'];
        };

        $root = $this->temporaryRoot('jobless');
        @mkdir($root . '/.github/workflows', 0o777, true);
        file_put_contents($root . '/.github/workflows/ci.yml', self::fixtureWorkflow());

        $dataset = cre_collect([
            'repository' => self::REPOSITORY,
            'fingerprint' => ['workflows' => ['ci.yml']],
            'cohorts' => ['pull_request' => [
                'workflow' => 'ci.yml', 'event' => 'pull_request', 'branch' => null, 'status' => 'completed',
                'skip_conclusions' => [], 'enforce_fingerprint' => true, 'exhaustive' => false,
                'target_runs' => 1, 'target_failures' => 1, 'max_pages' => 1, 'resolved_run_ids' => [],
            ]],
        ], $runner, [
            'repository_root' => $root,
            'scratch' => $this->temporaryRoot('jobless-scratch'),
            'collected_at' => '2026-09-18T00:00:00Z',
            'refetch' => null,
        ]);

        self::assertSame([7001], $dataset['cohort']['resolved']['pull_request']['run_ids']);
        self::assertCount(1, $dataset['runs'], 'The run must still be described.');
        self::assertSame(0, $dataset['runs'][0]['jobs_listed']);
        self::assertSame('failure', $dataset['runs'][0]['conclusion']);
        self::assertNull($dataset['runs'][0]['wall_seconds'], 'No job timestamps exist to derive a wall from.');
        self::assertNull($dataset['runs'][0]['queue_seconds']);
        self::assertSame([], $dataset['jobs']);

        self::assertCount(1, $dataset['cohort']['incomplete_runs']);
        $entry = $dataset['cohort']['incomplete_runs'][0];
        self::assertSame(7001, $entry['run_id']);
        self::assertSame(1, $entry['attempt']);
        self::assertSame('failure', $entry['conclusion']);
        self::assertStringContainsString('no-jobs', $entry['reason']);

        // A red run with no jobs is still a red run: it concluded, and its
        // conclusion is evidence. It simply has no job that owns the failure.
        $classified = cre_classify($dataset, self::inventory(), null, [
            'with_logs' => false,
            'scratch' => $this->temporaryRoot('jobless-classify'),
            'classified_at' => '2026-09-18T00:00:00Z',
        ]);
        $record = self::runClassification($classified, 7001, 1);
        self::assertSame('failure', $record['first_pass_outcome']);
        self::assertSame('no_jobs_listed', $record['failure_ownership']['note']);
        self::assertSame([], $record['failure_ownership']['roots']);
        // Summing a cost over no jobs yields 0, and a 0 in the distribution
        // would assert the attempt was free. It is unknown, so it stays null.
        self::assertNull($record['cost_proxy_seconds']);
        self::assertFalse($record['cost_proxy_complete']);
        self::assertNull($record['critical_path']['seconds']);

        self::assertStringContainsString(
            '| `7001` | 1 | `failure` |',
            cre_render_report($classified),
            'The report must name the run whose records are incomplete.',
        );
    }

    #[Test]
    public function an_empty_skip_list_from_a_refetch_reads_as_not_available_not_as_nothing_skipped(): void
    {
        // A refetch replays a committed run-id list and never walks a listing,
        // so it cannot re-derive what the walk passed over. Rendering that as
        // "No runs were skipped" would turn missing evidence into a clean bill
        // of health -- the exact failure this whole pipeline is built to avoid.
        $dataset = $this->classifyFixture();
        $dataset['cohort']['collection_mode'] = 'refetch';
        $dataset['cohort']['skipped_runs'] = [];
        $dataset['cohort']['skipped_runs_source'] = ['mode' => 'not-available'];

        $report = cre_render_report($dataset);

        self::assertStringContainsString('**Not available.**', $report);
        self::assertStringContainsString('missing evidence, not an empty set', $report);
        self::assertStringNotContainsString('No runs were skipped.', $report);
        self::assertStringContainsString('never walks a run listing', $report);
    }

    #[Test]
    public function a_refetch_carries_the_walks_skip_accounting_forward_rather_than_emptying_it(): void
    {
        $dataset = $this->classifyFixture();
        $dataset['cohort']['collection_mode'] = 'refetch';
        $dataset['cohort']['skipped_runs_source'] = [
            'mode' => 'carried-forward',
            'from_collected_at' => '2026-09-18T16:58:08Z',
        ];

        $report = cre_render_report($dataset);

        self::assertStringContainsString('carried forward unchanged from the collection of', $report);
        self::assertStringContainsString('2026-09-18T16:58:08Z', $report);
        self::assertStringContainsString('not a fresh derivation', $report);
    }

    #[Test]
    public function a_walk_reports_its_own_skip_accounting_without_a_refetch_caveat(): void
    {
        $report = cre_render_report($this->classifyFixture());

        self::assertStringNotContainsString('never walks a run listing', $report);
        self::assertStringNotContainsString('**Not available.**', $report);
    }

    #[Test]
    public function the_tracked_spec_and_dataset_name_exactly_the_same_runs(): void
    {
        $spec = cre_read_json(dirname(__DIR__, 2) . '/tools/ci-measurement/cohort-2026-09.json');
        $dataset = cre_read_json(self::trackedDatasetPath());

        foreach ($dataset['cohort']['resolved'] as $cohortId => $resolved) {
            self::assertSame(
                $resolved['run_ids'],
                $spec['cohorts'][$cohortId]['resolved_run_ids'],
                "The tracked cohort spec and the frozen dataset disagree about cohort {$cohortId}. "
                . 'A --refetch reproducibility check replays the spec, so the two must name one run list.',
            );
        }
    }

    #[Test]
    public function the_committed_report_is_a_fresh_render_of_the_committed_dataset(): void
    {
        $report = dirname(__DIR__, 2) . '/docs/audits/ci-measurement-baseline-2026-09.md';
        self::assertFileExists($report);

        self::assertSame(
            file_get_contents($report),
            cre_render_report(cre_read_json(self::trackedDatasetPath())),
            'The tracked baseline report must be exactly what the renderer produces from the tracked dataset. '
            . 'Regenerate it with `php bin/report-ci-measurement --dataset=tools/ci-measurement/dataset-2026-09.json '
            . '--output=docs/audits/ci-measurement-baseline-2026-09.md` rather than editing it by hand.',
        );
    }

    #[Test]
    public function nothing_in_the_tracked_dataset_claims_a_billing_figure(): void
    {
        $raw = (string) file_get_contents(self::trackedDatasetPath());

        self::assertStringNotContainsString('billed_minutes', $raw);
        self::assertStringNotContainsString('billable_minutes', $raw);
        self::assertStringContainsString('cost_proxy_seconds', $raw);
        self::assertStringContainsString('not billed spend', $raw);
    }

    private static function trackedDatasetPath(): string
    {
        return dirname(__DIR__, 2) . '/tools/ci-measurement/dataset-2026-09.json';
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * A compact workflow whose job graph exercises every structural feature the
     * fingerprint reduces: a singleton setup job, a literal matrix, a
     * conditional job, an `always()` aggregate, and three dependency edges.
     */
    private static function fixtureWorkflow(): string
    {
        return <<<'YAML'
            name: Fixture CI
            on:
              pull_request:
              push:
                branches: [main]
            jobs:
              prepare:
                name: fx/prepare
                runs-on: ubuntu-24.04
                steps:
                  - run: bash tools/prepare.sh
              shard:
                name: fx/shard-${{ matrix.id }}
                needs: [prepare]
                runs-on: ubuntu-24.04
                strategy:
                  matrix:
                    id: [1, 2]
                steps:
                  - run: vendor/bin/phpunit
              optional:
                name: fx/optional
                needs: [prepare]
                if: github.event_name == 'push'
                runs-on: ubuntu-24.04
                steps:
                  - run: bash tools/optional.sh
              aggregate:
                name: fx/aggregate
                needs: [shard]
                if: always()
                runs-on: ubuntu-24.04
                steps:
                  - run: bash tools/aggregate.sh
            YAML;
    }

    /**
     * The same graph with one job renamed, so a run built on it lands outside
     * the comparable cohort.
     */
    private static function driftedWorkflow(): string
    {
        return str_replace('name: fx/prepare', 'name: fx/prepare-v2', self::fixtureWorkflow());
    }

    /** Wraps body lines in the runner's per-line ISO timestamp prefix. */
    private static function fixtureLog(string $body): string
    {
        $out = '';
        foreach (explode("\n", $body) as $index => $line) {
            $out .= sprintf("2026-09-18T12:%02d:%02d.1234567Z %s\n", intdiv($index, 60), $index % 60, $line);
        }

        return $out;
    }

    private function fingerprint(string $yaml): string
    {
        return cre_fingerprint_source(
            dirname(__DIR__, 2),
            $this->temporaryRoot('fingerprint'),
            'ci.yml',
            $yaml,
        );
    }

    /**
     * The generated inventory of the fixture workflow, used as the classifier's
     * structural evidence exactly as the tracked inventory is in production.
     *
     * @return array<string, mixed>
     */
    private static function inventory(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $root = sys_get_temp_dir() . '/waaseyaa_ci_evidence_inv_' . getmypid();
        @mkdir($root . '/.github/workflows', 0o777, true);
        file_put_contents($root . '/.github/workflows/ci.yml', self::fixtureWorkflow());

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/generate-ci-workflow-inventory', '--root=' . $root],
            null,
            null,
            null,
            null,
        );
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $cached = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::removeTree($root);

        return $cached;
    }

    /**
     * The fixture cohort spec: a small pull-request walk plus one push run and
     * one scheduled run, so the floor evaluation has all three cohorts.
     *
     * @return array<string, mixed>
     */
    private static function fixtureSpec(): array
    {
        $cohort = static fn(array $overrides): array => array_merge([
            'workflow' => 'ci.yml',
            'event' => null,
            'branch' => null,
            'status' => 'completed',
            'skip_conclusions' => ['cancelled', 'action_required'],
            'enforce_fingerprint' => true,
            'exhaustive' => false,
            'target_runs' => 4,
            'target_failures' => 0,
            'max_pages' => 2,
            'resolved_run_ids' => [],
        ], $overrides);

        return [
            'schema_version' => 1,
            'kind' => 'ci-measurement-cohort-spec',
            'repository' => self::REPOSITORY,
            'fingerprint' => ['workflows' => ['ci.yml']],
            'cohorts' => [
                'pull_request' => $cohort(['event' => 'pull_request', 'target_runs' => 4, 'target_failures' => 3]),
                'main_push' => $cohort(['event' => 'push', 'branch' => 'main', 'target_runs' => 1]),
                'nightly' => $cohort([
                    'target_runs' => 1,
                    'enforce_fingerprint' => false,
                    'exhaustive' => true,
                ]),
            ],
        ];
    }

    /**
     * Collects the fixture cohort through an injected transport. No network.
     *
     * @return array<string, mixed>
     */
    private function collectFixture(): array
    {
        $root = $this->temporaryRoot('collect');
        @mkdir($root . '/.github/workflows', 0o777, true);
        file_put_contents($root . '/.github/workflows/ci.yml', self::fixtureWorkflow());

        return cre_collect(self::fixtureSpec(), self::fixtureRunner(), [
            'repository_root' => $root,
            'scratch' => $this->temporaryRoot('scratch'),
            'collected_at' => '2026-09-18T00:00:00Z',
            'refetch' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyFixture(): array
    {
        return cre_classify($this->collectFixture(), self::inventory(), null, [
            'with_logs' => false,
            'scratch' => $this->temporaryRoot('classify'),
            'classified_at' => '2026-09-18T00:00:00Z',
        ]);
    }

    /**
     * The fixture GitHub transport: a response map keyed by request path.
     * Anything unmapped is a hard 404, so an unexpected call fails the test
     * rather than silently returning an empty page.
     *
     * @return callable(string, string): array{status:int, body:string}
     */
    private static function fixtureRunner(): callable
    {
        $json = static fn(mixed $value): array => [
            'status' => 200,
            'body' => json_encode($value, JSON_THROW_ON_ERROR),
        ];
        $repository = self::REPOSITORY;

        $run = static fn(int $id, string $conclusion, array $overrides = []): array => array_merge([
            'id' => $id,
            'run_attempt' => 1,
            'head_sha' => str_repeat((string) ($id % 10), 40),
            'conclusion' => $conclusion,
            'event' => 'pull_request',
            'path' => '.github/workflows/ci.yml',
            'created_at' => '2026-09-18T12:00:00Z',
            'run_started_at' => '2026-09-18T12:00:00Z',
            'updated_at' => '2026-09-18T12:03:20Z',
            'pull_requests' => [],
        ], $overrides);

        $listings = [
            "repos/{$repository}/actions/workflows/ci.yml/runs?event=pull_request&page=1&per_page=100&status=completed" => [
                'workflow_runs' => [
                    $run(1001, 'failure', ['pull_requests' => [['number' => 501]]]),
                    $run(1002, 'success', ['run_attempt' => 2]),
                    $run(1003, 'failure'),
                    $run(1004, 'cancelled'),
                    // The drifted run sits before the run that satisfies the
                    // floor, so the fingerprint-mismatch skip is genuinely
                    // exercised rather than merely never reached.
                    $run(1006, 'failure'),
                    $run(1005, 'failure'),
                ],
            ],
            "repos/{$repository}/actions/workflows/ci.yml/runs?branch=main&event=push&page=1&per_page=100&status=completed" => [
                'workflow_runs' => [$run(2001, 'success', ['event' => 'push'])],
            ],
            "repos/{$repository}/actions/workflows/ci.yml/runs?page=1&per_page=100&status=completed" => [
                'workflow_runs' => [$run(3001, 'success', ['event' => 'schedule'])],
            ],
        ];

        return static function (string $path) use ($json, $listings, $repository): array {
            if (isset($listings[$path])) {
                return $json($listings[$path]);
            }
            if (preg_match('#^repos/.+/contents/\.github/workflows/ci\.yml\?ref=(\w+)$#', $path, $match) === 1) {
                // Run 1006 alone was built on a structurally different workflow.
                $drifted = str_starts_with($match[1], '6');
                $source = $drifted ? self::driftedWorkflow() : self::fixtureWorkflow();

                return $json(['sha' => $drifted ? 'blobdrift' : 'blobbase', 'content' => base64_encode($source)]);
            }
            if (preg_match('#^repos/.+/commits/(\w+)/pulls$#', $path, $match) === 1) {
                // Only run 1003's head resolves through the fallback endpoint.
                return $json(str_starts_with($match[1], '3') ? [['number' => 503]] : []);
            }
            if (preg_match('#^repos/.+/actions/runs/(\d+)/jobs\?#', $path, $match) === 1) {
                return $json(['jobs' => self::fixtureJobs((int) $match[1])]);
            }
            if (preg_match('#^repos/.+/actions/runs/(\d+)/artifacts\?#', $path) === 1) {
                return $json(['artifacts' => []]);
            }

            return ['status' => 404, 'body' => "HTTP 404: unmapped fixture path {$path} (in {$repository})"];
        };
    }

    /**
     * The fixture job graph per run. Timestamps are explicit so queue, wall,
     * critical path and cost proxy are exactly checkable.
     *
     * @return list<array<string, mixed>>
     */
    private static function fixtureJobs(int $runId): array
    {
        $job = static fn(
            int $id,
            string $name,
            string $conclusion,
            string $started,
            string $completed,
            array $steps = [],
            int $attempt = 1,
        ): array => [
            'id' => $id,
            'run_attempt' => $attempt,
            'name' => $name,
            'labels' => ['ubuntu-24.04'],
            'created_at' => '2026-09-18T12:00:00Z',
            'started_at' => $started,
            'completed_at' => $completed,
            'conclusion' => $conclusion,
            'steps' => $steps,
        ];
        $step = static fn(int $number, string $name, string $conclusion): array => [
            'number' => $number,
            'name' => $name,
            'conclusion' => $conclusion,
        ];
        $ok = [1 => null];
        unset($ok);

        return match ($runId) {
            // A root execution failure, a conditional skip, and the aggregate
            // that went red only because the root did.
            1001 => [
                $job(1, 'fx/prepare', 'success', '2026-09-18T12:00:10Z', '2026-09-18T12:01:00Z', [
                    $step(1, 'Set up job', 'success'),
                    $step(2, 'Run bash tools/prepare.sh', 'success'),
                ]),
                $job(2, 'fx/shard-1', 'failure', '2026-09-18T12:01:05Z', '2026-09-18T12:03:05Z', [
                    $step(1, 'Set up job', 'success'),
                    $step(2, 'Run vendor/bin/phpunit', 'failure'),
                ]),
                $job(3, 'fx/shard-2', 'success', '2026-09-18T12:01:05Z', '2026-09-18T12:02:05Z', [
                    $step(1, 'Set up job', 'success'),
                ]),
                $job(4, 'fx/optional', 'skipped', '2026-09-18T12:00:20Z', '2026-09-18T12:00:20Z'),
                $job(5, 'fx/aggregate', 'failure', '2026-09-18T12:03:10Z', '2026-09-18T12:03:20Z', [
                    $step(1, 'Run bash tools/aggregate.sh', 'failure'),
                ]),
            ],
            // Two attempts on one head: shard-1 red, then green.
            1002 => [
                $job(11, 'fx/prepare', 'success', '2026-09-18T12:00:10Z', '2026-09-18T12:01:00Z'),
                $job(12, 'fx/shard-1', 'failure', '2026-09-18T12:01:05Z', '2026-09-18T12:02:05Z', [
                    $step(1, 'Run vendor/bin/phpunit', 'failure'),
                ]),
                $job(13, 'fx/shard-2', 'success', '2026-09-18T12:01:05Z', '2026-09-18T12:02:05Z'),
                $job(21, 'fx/prepare', 'success', '2026-09-18T13:00:10Z', '2026-09-18T13:01:00Z', [], 2),
                $job(22, 'fx/shard-1', 'success', '2026-09-18T13:01:05Z', '2026-09-18T13:02:05Z', [], 2),
                $job(23, 'fx/shard-2', 'success', '2026-09-18T13:01:05Z', '2026-09-18T13:02:05Z', [], 2),
            ],
            // A setup failure that starves a downstream job of its prerequisite.
            1003 => [
                $job(31, 'fx/prepare', 'failure', '2026-09-18T12:00:10Z', '2026-09-18T12:00:30Z', [
                    $step(1, 'Set up job', 'failure'),
                ]),
                $job(32, 'fx/shard-1', 'skipped', '2026-09-18T12:00:35Z', '2026-09-18T12:00:35Z'),
                // An `always()` aggregate cannot skip on its own condition, so
                // a skip here must NOT read as an expected conditional skip.
                $job(33, 'fx/aggregate', 'skipped', '2026-09-18T12:00:40Z', '2026-09-18T12:00:40Z'),
            ],
            // A cancelled job inside an otherwise completed run.
            1005 => [
                $job(51, 'fx/prepare', 'success', '2026-09-18T12:00:10Z', '2026-09-18T12:01:00Z'),
                $job(52, 'fx/optional', 'cancelled', '2026-09-18T12:01:05Z', '2026-09-18T12:01:10Z'),
            ],
            2001 => [
                $job(61, 'fx/prepare', 'success', '2026-09-18T12:00:10Z', '2026-09-18T12:01:00Z'),
            ],
            3001 => [
                $job(71, 'fx/prepare', 'success', '2026-09-18T12:00:10Z', '2026-09-18T12:01:00Z'),
            ],
            default => [],
        };
    }

    // -----------------------------------------------------------------------
    // Assertion helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    private static function runRecord(array $dataset, int $runId, int $attempt): array
    {
        foreach ($dataset['runs'] as $run) {
            if ($run['run_id'] === $runId && $run['run_attempt'] === $attempt) {
                return $run;
            }
        }

        self::fail("No run record for {$runId} attempt {$attempt}.");
    }

    /**
     * @param array<string, mixed> $classified
     * @return array<string, string>
     */
    private static function labels(array $classified, int $runId, int $attempt): array
    {
        $labels = [];
        foreach ($classified['classifications']['jobs'] as $record) {
            if ($record['run_id'] === $runId && $record['run_attempt'] === $attempt) {
                $labels[$record['name']] = $record['classification'];
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $classified
     * @return array<string, mixed>
     */
    private static function jobRecord(array $classified, int $runId, int $attempt, string $name): array
    {
        foreach ($classified['classifications']['jobs'] as $record) {
            if ($record['run_id'] === $runId && $record['run_attempt'] === $attempt && $record['name'] === $name) {
                return $record;
            }
        }

        self::fail("No classification for {$name} in run {$runId} attempt {$attempt}.");
    }

    /**
     * @param array<string, mixed> $classified
     * @return array<string, mixed>
     */
    private static function runClassification(array $classified, int $runId, int $attempt): array
    {
        foreach ($classified['classifications']['runs'] as $record) {
            if ($record['run_id'] === $runId && $record['run_attempt'] === $attempt) {
                return $record;
            }
        }

        self::fail("No run classification for {$runId} attempt {$attempt}.");
    }

    /**
     * @param array<string, mixed> $classified
     * @return array<string, mixed>
     */
    private static function rerunRecord(array $classified, int $runId): array
    {
        foreach ($classified['classifications']['reruns'] as $record) {
            if ($record['run_id'] === $runId) {
                return $record;
            }
        }

        self::fail("No rerun record for {$runId}.");
    }

    /**
     * @param list<string> $arguments
     * @return array{exit:int, output:string, error:string}
     */
    private static function runCli(string $tool, array $arguments): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $tool], $arguments),
            null,
            null,
            null,
            null,
        );

        return [
            'exit' => $process->run(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    private function temporaryRoot(string $label): string
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
            . '/waaseyaa_ci_evidence_' . $label . '_' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o777, true) && !is_dir($root)) {
            self::fail("Unable to create the temporary root {$root}.");
        }
        $this->temporaryRoots[] = $root;

        return $root;
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory, SCANDIR_SORT_NONE) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
