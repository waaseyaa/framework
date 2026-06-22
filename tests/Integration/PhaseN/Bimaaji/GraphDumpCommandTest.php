<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Bimaaji\Command\GraphDumpHandler;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;

/**
 * Integration coverage for `bin/waaseyaa graph:dump` (WP02 of
 * mission bimaaji-wakeup-01KS5VEY).
 *
 * Stub providers stand in for the six default introspection providers
 * so the handler logic is exercised without booting a full kernel —
 * WP03 will add a booted-kernel pipeline test that runs the real wiring.
 */
#[CoversClass(GraphDumpHandler::class)]
final class GraphDumpCommandTest extends TestCase
{
    #[Test]
    public function dumpsFullGraphAsJsonWithAllSections(): void // FR-011
    {
        $tester = $this->createTester($this->stubProviders());
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());

        $data = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('1.0', $data['version']);
        self::assertSame(
            ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
            array_keys($data['sections']),
            'Section keys must be present and alphabetically sorted (NFR-003).',
        );
    }

    #[Test]
    public function scopesToSingleSectionViaOption(): void // FR-012
    {
        $tester = $this->createTester($this->stubProviders());
        $tester->execute(['--section', 'routing']);

        self::assertSame(0, $tester->getExitCode());

        $data = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['routing'], array_keys($data['sections']));
        self::assertSame('routing', $data['sections']['routing']['key']);
    }

    #[Test]
    public function failsWithHelpfulMessageOnUnknownSection(): void // FR-013
    {
        $tester = $this->createTester($this->stubProviders());
        $tester->execute(['--section', 'nonexistent']);

        self::assertSame(1, $tester->getExitCode());

        $stderr = $tester->getStderr();
        self::assertStringContainsString('Unknown section "nonexistent"', $stderr);
        self::assertStringContainsString('Available sections:', $stderr);
        self::assertStringContainsString('routing', $stderr);
    }

    #[Test]
    public function rejectsUnsupportedFormat(): void // FR-006 / NFR-004 boundary
    {
        $tester = $this->createTester($this->stubProviders());
        $tester->execute(['--format', 'yaml']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unsupported --format value "yaml"', $tester->getStderr());
    }

    #[Test]
    public function strictModeNamesFailingProviderInExitOnePath(): void // NFR-004
    {
        $providers = [
            $this->stubProvider('routing', ['count' => 7]),
            new class implements GraphSectionProviderInterface {
                public function getKey(): string
                {
                    return 'sovereignty';
                }

                public function provide(): GraphSection
                {
                    throw new \RuntimeException('upstream sovereignty config unavailable');
                }
            },
        ];

        $tester = $this->createTester($providers);
        $tester->execute(['--strict']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('--strict mode', $tester->getStderr());
        self::assertStringContainsString('RuntimeException', $tester->getStderr());
        self::assertStringContainsString('upstream sovereignty config unavailable', $tester->getStderr());
    }

    #[Test]
    public function outputIsStableAcrossRuns(): void // NFR-003
    {
        $tester = $this->createTester($this->stubProviders());
        $tester->execute([]);
        $first = $tester->getStdout();

        $tester = $this->createTester($this->stubProviders());
        $tester->execute([]);
        $second = $tester->getStdout();

        self::assertSame($first, $second, 'graph:dump output must be byte-for-byte stable across runs (NFR-003).');
    }

    /**
     * @param list<GraphSectionProviderInterface> $providers
     */
    private function createTester(array $providers): CliTester
    {
        $handler = new GraphDumpHandler(providers: $providers);

        $definition = new HandlerCommand(
            name: 'graph:dump',
            description: 'Dump the application graph as JSON.',
            options: [
                new HandlerOption(name: 'section', mode: HandlerOptionMode::Required, description: 'Scope to one section.'),
                new HandlerOption(name: 'format', mode: HandlerOptionMode::Required, description: 'Output format.'),
                new HandlerOption(name: 'strict', mode: HandlerOptionMode::None, description: 'Fail-closed on provider errors.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: $id");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($definition, $container);
    }

    /** @return list<GraphSectionProviderInterface> */
    private function stubProviders(): array
    {
        return [
            $this->stubProvider('admin', ['entity_types' => []]),
            $this->stubProvider('entities', ['entity_types' => []]),
            $this->stubProvider('jsonapi', ['routes' => []]),
            $this->stubProvider('public_surface', ['routes' => []]),
            $this->stubProvider('routing', ['routes' => []]),
            $this->stubProvider('sovereignty', ['profile' => 'local']),
        ];
    }

    /** @param array<string, mixed> $data */
    private function stubProvider(string $key, array $data): GraphSectionProviderInterface
    {
        return new class ($key, $data) implements GraphSectionProviderInterface {
            /** @param array<string, mixed> $data */
            public function __construct(
                private readonly string $key,
                private readonly array $data,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function provide(): GraphSection
            {
                return new GraphSection(
                    key: $this->key,
                    version: '1.0',
                    data: $this->data,
                );
            }
        };
    }
}
