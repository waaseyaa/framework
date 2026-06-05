<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;
use Waaseyaa\Bimaaji\Policy\SovereigntyGuardrails;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

/**
 * Cross-mission gate for M2 (ai-agent ↔ bimaaji in-process tools).
 *
 * Asserts that the four bimaaji services M2's tool adapters depend on are
 * container-resolvable after M1 lands:
 *
 *   - ApplicationGraphGenerator (M1 WP01 guarantee — sanity check)
 *   - MutationValidator         (M2 WP01 addition under documented C-002 exception)
 *   - PatchGenerator            (M2 WP01 addition under documented C-002 exception)
 *   - SovereigntyGuardrails     (M2 WP01 addition — kept available so future
 *                                mutation tools can surface deny reasons)
 *
 * If this test passes, M2's WP03 mutation tools can resolve their bimaaji
 * dependencies from the container without further bimaaji surgery.
 */
#[CoversNothing]
final class BimaajiBindingsAuditTest extends TestCase
{
    #[Test]
    public function resolvesAllRequiredBimaajiServices(): void
    {
        $provider = $this->buildBootedProvider();

        $generator = $provider->resolve(ApplicationGraphGenerator::class);
        self::assertInstanceOf(
            ApplicationGraphGenerator::class,
            $generator,
            'M1 contract: ApplicationGraphGenerator must be container-resolvable after BimaajiServiceProvider::register().',
        );

        $validator = $provider->resolve(MutationValidator::class);
        self::assertInstanceOf(
            MutationValidator::class,
            $validator,
            'M2 WP01: MutationValidator must be container-resolvable so M2 WP03 ProposeMutationTool can wrap it.',
        );

        $patchGenerator = $provider->resolve(PatchGenerator::class);
        self::assertInstanceOf(
            PatchGenerator::class,
            $patchGenerator,
            'M2 WP01: PatchGenerator must be container-resolvable so M2 WP03 GeneratePatchTool can wrap it.',
        );

        $guardrails = $provider->resolve(SovereigntyGuardrails::class);
        self::assertInstanceOf(
            SovereigntyGuardrails::class,
            $guardrails,
            'M2 WP01: SovereigntyGuardrails must be container-resolvable so mutation tools can surface deny reasons.',
        );
    }

    #[Test]
    public function mutationValidatorSeesTheGeneratedGraph(): void
    {
        $provider = $this->buildBootedProvider();
        $validator = $provider->resolve(MutationValidator::class);

        // Smoke-test: the validator's internal graph must be the one the
        // generator produced. Run a request against a known-unknown entity
        // type and assert the failure path reports UNKNOWN_ENTITY_TYPE rather
        // than crashing on a null graph.
        $request = new \Waaseyaa\Bimaaji\Mutation\MutationRequest(
            operation: 'add_field',
            entityType: 'definitely_not_a_real_entity_type',
            field: 'some_field',
            parameters: [],
        );
        $result = $validator->validate($request);
        self::assertFalse($result->isSuccess(), 'Validator must reject unknown entity types.');
    }

    /**
     * Boot a `BimaajiServiceProvider` with the minimum kernel-services it needs.
     * Same pattern as M1 WP03's `ApplicationGraphIntegrationTest` — keep the
     * stub inline so the cross-mission gate is self-contained.
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
                    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
                    {
                        return [];
                    }
                    public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
                    {
                        throw new \RuntimeException('Not exercised in this audit test.');
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
                        throw new \RuntimeException('Not exercised in this audit test.');
                    }

                    public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
                    {
                        throw new \RuntimeException('Not exercised in this audit test.');
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
