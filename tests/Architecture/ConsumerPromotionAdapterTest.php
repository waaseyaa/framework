<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Disposable proofs that the consumer-promotion adapter reads promote.yml
 * workflow_dispatch runs only and never treats PR merges or push deploys as
 * production deployments.
 */
#[CoversNothing]
final class ConsumerPromotionAdapterTest extends TestCase
{
    private const SCRIPT = 'bin/adapt-consumer-promotions';
    private const SECRET = 'ghs_fixture-token-must-not-print';
    private const REVISION = '0888d56e011483b84aacf8c3fc84de8b231b0b79';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function successful_promote_yml_run_is_a_sample_eligible_production_promotion(): void
    {
        $result = $this->adapt($this->document([
            $this->promoteRun(),
        ]));

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $decoded = $this->decode($result);
        self::assertSame('waaseyaa.consumer-promotion-adapter.v1', $decoded['schema']);
        self::assertSame(1, $decoded['sample_size']);
        self::assertCount(1, $decoded['promotions']);
        self::assertSame([], $decoded['rejected']);
        $promotion = $decoded['promotions'][0];
        self::assertTrue($promotion['sample_eligible']);
        self::assertSame('northway', $promotion['target']);
        self::assertSame('production', $promotion['environment']);
        self::assertSame(self::REVISION, $promotion['revision']);
        self::assertSame('Deploy bounded retention and persistence health', $promotion['reason']);
        self::assertSame('success', $promotion['conclusion']);
        self::assertSame('.github/workflows/promote.yml', $promotion['workflow_path']);
        self::assertSame('workflow_dispatch', $promotion['event']);
        self::assertSame(33474562592, $promotion['run_id']);
        self::assertSame('2026-09-01T05:41:22Z', $promotion['started_at']);
        self::assertSame('2026-09-01T05:45:28Z', $promotion['finished_at']);
        $this->assertNoSecrets($result);
    }

    #[Test]
    public function push_deploy_workflow_and_pr_merge_payloads_are_rejected(): void
    {
        $pushPromote = $this->promoteRun();
        $pushPromote['event'] = 'push';
        $result = $this->adapt($this->document([
            $this->pushDeployRun(),
            $this->prMergeRun(),
            $pushPromote,
        ]));

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $decoded = $this->decode($result);
        self::assertSame(0, $decoded['sample_size']);
        self::assertSame([], $decoded['promotions']);
        self::assertCount(3, $decoded['rejected']);
        self::assertSame('workflow_path_not_promote', $decoded['rejected'][0]['code']);
        self::assertSame('workflow_path_not_promote', $decoded['rejected'][1]['code']);
        self::assertSame('event_not_workflow_dispatch', $decoded['rejected'][2]['code']);
        $this->assertNoSecrets($result);
    }

    #[Test]
    public function failed_promote_is_recorded_but_not_sample_eligible(): void
    {
        $result = $this->adapt($this->document([
            $this->promoteRun(conclusion: 'failure'),
        ]));

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $decoded = $this->decode($result);
        self::assertSame(0, $decoded['sample_size']);
        self::assertCount(1, $decoded['promotions']);
        self::assertFalse($decoded['promotions'][0]['sample_eligible']);
        self::assertSame('failure', $decoded['promotions'][0]['conclusion']);
        $this->assertNoSecrets($result);
    }

    #[Test]
    public function malformed_title_short_sha_and_head_mismatch_are_rejected(): void
    {
        $result = $this->adapt($this->document([
            $this->promoteRun(title: 'Promote northway to production'),
            $this->promoteRun(revision: '0888d56e0114', titleRevision: '0888d56e0114'),
            $this->promoteRun(headSha: str_repeat('a', 40)),
        ]));

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $decoded = $this->decode($result);
        self::assertSame([], $decoded['promotions']);
        $codes = array_column($decoded['rejected'], 'code');
        self::assertContains('display_title_unparseable', $codes);
        self::assertContains('revision_not_exact_sha', $codes);
        self::assertContains('revision_mismatch_head_sha', $codes);
        $this->assertNoSecrets($result);
    }

    #[Test]
    public function credential_free_self_test_covers_promote_reject_and_ineligible_cases(): void
    {
        $result = $this->runProcess([PHP_BINARY, $this->root . '/' . self::SCRIPT, '--self-test']);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('PASS', $result['stdout']);
        self::assertStringContainsString('promote', strtolower($result['stdout']));
        self::assertStringContainsString('reject', strtolower($result['stdout']));
        self::assertStringContainsString('ineligible', strtolower($result['stdout']));
        $this->assertNoSecrets($result);
    }

