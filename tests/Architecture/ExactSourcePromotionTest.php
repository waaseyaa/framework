<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ExactSourcePromotionTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            if (is_dir($root)) {
                new Filesystem()->remove($root);
            }
        }
    }

    #[Test]
    public function it_promotes_one_green_exact_head_ci_artifact_with_provenance(): void
    {
        [$root, $sha] = $this->fixtureRepository();

        $result = $this->promote($root, $sha, 'release-readiness');

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('exact source artifact promoted', $result['output']);
        $handoff = json_decode((string) file_get_contents($root . '/promoted/handoff.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(1, $handoff['schema_version']);
        self::assertSame('framework-exact-source-promotion', $handoff['kind']);
        self::assertSame($sha, $handoff['source_sha']);
        self::assertSame('ci.yml', $handoff['producer_workflow']);
        self::assertSame(4242, $handoff['producer_run_id']);
        self::assertSame('https://example.test/runs/4242', $handoff['producer_run_url']);
        self::assertSame('framework-source-' . $sha, $handoff['source_artifact_name']);
        self::assertSame(9001, $handoff['source_artifact_id']);
        self::assertSame('release-readiness', $handoff['promotion_target']);
        self::assertSame(hash_file('sha256', $root . '/promoted/framework-source.tar'), $handoff['archive_sha256']);
    }

    #[Test]
    public function it_refuses_missing_wrong_head_expired_ambiguous_and_tampered_evidence(): void
    {
        foreach ([
            'missing-run' => 'no completed successful ci.yml run',
            'run-api-failure' => 'could not query ci.yml runs',
            'wrong-head' => 'run does not bind the requested SHA',
            'expired' => 'source artifact is expired',
            'ambiguous' => 'expected exactly one source artifact',
            'artifact-api-failure' => 'could not query producing run artifacts',
            'tampered' => 'archive byte count mismatch',
        ] as $mode => $message) {
            [$root, $sha] = $this->fixtureRepository($mode);
            $result = $this->promote($root, $sha, 'release-readiness');

            self::assertSame(1, $result['exit_code'], $mode . "\n" . $result['output']);
            self::assertStringContainsString($message, $result['output'], $mode);
        }
    }

    /** @return array{string, string} */
    private function fixtureRepository(string $mode = 'success'): array
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('sourcepromotion', true);
        $this->fixtureRoots[] = $root;
        mkdir($root . '/bin', 0o777, true);
        mkdir($root . '/producer', 0o777, true);
        foreach ([
            'build-exact-source-artifact',
            'verify-exact-source-artifact',
            'promote-exact-source-artifact',
        ] as $script) {
            copy($this->repoRoot . '/bin/' . $script, $root . '/bin/' . $script);
            chmod($root . '/bin/' . $script, 0o755);
        }
        file_put_contents($root . '/bin/git', "#!/usr/bin/env bash\nexec /usr/bin/git \"\$@\"\n");
        chmod($root . '/bin/git', 0o755);

        $this->runCommand(['git', 'init', '--quiet'], $root);
        $this->runCommand(['git', 'config', 'user.name', 'Fixture'], $root);
        $this->runCommand(['git', 'config', 'user.email', 'fixture@example.test'], $root);
        file_put_contents($root . '/tracked.txt', "exact bytes\n");
        $this->runCommand(['git', 'add', 'tracked.txt', 'bin'], $root);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'fixture'], $root);
        $sha = trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $root)['output']);
        self::assertSame(0, $this->runCommand([$root . '/bin/build-exact-source-artifact', 'producer', $sha], $root)['exit_code']);

        $this->writeFakeGh($root, $mode);

        return [$root, $sha];
    }

    private function writeFakeGh(string $root, string $mode): void
    {
        $script = <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            case "$1:$2" in
              api:*'/runs?'*)
                case "${FAKE_MODE}" in
                  missing-run) exit 0 ;;
                  run-api-failure) exit 69 ;;
                  wrong-head) printf '4242\t0000000000000000000000000000000000000000\tcompleted\tsuccess\thttps://example.test/runs/4242\n' ;;
                  *) printf '4242\t%s\tcompleted\tsuccess\thttps://example.test/runs/4242\n' "${FAKE_SHA}" ;;
                esac
                ;;
              api:*'/artifacts?'*)
                case "${FAKE_MODE}" in
                  artifact-api-failure) exit 69 ;;
                  expired) printf '9001\tframework-source-%s\ttrue\n' "${FAKE_SHA}" ;;
                  ambiguous) printf '9001\tframework-source-%s\tfalse\n9002\tframework-source-%s\tfalse\n' "${FAKE_SHA}" "${FAKE_SHA}" ;;
                  *) printf '9001\tframework-source-%s\tfalse\n' "${FAKE_SHA}" ;;
                esac
                ;;
              run:download)
                destination=''
                while [ "$#" -gt 0 ]; do
                  if [ "$1" = '--dir' ]; then destination="$2"; shift 2; continue; fi
                  shift
                done
                mkdir -p "$destination"
                cp "${FAKE_PRODUCER}/framework-source.tar" "$destination/framework-source.tar"
                cp "${FAKE_PRODUCER}/manifest.json" "$destination/manifest.json"
                if [ "${FAKE_MODE}" = 'tampered' ]; then printf tamper >> "$destination/framework-source.tar"; fi
                ;;
              *) printf 'unexpected fake gh invocation: %s\n' "$*" >&2; exit 70 ;;
            esac
            BASH;
        file_put_contents($root . '/bin/gh', $script);
        chmod($root . '/bin/gh', 0o755);
        file_put_contents($root . '/mode', $mode);
    }

    /** @return array{exit_code: int, output: string} */
    private function promote(string $root, string $sha, string $target): array
    {
        $mode = trim((string) file_get_contents($root . '/mode'));

        return $this->runCommand(
            [$root . '/bin/promote-exact-source-artifact', $sha, 'promoted', $target],
            $root,
            [
                'PATH' => $root . '/bin:/usr/bin:/bin',
                'GITHUB_REPOSITORY' => 'fixture/framework',
                'FAKE_MODE' => $mode,
                'FAKE_SHA' => $sha,
                'FAKE_PRODUCER' => $root . '/producer',
            ],
        );
    }

    /** @param list<string> $command @param array<string, string> $environment @return array{exit_code: int, output: string} */
    private function runCommand(array $command, string $cwd, array $environment = []): array
    {
        $process = new Process($command, $cwd, $environment === [] ? null : $environment, null, 30);
        $exitCode = $process->run();

        return [
            'exit_code' => $exitCode,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
