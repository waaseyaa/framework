<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

require_once dirname(__DIR__, 2) . '/tools/j1-published-s1/qualify.php';
require_once dirname(__DIR__, 2) . '/tools/j1-published-s1/read-content.php';

#[CoversNothing]
final class PublishedS1QualificationContractTest extends TestCase
{
    #[Test]
    public function one_authoritative_file_allows_sqlite_temp_but_refuses_other_schemas_and_paths(): void
    {
        $root = sys_get_temp_dir() . '/j1-topology-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir($root . '/storage');
        $path = $root . '/storage/waaseyaa.sqlite';
        $fs->dumpFile($path, 'Path witness only; the unit test opens no database.');
        $main = ['seq' => 0, 'name' => 'main', 'file' => $path];
        $temp = ['seq' => 1, 'name' => 'temp', 'file' => ''];
        $observed = ['foreign_keys' => [['foreign_keys' => 1]], 'journal_mode' => [['journal_mode' => 'wal']],
            'busy_timeout' => [['timeout' => 5000]], 'database_list' => [$main, $temp]];
        try {
            self::assertTrue(j1DatabaseTopologyMatches($observed, $root));
            foreach ([[$main], [$temp, $main]] as $valid) {
                self::assertTrue(j1DatabaseTopologyMatches(array_replace($observed, ['database_list' => $valid]), $root));
            }
            foreach ([[], [$temp], [$main, $main], [$main, $temp, $temp], [$main, ['name' => 'other', 'file' => '']],
                [$main, ['name' => 'other', 'file' => $path]], [$main, ['name' => 'temp', 'file' => $path]],
                [['name' => 'main', 'file' => $root . '/elsewhere.sqlite']]] as $invalid) {
                self::assertFalse(j1DatabaseTopologyMatches(array_replace($observed, ['database_list' => $invalid]), $root));
            }
            self::assertFalse(j1DatabaseTopologyMatches(array_replace($observed, ['foreign_keys' => [['foreign_keys' => 0]]]), $root));
            self::assertFalse(j1DatabaseTopologyMatches(array_replace($observed, ['journal_mode' => [['journal_mode' => 'delete']]]), $root));
            self::assertFalse(j1DatabaseTopologyMatches(array_replace($observed, ['busy_timeout' => [['timeout' => 0]]]), $root));
        } finally {
            $fs->remove($root);
        }
    }

    #[Test]
    public function original_archive_bytes_discriminate_a_manifest_only_match_from_a_stale_runtime(): void
    {
        $root = sys_get_temp_dir() . '/j1-dist-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir($root . '/installed/src');
        $fs->dumpFile($root . '/installed/composer.json', '{"name":"waaseyaa/example"}');
        $fs->dumpFile($root . '/installed/src/Example.php', '<?php echo "reviewed";');
        $zip = new \ZipArchive();
        $zip->open($root . '/dist.zip', \ZipArchive::CREATE);
        $zip->addFromString('waaseyaa-example-abcdef0/composer.json', '{"name":"waaseyaa/example"}');
        $zip->addFromString('waaseyaa-example-abcdef0/src/Example.php', '<?php echo "reviewed";');
        $zip->close();
        try {
            $qualification = new \ReflectionClass(\PublishedS1Qualification::class)->newInstanceWithoutConstructor();
            $match = new \ReflectionMethod($qualification, 'zipMatches');
            self::assertTrue($match->invoke($qualification, $root . '/dist.zip', $root . '/installed'));
            $fs->dumpFile($root . '/installed/src/Example.php', '<?php echo "stale";');
            self::assertFalse($match->invoke($qualification, $root . '/dist.zip', $root . '/installed'));
            self::assertTrue($match->invoke($qualification, $root . '/dist.zip', $root . '/installed', ['composer.json']));
            self::assertFalse($match->invoke($qualification, $root . '/dist.zip', $root . '/installed', ['missing.php']));
        } finally {
            $fs->remove($root);
        }
    }

