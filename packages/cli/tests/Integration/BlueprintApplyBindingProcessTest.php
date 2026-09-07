<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\SiteManifestParser;

/** The actual CLI must preserve blueprint approval across reviewed-plan transport. */
#[CoversNothing]
final class BlueprintApplyBindingProcessTest extends TestCase
{
    private string $repoRoot;
    private string $workspace;
    private string $projectRoot;
    private string $answersPath;
    private string $receiptPath;
    private BlueprintDecisionReceipt $receipt;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 4);
        $this->workspace = sys_get_temp_dir() . '/waaseyaa_blueprint_apply_binding_' . bin2hex(random_bytes(8));
        $this->projectRoot = $this->workspace . '/project';
        mkdir($this->projectRoot, 0o700, true);
        foreach (['composer.json', 'composer.lock'] as $path) {
            file_put_contents($this->projectRoot . '/' . $path, "{}\n");
            chmod($this->projectRoot . '/' . $path, 0o644);
        }

        $this->answersPath = $this->workspace . '/answers.yaml';
        $yaml = (string) file_get_contents(
            $this->repoRoot . '/packages/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml',
        );
        $yaml = str_replace(str_repeat('a', 64), hash('sha256', "{}\n"), $yaml);
        file_put_contents($this->answersPath, $yaml);

        $manifest = new SiteManifestParser()->parse($yaml, $this->answersPath);
        self::assertNotNull($manifest->applicationBlueprint);
        $this->receipt = BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'studio:verified:operator-7',
            'decided_at' => '2026-09-07T12:00:00Z',
            'mechanism' => 'studio-project-revision-approval',
        ]);
        $this->receiptPath = $this->workspace . '/decision.json';
        file_put_contents($this->receiptPath, $this->receipt->canonicalJson() . "\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->workspace);
    }

    public function test_reviewed_blueprint_plan_is_applied_by_a_later_cli_process_with_its_exact_approval(): void
    {
        $beforePreview = $this->snapshot();
        $planned = $this->runCli([
            'site:init',
            '--json',
            '--answers=' . $this->answersPath,
            '--decision-receipt=' . $this->receiptPath,
            '--dry-run',
        ]);

        self::assertSame('planned', $planned['result']['outcome']);
        self::assertSame($beforePreview, $this->snapshot(), 'Blueprint preview must not change the target project.');

        $request = ArtifactApplyRequest::fromArray([
            'schema' => ArtifactApplyRequest::SCHEMA_ID,
            'version' => ArtifactApplyRequest::CONTRACT_VERSION,
            'plan' => $planned['evaluation']['plan'],
            'plan_digest' => $planned['evaluation']['plan_digest'],
            'project_state_digest' => $planned['evaluation']['project_state_digest'],
        ], '<studio-reviewed-apply-request>');
        self::assertSame($planned['evaluation']['plan_digest'], $request->planDigest);
        self::assertSame($planned['evaluation']['project_state_digest'], $request->projectStateDigest);
        self::assertSame(
            ['schema', 'version', 'plan', 'plan_digest', 'project_state_digest'],
            array_keys($request->toArray()),
            'The approval remains a separate invocation input, not trusted data embedded in the apply request.',
        );
        $requestPath = $this->workspace . '/reviewed-request.json';
        file_put_contents($requestPath, $request->canonicalJson() . "\n");

        $applied = $this->runCli([
            'site:apply',
            '--request=' . $requestPath,
            '--decision-receipt=' . $this->receiptPath,
            '--json',
        ]);

        self::assertSame('applied', $applied['result']['outcome']);
        self::assertSame($request->planDigest, $applied['result']['plan_digest']);
        self::assertSame($request->projectStateDigest, $applied['result']['project_state_digest']);
        self::assertSame($this->receipt->digest(), $applied['receipts'][0]['decision_receipt_id']);
        self::assertSame([], $applied['errors']);

        $evidence = json_decode(
            (string) file_get_contents($this->projectRoot . '/.waaseyaa/generated.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            json_decode($this->receipt->canonicalJson(), true, flags: JSON_THROW_ON_ERROR),
            $evidence['application_blueprint']['decision_receipt'],
            'The published ownership evidence must retain the exact separately supplied approval.',
        );

        $projection = [
            'decision_receipt_matches' => $applied['receipts'][0]['decision_receipt_id'] === $this->receipt->digest(),
            'errors' => $applied['errors'],
            'outcome' => $applied['result']['outcome'],
            'plan_digest_matches' => $applied['result']['plan_digest'] === $request->planDigest,
            'project_state_digest_matches' => $applied['result']['project_state_digest'] === $request->projectStateDigest,
        ];
        self::assertSame(
            (string) file_get_contents(__DIR__ . '/../Fixtures/SiteInit/BlueprintApplyBinding/applied-contract.json'),
            CanonicalJson::encode($projection) . "\n",
        );
    }

    /** @param list<string> $arguments @return array<string, mixed> */
    private function runCli(array $arguments): array
    {
        $process = new Process(
            [PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', ...$arguments, '--project-root=' . $this->projectRoot],
            $this->repoRoot,
            ['APP_ENV' => 'local', 'WAASEYAA_DB' => $this->projectRoot . '/probe.sqlite'],
            timeout: 30,
        );
        self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{sha256: string, mode: int}> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $snapshot[substr($file->getPathname(), strlen($this->projectRoot) + 1)] = [
                'sha256' => hash_file('sha256', $file->getPathname()),
                'mode' => $file->getPerms() & 0o777,
            ];
        }
        ksort($snapshot);

        return $snapshot;
    }
}
