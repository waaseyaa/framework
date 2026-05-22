<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

/**
 * Booted-pipeline integration test for {@see ApplicationGraphGenerator}.
 *
 * Mirrors the minimal-kernel pattern WP01 established for unit tests of
 * {@see BimaajiServiceProvider}: a stub {@see KernelServicesInterface}
 * provides the three required cross-package services, then the provider
 * registers + resolves the generator end-to-end. This exercises the
 * `composer.json` → `extra.waaseyaa.providers` → `BimaajiServiceProvider`
 * → container → `ApplicationGraphGenerator` pipeline that WP01 wires up,
 * which a pure unit test of any single component cannot do.
 *
 * FR-010 (3 assertions): six default sections present, non-empty versions,
 * shape contract upheld. NFR-001 (1 assertion): soft 100 ms budget with a
 * hard 500 ms ceiling.
 */
#[CoversClass(ApplicationGraphGenerator::class)]
final class ApplicationGraphIntegrationTest extends TestCase
{
    private ?ApplicationGraphGenerator $generator = null;

    protected function setUp(): void
    {
        $provider = $this->buildBootedProvider();
        $resolved = $provider->resolve(ApplicationGraphGenerator::class);
        self::assertInstanceOf(ApplicationGraphGenerator::class, $resolved);
        $this->generator = $resolved;
    }

    private function generator(): ApplicationGraphGenerator
    {
        self::assertNotNull($this->generator, 'setUp() must have initialised the generator.');

        return $this->generator;
    }

    #[Test]
    public function generatesAllSixDefaultSections(): void // FR-010
    {
        $graph = $this->generator()->generate();
        $this->assertGraph($graph);

        self::assertSame(
            ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
            $this->sortedSectionKeys($graph),
            'Expected the six canonical bimaaji sections in the booted-pipeline graph.',
        );
    }

    #[Test]
    public function allSectionsHaveNonEmptyVersionStrings(): void // FR-010
    {
        $graph = $this->generator()->generate();
        $this->assertGraph($graph);

        foreach ($graph->sections as $key => $section) {
            self::assertNotSame('', $section->version, "Section \"{$key}\" version string must not be empty.");
        }
    }

    #[Test]
    public function eachSectionToArrayUpholdsContract(): void // FR-010 smoke
    {
        $graph = $this->generator()->generate();
        $this->assertGraph($graph);

        foreach ($graph->sections as $key => $section) {
            $array = $section->toArray();
            self::assertArrayHasKey('key', $array, "Section \"{$key}\" toArray() must include 'key'.");
            self::assertArrayHasKey('version', $array, "Section \"{$key}\" toArray() must include 'version'.");
            self::assertArrayHasKey('data', $array, "Section \"{$key}\" toArray() must include 'data'.");
            self::assertSame($key, $array['key'], "Section \"{$key}\" toArray() key field must echo the section key.");
        }
    }

    #[Test]
    public function generateCompletesWithinNfrBudget(): void // NFR-001
    {
        // Soft budget — log a warning to stderr instead of failing, so a slow CI runner
        // does not produce flaky reds. Hard ceiling at 5× the budget catches catastrophic
        // regressions (e.g. provider doing unexpected filesystem walks).
        $softBudgetMs = 100.0;
        $hardCeilingMs = 500.0;

        $start = hrtime(true);
        $this->generator()->generate();
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        if ($elapsedMs > $softBudgetMs) {
            fwrite(STDERR, sprintf(
                "\n[NFR-001 WARNING] ApplicationGraphGenerator::generate() took %.2f ms (soft budget: %.0f ms). "
                . "Investigate provider performance if this stays elevated across runs.\n",
                $elapsedMs,
                $softBudgetMs,
            ));
        }

        self::assertLessThan(
            $hardCeilingMs,
            $elapsedMs,
            sprintf('NFR-001 HARD CEILING: generate() took %.2f ms (limit: %.0f ms).', $elapsedMs, $hardCeilingMs),
        );
    }

    private function assertGraph(ApplicationGraph $graph): void
    {
        self::assertGreaterThanOrEqual(
            6,
            count($graph->sections),
            'Expected at least 6 default sections from BimaajiServiceProvider.',
        );
    }

    /** @return list<string> */
    private function sortedSectionKeys(ApplicationGraph $graph): array
    {
        $keys = array_keys($graph->sections);
        sort($keys);

        return $keys;
    }

    /**
     * Boot a `BimaajiServiceProvider` with the minimum kernel-services it needs
     * to resolve `ApplicationGraphGenerator`. The pattern mirrors WP01's unit
     * test helper but is deliberately not extracted to a shared trait — keeping
     * the integration test self-contained makes the WP03/WP02 inheritance graph
     * visible from a single file.
     */
    private function buildBootedProvider(): BimaajiServiceProvider
    {
        $kernelServices = new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class, EntityTypeManager::class => self::stubEntityTypeManager(),
                    RouteCollection::class => new RouteCollection(),
                    SovereigntyConfigInterface::class => self::stubSovereigntyConfig(),
                    default => null,
                };
            }

            private static function stubEntityTypeManager(): EntityTypeManagerInterface
            {
                return new class implements EntityTypeManagerInterface {
                    public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
                    {
                        throw new \RuntimeException('Not exercised in this integration test.');
                    }

                    public function registerEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void {}

                    public function registerCoreEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void {}

                    /** @return array<string, \Waaseyaa\Entity\EntityTypeInterface> */
                    public function getDefinitions(): array
                    {
                        return [];
                    }

                    public function hasDefinition(string $entityTypeId): bool
                    {
                        return false;
                    }

                    public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface
                    {
                        throw new \RuntimeException('Not exercised in this integration test.');
                    }

                    public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
                    {
                        throw new \RuntimeException('Not exercised in this integration test.');
                    }
                };
            }

            private static function stubSovereigntyConfig(): SovereigntyConfigInterface
            {
                return new class implements SovereigntyConfigInterface {
                    public function get(string $key): ?string
                    {
                        return null;
                    }

                    public function getProfile(): SovereigntyProfile
                    {
                        return SovereigntyProfile::SelfHosted;
                    }

                    /** @return array<string, string> */
                    public function all(): array
                    {
                        return [];
                    }
                };
            }
        };

        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext('/tmp', [], []);
        $provider->setKernelServices($kernelServices);
        $provider->register();

        return $provider;
    }
}
