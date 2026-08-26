<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReleaseEvidenceTest extends TestCase
{
    private string $repoRoot = '';
    private string $temporaryRoot = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->temporaryRoot = sys_get_temp_dir() . '/waaseyaa_release_evidence_' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryRoot)) {
            exec('rm -rf ' . escapeshellarg($this->temporaryRoot));
        }
    }

    #[Test]
    public function release_evidence_is_complete_and_byte_deterministic(): void
    {
        $splitDirectory = $this->temporaryRoot . '/splits';
        mkdir($splitDirectory, 0o700, true);
        $sourceSha = str_repeat('a', 40);
        $this->writeCompleteSplitRecords($splitDirectory, $sourceSha);

        $first = $this->temporaryRoot . '/first';
        $second = $this->temporaryRoot . '/second';
        self::assertSame(0, $this->generate($splitDirectory, $first, $sourceSha));
        self::assertSame(0, $this->generate($splitDirectory, $second, $sourceSha));

        foreach (['waaseyaa-framework.cdx.json', 'waaseyaa-framework-provenance.json', 'SHA256SUMS'] as $file) {
            self::assertFileExists($first . '/' . $file);
            self::assertSame(file_get_contents($first . '/' . $file), file_get_contents($second . '/' . $file));
        }

        $sbom = json_decode((string) file_get_contents($first . '/waaseyaa-framework.cdx.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('CycloneDX', $sbom['bomFormat']);
        self::assertSame('1.6', $sbom['specVersion']);
        foreach ($sbom['components'] as $component) {
            foreach ($component['properties'] ?? [] as $property) {
                self::assertTrue(
                    !array_key_exists('value', $property) || is_string($property['value']),
                    sprintf('%s: CycloneDX property %s has a non-string value', $component['purl'], $property['name']),
                );
            }
        }
        $purls = array_column($sbom['components'], 'purl');

        $composerLock = json_decode((string) file_get_contents($this->repoRoot . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge($composerLock['packages'], $composerLock['packages-dev']) as $package) {
            self::assertContains('pkg:composer/' . $package['name'] . '@' . rawurlencode($package['version']), $purls);
        }

        $npmLock = json_decode((string) file_get_contents($this->repoRoot . '/packages/admin/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($npmLock['packages'] as $path => $package) {
            if ($path === '' || !isset($package['version'])) {
                continue;
            }
            $name = $package['name'] ?? preg_replace('#^.*node_modules/#', '', $path);
            self::assertContains('pkg:npm/' . str_replace('%2F', '/', rawurlencode((string) $name)) . '@' . rawurlencode((string) $package['version']), $purls);
        }

        $provenance = json_decode((string) file_get_contents($first . '/waaseyaa-framework-provenance.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($sourceSha, $provenance['source']['sha']);
        self::assertSame(hash_file('sha256', $this->repoRoot . '/composer.lock'), $provenance['locks']['composer']['sha256']);
        self::assertSame(hash_file('sha256', $this->repoRoot . '/packages/admin/package-lock.json'), $provenance['locks']['npm']['sha256']);
        self::assertCount(count(glob($this->repoRoot . '/packages/*/composer.json')), $provenance['packages']);
    }

    #[Test]
    public function release_evidence_refuses_an_incomplete_split_set(): void
    {
        $splitDirectory = $this->temporaryRoot . '/splits';
        mkdir($splitDirectory, 0o700, true);
        $sourceSha = str_repeat('b', 40);
        $this->writeCompleteSplitRecords($splitDirectory, $sourceSha);
        $splitRecords = glob($splitDirectory . '/*.json');
        self::assertNotFalse($splitRecords);
        self::assertNotEmpty($splitRecords);
        unlink($splitRecords[0]);

        self::assertSame(1, $this->generate($splitDirectory, $this->temporaryRoot . '/out', $sourceSha));
    }

    #[Test]
    public function every_external_workflow_action_is_immutably_selected(): void
    {
        $ymlWorkflows = glob($this->repoRoot . '/.github/workflows/*.yml');
        $yamlWorkflows = glob($this->repoRoot . '/.github/workflows/*.yaml');
        $workflows = array_merge(
            $ymlWorkflows === false ? [] : $ymlWorkflows,
            $yamlWorkflows === false ? [] : $yamlWorkflows,
        );

        $externalUses = 0;
        foreach ($workflows as $workflow) {
            $contents = (string) file_get_contents($workflow);
            preg_match_all('/^\s*-?\s*uses:\s*([^\s#]+)(?:\s+#\s*(.+))?$/m', $contents, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (str_starts_with($match[1], './')) {
                    continue;
                }
                ++$externalUses;
                self::assertMatchesRegularExpression('#^[^@\s]+@[0-9a-f]{40}$#', $match[1], $workflow . ': ' . $match[1]);
                self::assertNotSame('', trim($match[2] ?? ''), $workflow . ': immutable action pins require a readable version comment.');
            }
        }

        self::assertGreaterThan(0, $externalUses);
    }

    #[Test]
    public function release_publication_requires_and_attaches_the_complete_evidence_set(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/split.yml');

        self::assertStringContainsString('assemble-release-evidence:', $workflow);
        self::assertMatchesRegularExpression('/publish-github-release:.*?needs:\s*\[[^\]]*assemble-release-evidence/s', $workflow);
        self::assertStringContainsString('name: waaseyaa-release-evidence', $workflow);
        self::assertStringContainsString('waaseyaa-framework.cdx.json', $workflow);
        self::assertStringContainsString('waaseyaa-framework-provenance.json', $workflow);
        self::assertStringContainsString('SHA256SUMS', $workflow);
        self::assertStringContainsString('files: release-evidence/*', $workflow);
        self::assertSame(2, substr_count($workflow, 'overwrite: true'));

        $manualWorkflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/github-release.yml');
        self::assertStringContainsString('evidence_run_id:', $manualWorkflow);
        self::assertStringContainsString('split_run_id:', $manualWorkflow);
        self::assertStringContainsString('actions/download-artifact@', $manualWorkflow);
        self::assertStringContainsString('name: waaseyaa-release-evidence', $manualWorkflow);
        self::assertStringContainsString('run-id: ${{ inputs.evidence_run_id }}', $manualWorkflow);
        self::assertStringContainsString('pattern: split-provenance-*', $manualWorkflow);
        self::assertStringContainsString('run-id: ${{ inputs.split_run_id }}', $manualWorkflow);
        self::assertStringContainsString('bin/generate-release-evidence', $manualWorkflow);
        self::assertStringContainsString('repo.packagist.org/p2/${package}.json', $manualWorkflow);
        self::assertStringContainsString('github-token: ${{ github.token }}', $manualWorkflow);
        self::assertStringContainsString('Verify retained release evidence', $manualWorkflow);
        self::assertStringContainsString('files: release-evidence/*', $manualWorkflow);
        self::assertStringContainsString('fail_on_unmatched_files: true', $manualWorkflow);

        $ciWorkflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertMatchesRegularExpression('/release-evidence-dry-run-.*?overwrite:\s*true/s', $ciWorkflow);
    }

    #[Test]
    public function new_packagist_packages_use_main_token_auth_without_skipping_release_bookkeeping(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/split.yml');

        self::assertStringContainsString('PACKAGIST_MAIN_TOKEN: ${{ secrets.PACKAGIST_MAIN_TOKEN }}', $workflow);
        self::assertStringContainsString('"https://packagist.org/api/create-package"', $workflow);
        self::assertStringContainsString('-H "Authorization: Bearer ${PACKAGIST_USERNAME}:${PACKAGIST_MAIN_TOKEN}"', $workflow);
        self::assertStringNotContainsString('api/create-package?username=', $workflow);
        self::assertMatchesRegularExpression(
            '/verify-packagist:.*?if: \$\{\{ always\(\).*?needs\.publish-packagist\.result/s',
            $workflow,
        );
        self::assertMatchesRegularExpression(
            '/assemble-release-evidence:.*?needs:\s*\[split, verify-monorepo-main-intact, verify-tag-parity, verify-require-parity\]/s',
            $workflow,
        );
        self::assertMatchesRegularExpression(
            '/publish-github-release:.*?if: \$\{\{ always\(\) \}\}.*?Enforce release publication prerequisites/s',
            $workflow,
        );

        $manualWorkflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/packagist-register.yml');
        self::assertStringContainsString('PACKAGIST_MAIN_TOKEN: ${{ secrets.PACKAGIST_MAIN_TOKEN }}', $manualWorkflow);
        self::assertStringContainsString('-H "Authorization: Bearer ${PACKAGIST_USERNAME}:${PACKAGIST_MAIN_TOKEN}"', $manualWorkflow);
        self::assertStringNotContainsString('api/create-package?username=', $manualWorkflow);
    }

    private function writeCompleteSplitRecords(string $directory, string $sourceSha): void
    {
        foreach (glob($this->repoRoot . '/packages/*/composer.json') as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $prefix = dirname(substr($manifestPath, strlen($this->repoRoot) + 1));
            $remote = basename($prefix);
            $record = [
                'source_repository' => 'waaseyaa/framework',
                'source_sha' => $sourceSha,
                'package' => $manifest['name'],
                'prefix' => $prefix,
                'split_repository' => 'waaseyaa/' . $remote,
                'split_sha' => sha1($manifest['name']),
                'tag' => 'v0.1.0-alpha.test',
                'run_id' => '12345',
            ];
            file_put_contents($directory . '/' . $remote . '.json', json_encode($record, JSON_THROW_ON_ERROR));
        }
    }

    private function generate(string $splitDirectory, string $outputDirectory, string $sourceSha): int
    {
        $command = [
            $this->repoRoot . '/bin/generate-release-evidence',
            '--repository-root=' . $this->repoRoot,
            '--split-provenance-dir=' . $splitDirectory,
            '--output-dir=' . $outputDirectory,
            '--source-repository=waaseyaa/framework',
            '--source-sha=' . $sourceSha,
            '--tag=v0.1.0-alpha.test',
            '--builder-id=https://github.com/waaseyaa/framework/actions/workflows/split.yml',
            '--workflow-run-id=12345',
            '--composer-version=2.9.0',
            '--node-version=24.0.0',
        ];

        $escaped = implode(' ', array_map('escapeshellarg', $command));
        exec($escaped . ' 2>/dev/null', $output, $exitCode);

        return $exitCode;
    }
}