    #[Test]
    public function the_adapter_is_read_only_json_normalization(): void
    {
        $source = (string) file_get_contents($this->root . '/' . self::SCRIPT);

        self::assertStringContainsString('.github/workflows/promote.yml', $source);
        self::assertStringContainsString('workflow_dispatch', $source);
        self::assertStringNotContainsString('curl_exec', $source);
        self::assertStringNotContainsString('file_get_contents(\'https', $source);
        self::assertStringNotContainsString('grafana', $source);
        self::assertStringNotContainsString('INSERT', $source);
        self::assertStringNotContainsString('UPDATE', $source);
    }

    /**
     * @param array<string, mixed> $document
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function adapt(array $document): array
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-consumer-promotion-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $input = $directory . '/runs.json';
        file_put_contents($input, json_encode($document, JSON_THROW_ON_ERROR));

        return $this->runProcess(
            [PHP_BINARY, $this->root . '/' . self::SCRIPT, '--input=' . $input],
            ['GH_TOKEN' => self::SECRET],
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, array $env = []): array
    {
        $process = new Process($command, $this->root, $env + ['GH_TOKEN' => self::SECRET]);
        $process->run();

        return ['exit' => $process->getExitCode() ?? 1, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    /** @param array{exit: int, stdout: string, stderr: string} $result */
    private function assertNoSecrets(array $result): void
    {
        $output = $result['stdout'] . $result['stderr'];
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertStringNotContainsString('GH_TOKEN', $output);
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $result
     * @return array{schema: string, sample_size: int, promotions: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    private function decode(array $result): array
    {
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array{repository: string, runs: list<array<string, mixed>>}
     */
    private function document(array $runs): array
    {
        return [
            'repository' => 'jonesrussell/waaseyaa-infra',
            'runs' => $runs,
        ];
    }

    /** @return array<string, mixed> */
    private function promoteRun(
        string $conclusion = 'success',
        ?string $title = null,
        string $revision = self::REVISION,
        string $titleRevision = self::REVISION,
        string $headSha = self::REVISION,
    ): array {
        $title ??= 'Promote northway to production at ' . $titleRevision . ' — Deploy bounded retention and persistence health';

        return [
            'id' => 33474562592,
            'path' => '.github/workflows/promote.yml',
            'event' => 'workflow_dispatch',
            'name' => $title,
            'display_title' => $title,
            'head_sha' => $headSha,
            'conclusion' => $conclusion,
            'status' => 'completed',
            'html_url' => 'https://github.com/jonesrussell/waaseyaa-infra/actions/runs/33474562592',
            'created_at' => '2026-09-01T05:41:22Z',
            'updated_at' => '2026-09-01T05:45:28Z',
            'run_started_at' => '2026-09-01T05:41:22Z',
            'triggering_actor' => ['login' => 'jonesrussell'],
        ];
    }

    /** @return array<string, mixed> */
    private function pushDeployRun(): array
    {
        return [
            'id' => 1,
            'path' => '.github/workflows/deploy-oiatc.yml',
            'event' => 'push',
            'name' => 'Deploy oiatc-app',
            'display_title' => 'Deploy oiatc-app',
            'head_sha' => self::REVISION,
            'conclusion' => 'success',
            'status' => 'completed',
            'html_url' => 'https://github.com/jonesrussell/waaseyaa-infra/actions/runs/1',
            'created_at' => '2026-04-20T12:00:00Z',
            'updated_at' => '2026-04-20T12:05:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function prMergeRun(): array
    {
        return [
            'id' => 5827240642,
            'path' => '.github/workflows/release.yml',
            'event' => 'push',
            'name' => 'Merge pull request #2331 from waaseyaa/feat-example',
            'display_title' => 'Merge pull request #2331 from waaseyaa/feat-example',
            'head_sha' => self::REVISION,
            'conclusion' => 'success',
            'status' => 'completed',
            'html_url' => 'https://github.com/waaseyaa/framework/actions/runs/5827240642',
            'created_at' => '2026-08-10T06:21:00Z',
            'updated_at' => '2026-08-10T06:22:00Z',
        ];
    }
}
