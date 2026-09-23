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
 * Executes bin/check-ingestion-defaults for real: the shipped defaults/ pack
 * must pass, and every contract rule must reject its violation with its own
 * FAIL line rather than an interpreter crash. A crash exits 1 too, so the
 * exit code alone would let an environmental failure pass as a negative
 * control; every assertion therefore pins the rule's message and forbids a
 * Python traceback.
 */
#[CoversNothing]
final class CheckIngestionDefaultsGateTest extends TestCase
{
    private const string GATE = 'bin/check-ingestion-defaults';
    private const string OK = 'OK: All ingestion defaults are valid';

    private string $repoRoot;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa_ingestion_defaults_' . uniqid('', true);
        mkdir($this->fixtureRoot . '/bin', 0o755, true);
        mkdir($this->fixtureRoot . '/defaults', 0o755, true);
        copy($this->repoRoot . '/' . self::GATE, $this->fixtureRoot . '/' . self::GATE);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixtureRoot);
    }

    #[Test]
    public function shipped_defaults_pack_passes(): void
    {
        [$exit, $output] = $this->runGate($this->repoRoot);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(self::OK, $output);
        self::assertStringNotContainsString('Traceback', $output);
    }

    #[Test]
    public function shipped_defaults_pack_passes_without_msys_argument_conversion(): void
    {
        // Inert on Linux; on Git for Windows these disable argv path rewriting,
        // proving the gate never relies on it to reach a native python3.
        [$exit, $output] = $this->runGate($this->repoRoot, ['MSYS_NO_PATHCONV' => '1', 'MSYS2_ARG_CONV_EXCL' => '*']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(self::OK, $output);
    }

    #[Test]
    public function minimal_valid_pack_passes(): void
    {
        $this->writeDefaults(['ingestion.envelope.schema.json' => self::envelope(), 'demo.item.schema.json' => self::entity()]);

        [$exit, $output] = $this->runGate($this->fixtureRoot);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(self::OK, $output);
    }

    #[Test]
    public function schemas_are_read_as_utf8_on_every_host(): void
    {
        $entity = self::entity();
        $entity['description'] = "Anishinaabemowin \u{01DD} \u{2014} gichi";
        $this->writeDefaults(['ingestion.envelope.schema.json' => self::envelope(), 'demo.item.schema.json' => $entity]);

        [$exit, $output] = $this->runGate($this->fixtureRoot);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(self::OK, $output);
    }

    /** @param array<string, array<string, mixed>> $files */
    #[Test]
    #[DataProvider('violations')]
    public function each_contract_violation_fails_with_its_own_message(array $files, string $expected): void
    {
        $this->writeDefaults($files);

        [$exit, $output] = $this->runGate($this->fixtureRoot);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString('Traceback', $output);
        self::assertStringNotContainsString(self::OK, $output);
    }

    /** @return iterable<string, array{array<string, array<string, mixed>>, string}> */
    public static function violations(): iterable
    {
        $envelope = self::envelope();
        $pack = static fn(array $entity): array => ['ingestion.envelope.schema.json' => $envelope, 'demo.item.schema.json' => $entity];
        $ext = static fn(array $override): array => ['type' => 'object', 'x-waaseyaa' => array_replace(self::entity()['x-waaseyaa'], $override)];

        yield 'missing x-waaseyaa' => [$pack(['type' => 'object']), 'FAIL: demo.item.schema.json missing x-waaseyaa block'];
        $noVersion = self::entity();
        unset($noVersion['x-waaseyaa']['version']);
        yield 'missing version' => [$pack($noVersion), 'FAIL: demo.item.schema.json x-waaseyaa missing required field: version'];
        yield 'unknown compatibility' => [$pack($ext(['compatibility' => 'loose'])), 'x-waaseyaa.compatibility must be liberal or strict, got: loose'];
        yield 'non-semver version' => [$pack($ext(['version' => '1.0'])), 'x-waaseyaa.version must be semver (e.g. 0.1.0), got: 1.0'];
        yield 'unknown schema_kind' => [$pack($ext(['schema_kind' => 'blob'])), 'x-waaseyaa.schema_kind must be entity or ingestion_envelope, got: blob'];
        yield 'unknown stability' => [$pack($ext(['stability' => 'beta'])), 'x-waaseyaa.stability must be experimental, stable, or deprecated, got: beta'];
        yield 'entity_type does not match filename' => [$pack($ext(['entity_type' => 'demo.other'])), 'FAIL: demo.item.schema.json x-waaseyaa.entity_type is "demo.other", expected "demo.item"'];
        yield 'envelope absent' => [['demo.item.schema.json' => self::entity()], 'FAIL: ingestion.envelope.schema.json not found in defaults/'];
        $noTimestamp = $envelope;
        $noTimestamp['required'] = ['source', 'type', 'payload'];
        yield 'envelope missing timestamp' => [['ingestion.envelope.schema.json' => $noTimestamp, 'demo.item.schema.json' => self::entity()], 'FAIL: ingestion.envelope.schema.json missing required field: timestamp'];
        $wrongKind = $envelope;
        $wrongKind['x-waaseyaa']['schema_kind'] = 'entity';
        yield 'envelope schema_kind' => [['ingestion.envelope.schema.json' => $wrongKind, 'demo.item.schema.json' => self::entity()], 'FAIL: ingestion.envelope.schema.json x-waaseyaa.schema_kind must be ingestion_envelope'];
    }

    /** @return array<string, mixed> */
    private static function envelope(): array
    {
        return [
            'type' => 'object',
            'required' => ['source', 'type', 'payload', 'timestamp'],
            'x-waaseyaa' => ['entity_type' => 'ingestion.envelope', 'version' => '0.1.0', 'compatibility' => 'strict', 'schema_kind' => 'ingestion_envelope'],
        ];
    }

    /** @return array<string, mixed> */
    private static function entity(): array
    {
        return ['type' => 'object', 'x-waaseyaa' => ['entity_type' => 'demo.item', 'version' => '0.1.0', 'compatibility' => 'liberal']];
    }

    /** @param array<string, array<string, mixed>> $files */
    private function writeDefaults(array $files): void
    {
        foreach ($files as $name => $schema) {
            file_put_contents(
                $this->fixtureRoot . '/defaults/' . $name,
                json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
        }
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function runGate(string $root, array $env = []): array
    {
        $process = new Process([$this->bash(), $root . '/' . self::GATE], $root, $env === [] ? null : $env);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }

    private function bash(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'bash';
        }

        // CreateProcess searches System32 before PATH, so a bare "bash" is the
        // WSL launcher; run the POSIX gate under Git for Windows Bash instead.
        foreach (preg_split('/\R/', (string) shell_exec('where git 2>NUL')) ?: [] as $git) {
            $candidate = dirname($git, 2) . '/bin/bash.exe';
            if ($git !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        self::fail('Tests on Windows must use Git for Windows Bash, not WSL Bash.');
    }
}
