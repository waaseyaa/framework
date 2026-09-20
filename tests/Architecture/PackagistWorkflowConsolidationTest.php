<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class PackagistWorkflowConsolidationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function workflows_delegate_submission_and_verification_to_shared_composite_actions(): void
    {
        $submitAction = $this->read('.github/actions/packagist-submit/action.yml');
        $verifyAction = $this->read('.github/actions/packagist-verify/action.yml');

        self::assertStringContainsString('using: composite', $submitAction);
        self::assertStringContainsString('using: composite', $verifyAction);
        self::assertStringContainsString('dry-run:', $submitAction);
        self::assertStringContainsString('dry-run:', $verifyAction);

        foreach (['packagist-submit', 'packagist-verify'] as $action) {
            $metadata = Yaml::parseFile($this->repoRoot . "/.github/actions/{$action}/action.yml");
            self::assertIsArray($metadata);
            self::assertSame('composite', $metadata['runs']['using'] ?? null);
            self::assertNotEmpty($metadata['runs']['steps'] ?? []);
        }

        foreach (
            [
                '.github/workflows/split.yml' => 'uses: ./.github/actions/packagist-submit',
                '.github/workflows/packagist-register.yml' => 'uses: ./.github/actions/packagist-submit',
                '.github/workflows/packagist-recover.yml' => 'uses: ./.github/actions/packagist-submit',
                '.github/workflows/sync-skeleton.yml' => 'uses: ./framework/.github/actions/packagist-submit',
            ] as $workflow => $actionReference
        ) {
            self::assertStringContainsString(
                $actionReference,
                $this->read($workflow),
                $workflow,
            );
        }

        foreach (
            [
                '.github/workflows/split.yml',
                '.github/workflows/packagist-update.yml',
                '.github/workflows/github-release.yml',
            ] as $workflow
        ) {
            self::assertStringContainsString(
                'uses: ./.github/actions/packagist-verify',
                $this->read($workflow),
                $workflow,
            );
        }
    }

    #[Test]
    public function packagist_endpoints_and_disabled_webhook_guidance_have_single_implementations(): void
    {
        $sources = [];
        foreach (
            [
                '.github/actions/packagist-submit/action.yml',
                '.github/actions/packagist-submit/submit.sh',
                '.github/actions/packagist-verify/action.yml',
                '.github/actions/packagist-verify/verify.sh',
                '.github/actions/packagist-verify/packagist-lib.sh',
                '.github/workflows/split.yml',
                '.github/workflows/packagist-register.yml',
                '.github/workflows/packagist-recover.yml',
                '.github/workflows/sync-skeleton.yml',
                '.github/workflows/packagist-update.yml',
                '.github/workflows/github-release.yml',
            ] as $path
        ) {
            $sources[] = $this->read($path);
        }
        $combined = implode("\n", $sources);

        self::assertSame(1, substr_count($combined, 'https://packagist.org/api/update-package'));
        self::assertSame(1, substr_count($combined, 'https://packagist.org/api/create-package'));
        self::assertSame(1, substr_count($combined, 'https://repo.packagist.org/p2/'));
        self::assertSame(1, substr_count($combined, "packagist_error 'The Packagist push webhooks are disabled"));
    }

    #[Test]
    public function each_submitter_preserves_its_create_and_recovery_policy(): void
    {
        $split = $this->read('.github/workflows/split.yml');
        $register = $this->read('.github/workflows/packagist-register.yml');
        $recover = $this->read('.github/workflows/packagist-recover.yml');
        $skeleton = $this->read('.github/workflows/sync-skeleton.yml');

        self::assertMatchesRegularExpression('/packagist-submit.*?allow-create:\s*true/s', $split);
        self::assertMatchesRegularExpression('/packagist-submit.*?allow-create:\s*true/s', $register);
        self::assertMatchesRegularExpression('/packagist-submit.*?allow-create:\s*false/s', $recover);
        self::assertMatchesRegularExpression('/packagist-submit.*?mode:\s*recovery/s', $recover);
        self::assertMatchesRegularExpression('/packagist-submit.*?allow-create:\s*false/s', $skeleton);
        self::assertMatchesRegularExpression('/permissions:\s*contents:\s*read/s', $register);
    }

    #[Test]
    public function standalone_verification_is_manual_only(): void
    {
        $workflow = $this->read('.github/workflows/packagist-update.yml');
        $trigger = substr($workflow, 0, (int) strpos($workflow, 'permissions:'));

        self::assertStringContainsString('workflow_dispatch:', $trigger);
        self::assertStringNotContainsString('push:', $trigger);
    }

    #[Test]
    public function submission_dry_run_validates_inputs_without_credentials_or_network(): void
    {
        $process = $this->runSubmit([
            'PACKAGIST_PACKAGES' => 'waaseyaa/framework, waaseyaa/foundation',
            'PACKAGIST_ALLOW_CREATE' => 'true',
        ]);

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('DRY RUN', $process->getOutput());
        self::assertStringContainsString('waaseyaa/framework', $process->getOutput());
        self::assertStringContainsString('waaseyaa/foundation', $process->getOutput());
        self::assertStringNotContainsString('visible at', $process->getOutput());
    }

    #[Test]
    public function recovery_dry_run_validates_without_claiming_publication(): void
    {
        $process = $this->runSubmit([
            'PACKAGIST_PACKAGES' => 'waaseyaa/foundation',
            'PACKAGIST_ALLOW_CREATE' => 'false',
            'PACKAGIST_MODE' => 'recovery',
            'PACKAGIST_TAG' => 'v1.2.3-alpha.4',
            'PACKAGIST_MAX_ATTEMPTS' => '3',
        ]);

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('recovery inputs and submission plan validated', $process->getOutput());
        self::assertStringNotContainsString('All requested packages are visible', $process->getOutput());
    }

    #[Test]
    public function dry_run_rejects_unsafe_package_names(): void
    {
        $process = $this->runSubmit([
            'PACKAGIST_PACKAGES' => 'waaseyaa/../../outside',
        ]);

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('package must match waaseyaa/<name>', $process->getOutput());
    }

    #[Test]
    public function recovery_rejects_unbounded_attempt_counts(): void
    {
        $process = $this->runSubmit([
            'PACKAGIST_PACKAGES' => 'waaseyaa/foundation',
            'PACKAGIST_MODE' => 'recovery',
            'PACKAGIST_TAG' => 'v1.2.3',
            'PACKAGIST_MAX_ATTEMPTS' => '100',
        ]);

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('max-attempts must be between 1 and 99', $process->getOutput());
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->repoRoot . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }

    private function bash(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        $gitBash = 'C:/Program Files/Git/bin/bash.exe';
        self::assertFileExists($gitBash, 'Tests on Windows must use Git for Windows Bash, not WSL Bash.');

        return $gitBash;
    }

    /**
     * @param array<string, string> $environment
     */
    private function runSubmit(array $environment): Process
    {
        $process = new Process(
            [
                $this->bash(),
                str_replace('\\', '/', $this->repoRoot . '/.github/actions/packagist-submit/submit.sh'),
            ],
            $this->repoRoot,
            array_merge(
                [
                    'PACKAGIST_REPOSITORY_OWNER' => 'waaseyaa',
                    'PACKAGIST_ALLOW_CREATE' => 'false',
                    'PACKAGIST_DRY_RUN' => 'true',
                    'PACKAGIST_MODE' => 'submit',
                    'PACKAGIST_SPACING_MIN_SECONDS' => '0',
                    'PACKAGIST_SPACING_MAX_SECONDS' => '0',
                ],
                $environment,
            ),
        );
        $process->run();

        return $process;
    }
}
