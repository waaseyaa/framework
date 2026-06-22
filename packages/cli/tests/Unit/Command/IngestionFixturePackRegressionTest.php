<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\FixturePackRefreshHandler;
use Waaseyaa\CLI\Handler\IngestRunHandler;
use Waaseyaa\CLI\Provider\BundleFixtureServiceProvider;
use Waaseyaa\CLI\Provider\IngestSearchSemanticServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(IngestRunHandler::class)]
#[CoversClass(FixturePackRefreshHandler::class)]
final class IngestionFixturePackRegressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_ingestion_fixture_regression_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function ingest_fixture_replay_is_deterministic_for_valid_structured_scenario(): void
    {
        $inputPath = $this->repoRoot() . '/tests/fixtures/ingestion/structured-valid.input.json';

        $first = $this->runIngest(
            inputPath: $inputPath,
            extraOptions: [
                '--format' => 'structured',
                '--source' => 'dataset://fixtures',
                '--batch-id' => 'batch_fixture_valid',
                '--timestamp' => '1735689600',
            ],
            outputName: 'valid-first.json',
        );
        $second = $this->runIngest(
            inputPath: $inputPath,
            extraOptions: [
                '--format' => 'structured',
                '--source' => 'dataset://fixtures',
                '--batch-id' => 'batch_fixture_valid',
                '--timestamp' => '1735689600',
            ],
            outputName: 'valid-second.json',
        );

        $this->assertSame(0, $first['exitCode']);
        $this->assertSame(0, $second['exitCode']);
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame(0, $first['decoded']['meta']['error_count']);
        $this->assertSame(2, $first['decoded']['meta']['node_count']);
    }

    #[Test]
    public function ingestion_fixtures_cover_schema_validation_and_inference_surfaces(): void
    {
        $schema = $this->runIngest(
            inputPath: $this->repoRoot() . '/tests/fixtures/ingestion/structured-schema-invalid.input.json',
            extraOptions: [
                '--format' => 'structured',
                '--policy' => 'validate_only',
                '--source' => 'dataset://fixtures',
                '--batch-id' => 'batch_fixture_schema_invalid',
            ],
            outputName: 'schema-invalid.json',
        );
        $validation = $this->runIngest(
            inputPath: $this->repoRoot() . '/tests/fixtures/ingestion/structured-validation-invalid.input.json',
            extraOptions: [
                '--format' => 'structured',
                '--policy' => 'validate_only',
                '--source' => 'dataset://fixtures',
                '--batch-id' => 'batch_fixture_validation_invalid',
            ],
            outputName: 'validation-invalid.json',
        );
        $inference = $this->runIngest(
            inputPath: $this->repoRoot() . '/tests/fixtures/ingestion/structured-inference.input.json',
            extraOptions: [
                '--format' => 'structured',
                '--source' => 'dataset://fixtures',
                '--batch-id' => 'batch_fixture_inference',
                '--infer-relationships' => true,
            ],
            outputName: 'inference.json',
        );

        $schemaCodes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $schema['decoded']['diagnostics']['schema'],
        ));
        $validationCodes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $validation['decoded']['diagnostics']['validation'],
        ));
        $inferenceCodes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $inference['decoded']['diagnostics']['inference'],
        ));

        $this->assertSame(1, $schema['exitCode']);
        $this->assertContains('schema.duplicate_source_uri', $schemaCodes);
        $this->assertSame(1, $validation['exitCode']);
        $this->assertContains('validation.semantic.insufficient_publishable_tokens', $validationCodes);
        $this->assertSame(0, $inference['exitCode']);
        $this->assertContains('inference.relationship_inferred', $inferenceCodes);
        $this->assertSame(1, $inference['decoded']['meta']['inferred_relationship_count']);
    }

    #[Test]
    public function fixture_pack_refresh_consumes_ingestion_scenarios_consistently(): void
    {
        $scenarioDir = $this->repoRoot() . '/tests/fixtures/scenarios';

        $provider = new BundleFixtureServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'fixture:pack:refresh') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new FixturePackRefreshHandler();
            }

            public function has(string $id): bool
            {
                return $id === FixturePackRefreshHandler::class;
            }
        };

        $firstTester = CliTester::for($definition, $container);
        $firstTester->executeMap(['--input-dir' => $scenarioDir, '--json' => true]);
        $first = json_decode($firstTester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        $secondTester = CliTester::for($definition, $container);
        $secondTester->executeMap(['--input-dir' => $scenarioDir, '--json' => true]);
        $second = json_decode($secondTester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstTester->getExitCode());
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertGreaterThanOrEqual(3, $first['scenario_count']);
        $this->assertSame('ingestion-blocked', array_keys($first['scenarios'])[0]);
        $this->assertArrayHasKey('ingestion-ready', $first['scenarios']);
        $this->assertArrayHasKey('ingestion-review', $first['scenarios']);
    }

    /**
     * @param array<string, mixed> $extraOptions
     * @return array{exitCode:int,decoded:array<string,mixed>,hash:string}
     */
    private function runIngest(string $inputPath, array $extraOptions, string $outputName): array
    {
        $provider = new IngestSearchSemanticServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'ingest:run') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new IngestRunHandler();
            }

            public function has(string $id): bool
            {
                return $id === IngestRunHandler::class;
            }
        };

        $outputPath = $this->tempDir . '/' . $outputName;
        $options = array_merge([
            '--input' => $inputPath,
            '--output' => $outputPath,
        ], $extraOptions);

        $cliTester = CliTester::for($definition, $container);
        $cliTester->executeMap($options);

        $raw = (string) file_get_contents($outputPath);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return [
            'exitCode' => $cliTester->getExitCode(),
            'decoded' => $decoded,
            'hash' => hash('sha256', $raw),
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