    #[Test]
    public function private_diagnostic_selection_ignores_the_first_unrelated_json_on_an_apache_line(): void
    {
        $qualification = new \ReflectionClass(\PublishedS1Qualification::class)->newInstanceWithoutConstructor();
        $select = new \ReflectionMethod($qualification, 'controllerEvents');
        $context = ['exception' => ['type' => 'RuntimeException', 'code' => 3199, 'message' => 'J1 controlled failure',
            'previous' => ['type' => 'LogicException', 'code' => 0, 'message' => '[REDACTED]', 'previous' => null]],
            'http_method' => 'GET', 'uri' => '/__j1/failure'];
        $formatter = new TextFormatter();
        $unrelated = $formatter->format(new LogRecord(LogLevel::WARNING, 'Authorization trace', $context));
        $event = $formatter->format(new LogRecord(LogLevel::CRITICAL, 'Unhandled controller exception.', $context));
        $line = "[apache] Got error 'PHP message: $unrelated; PHP message: $event'";
        self::assertSame([$context], $select->invoke($qualification, $line));
        self::assertSame([$context, $context], $select->invoke($qualification, $line . '; PHP message: ' . $event));
        self::assertSame([], $select->invoke($qualification, $unrelated));
        self::assertSame([], $select->invoke($qualification, str_replace('[critical]', '[warning]', $event)));
        self::assertSame([], $select->invoke($qualification, substr($event, 0, -1)));
    }

    #[Test]
    public function public_records_redact_the_canonical_app_secret_and_private_fixture_without_hiding_names(): void
    {
        $root = sys_get_temp_dir() . '/j1-secrets-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir($root . '/apps/dev');
        $fs->dumpFile($root . '/apps/dev/.env', "WAASEYAA_APP_SECRET=base64:canonical-synthetic-secret\n");
        $fs->dumpFile($root . '/apps/dev/.env.local', "WAASEYAA_APP_SECRET=base64:canonical/secret+\n");
        try {
            $qualification = new \ReflectionClass(\PublishedS1Qualification::class)->newInstanceWithoutConstructor();
            new \ReflectionProperty($qualification, 'work')->setValue($qualification, $root);
            $safe = new \ReflectionMethod($qualification, 'safe');
            self::assertSame('WAASEYAA_APP_SECRET [REDACTED] [REDACTED]', $safe->invoke(
                $qualification,
                'WAASEYAA_APP_SECRET base64:canonical-synthetic-secret synthetic-j1-private-payload',
            ));
            self::assertSame('{"fields":{"node|page|body":"public:definition"}}', $safe->invoke(
                $qualification,
                '{"fields":{"node|page|body":"public:definition"}}',
            ));
            self::assertSame('{"canary":"[REDACTED]"}', $safe->invoke(
                $qualification,
                json_encode(['canary' => 'base64:canonical/secret+'], JSON_THROW_ON_ERROR),
            ));
        } finally {
            $fs->remove($root);
        }
    }

