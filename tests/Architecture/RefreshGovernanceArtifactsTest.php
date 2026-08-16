<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavior of bin/refresh-governance-artifacts (docs/specs/governed-gates.md
 * §2): one command repairs every governed recorded artifact — regenerating
 * mechanical ones via their verifiers' own write modes, printing the exact
 * instruction for judgment ones, and never touching artifacts whose gate
 * already passes.
 */
#[CoversNothing]
final class RefreshGovernanceArtifactsTest extends TestCase
{
    private string $root;
    private string $manifestPath = '';
    private string $stateDir = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->stateDir = sys_get_temp_dir() . '/waaseyaa_refresh_' . uniqid('', true);
        mkdir($this->stateDir, 0o755, true);
        $this->manifestPath = $this->stateDir . '/manifest.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->stateDir)) {
            rmdir($this->stateDir);
        }
    }

    /** @param list<array<string, mixed>> $gates */
    private function runRefresh(array $gates): array
    {
        file_put_contents($this->manifestPath, json_encode([
            'schema_version' => 1,
            'gates' => $gates,
        ], JSON_THROW_ON_ERROR));

        exec(sprintf(
            'php %s --manifest=%s 2>&1',
            escapeshellarg($this->root . '/bin/refresh-governance-artifacts'),
            escapeshellarg($this->manifestPath),
        ), $output, $exitCode);

        return [$output, $exitCode];
    }

    #[Test]
    public function failing_auto_artifact_is_regenerated_via_its_write_command(): void
    {
        $marker = $this->stateDir . '/regenerated.marker';
        // A realistic repairable gate: fails while the artifact is stale
        // (marker missing), and the write mode genuinely repairs it.
        [$output, $exitCode] = $this->runRefresh([[
            'id' => 'auto-gate',
            'run' => 'test -f ' . escapeshellarg($marker),
            'repair' => 'n/a',
            'profile' => 'default',
            'enforced_by' => 'workflow:ci.yml',
            'refresh' => ['mode' => 'auto', 'write' => 'touch ' . escapeshellarg($marker)],
        ]]);

        $this->assertFileExists($marker, implode("\n", $output));
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('auto-gate', implode("\n", $output));
    }

    #[Test]
    public function passing_gate_is_left_untouched(): void
    {
        $marker = $this->stateDir . '/should-not-exist.marker';
        [$output, $exitCode] = $this->runRefresh([[
            'id' => 'green-gate',
            'run' => 'true',
            'repair' => 'n/a',
            'profile' => 'default',
            'enforced_by' => 'workflow:ci.yml',
            'refresh' => ['mode' => 'auto', 'write' => 'touch ' . escapeshellarg($marker)],
        ]]);

        $this->assertFileDoesNotExist($marker, 'A clean gate must be a refresh no-op.');
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function failing_manual_artifact_prints_the_instruction_and_exits_nonzero(): void
    {
        [$output, $exitCode] = $this->runRefresh([[
            'id' => 'manual-gate',
            'run' => 'false',
            'repair' => 'n/a',
            'profile' => 'default',
            'enforced_by' => 'workflow:ci.yml',
            'refresh' => ['mode' => 'manual', 'instruction' => 'hand-edit tools/example-baseline.php with a reviewed rationale'],
        ]]);

        $joined = implode("\n", $output);
        $this->assertStringContainsString('hand-edit tools/example-baseline.php', $joined);
        $this->assertSame(1, $exitCode, 'Manual artifacts still need a human; refresh must not report clean.');
    }

    #[Test]
    public function gate_without_refresh_metadata_is_skipped(): void
    {
        [$output, $exitCode] = $this->runRefresh([[
            'id' => 'plain-gate',
            'run' => 'false',
            'repair' => 'n/a',
            'profile' => 'default',
            'enforced_by' => 'workflow:ci.yml',
        ]]);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function real_manifest_declares_refresh_for_every_s1_roster(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root . '/tools/preflight-gates.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $byId = [];
        foreach ($manifest['gates'] as $gate) {
            $byId[$gate['id']] = $gate;
        }

        foreach ([
            'check-s1-configuration-activation',
            'check-s1-configuration-authority',
            'check-s1-schema-authority',
            'check-s1-sqlite-contract',
        ] as $id) {
            $this->assertSame('auto', $byId[$id]['refresh']['mode'] ?? null, $id . ' must be auto-refreshable.');
            $this->assertNotSame('', (string) ($byId[$id]['refresh']['write'] ?? ''), $id);
        }

        foreach (['check-getquery-bindings', 'check-dead-code', 'surface-parity'] as $id) {
            $this->assertSame('manual', $byId[$id]['refresh']['mode'] ?? null, $id . ' regeneration needs human judgment.');
        }
    }
}