    #[Test]
    public function qualification_budget_includes_the_workflow_prelude(): void
    {
        $root = sys_get_temp_dir() . '/j1-budget-' . bin2hex(random_bytes(8));
        $started = time() - 600;
        $environment = ['GITHUB_RUN_ID' => '3199', 'RUNNER_TEMP' => $root,
            'J1_FRAMEWORK_VERSION' => '0.1.0-alpha.308', 'J1_EVIDENCE' => $root . '/public', 'J1_JOB_STARTED_AT' => (string) $started];
        $original = [];
        foreach ($environment as $key => $value) {
            $original[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $qualification = new \PublishedS1Qualification();
            self::assertLessThanOrEqual($started + 30 * 60, new \ReflectionProperty($qualification, 'deadline')->getValue($qualification));
        } finally {
            foreach ($original as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function an_unconfirmed_container_launch_remains_owned_for_custody(): void
    {
        $qualification = new \ReflectionClass(\PublishedS1Qualification::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty($qualification, 'work')->setValue($qualification, '/unstarted-j1');
        new \ReflectionProperty($qualification, 'deadline')->setValue($qualification, microtime(true) - 1);
        try {
            new \ReflectionMethod($qualification, 'profile')->invoke($qualification, 'dev');
            self::fail('An expired qualification launched a container.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Qualification/custody deadline reached', $failure->getMessage());
        }
        self::assertCount(1, new \ReflectionProperty($qualification, 'containers')->getValue($qualification));
    }

    #[Test]
    public function child_refusal_and_timeout_preserve_original_streams_and_fail_closed(): void
    {
        $root = sys_get_temp_dir() . '/j1-command-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir([$root . '/raw', $root . '/public']);
        try {
            $qualification = new \ReflectionClass(\PublishedS1Qualification::class)->newInstanceWithoutConstructor();
            foreach (['work' => $root, 'evidence' => $root . '/public', 'fs' => $fs, 'deadline' => microtime(true) + 15] as $property => $value) {
                new \ReflectionProperty($qualification, $property)->setValue($qualification, $value);
            }
            $run = new \ReflectionMethod($qualification, 'run');
            ob_start();
            try {
                $success = $run->invoke($qualification, 'success', [PHP_BINARY, '-r', 'echo "ready";']);
                self::assertSame([0, 'ready', ''], $success);
                try {
                    $run->invoke($qualification, 'refusal', [PHP_BINARY, '-r', 'fwrite(STDOUT, "synthetic-j1-private-payload"); fwrite(STDERR, "refused"); exit(7);']);
                    self::fail('A refused child was accepted.');
                } catch (\RuntimeException $failure) {
                    self::assertSame('refusal exited 7', $failure->getMessage());
                }
                try {
                    $run->invoke($qualification, 'timeout', [PHP_BINARY, '-r', 'echo "before timeout"; sleep(5);'], null, true, 1);
                    self::fail('A timed out child was accepted.');
                } catch (\RuntimeException $failure) {
                    self::assertSame('timeout exited 124', $failure->getMessage());
                }
            } finally {
                ob_end_clean();
            }
            self::assertSame('synthetic-j1-private-payload', file_get_contents($root . '/raw/refusal.stdout'));
            self::assertSame('[REDACTED]', file_get_contents($root . '/public/refusal.stdout'));
            self::assertSame('refused', file_get_contents($root . '/raw/refusal.stderr'));
            self::assertSame('before timeout', file_get_contents($root . '/raw/timeout.stdout'));
            $steps = json_decode(file_get_contents($root . '/public/steps.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([0, 7, 124], array_column($steps, 'exit'));
            self::assertStringNotContainsString('synthetic-j1-private-payload', file_get_contents($root . '/public/steps.json'));
        } finally {
            $fs->remove($root);
        }
    }

    #[Test]
    public function the_serving_lane_requires_explicit_manual_selection_and_only_reads_repository_contents(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/skeleton-smoke.yml');
        $inputs = $workflow['on']['workflow_dispatch']['inputs'];
        self::assertSame('boolean', $inputs['j1_published_s1']['type']);
        self::assertFalse($inputs['j1_published_s1']['default']);
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        $job = $workflow['jobs']['published-j1-s1'];
        self::assertSame("github.event_name == 'workflow_dispatch' && inputs.j1_published_s1", $job['if']);
        self::assertSame('ubuntu-24.04', $job['runs-on']);
        self::assertStringContainsString('!inputs.j1_published_s1', $workflow['jobs']['smoke']['if']);
        $upload = $job['steps'][array_key_last($job['steps'])];
        self::assertSame('${{ runner.temp }}/j1-s1-evidence/', $upload['with']['path']);
        self::assertSame('always()', $upload['if']);
    }
}
