<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as FoundationServiceProvider;
use Waaseyaa\Listing\Exception\UnsupportedListingException;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ServiceProvider as ListingServiceProvider;

/**
 * Discriminating regression for the #2857 packaged-proof lifecycle defect:
 * `PackageManifestCompiler` places the installed Listing package provider
 * BEFORE root App providers, so a generated App provider that registers its
 * bundle fields inside its own `boot()` does so strictly after the Listing
 * provider's `boot()` has already run.
 *
 * Before the repair, FR-052/FR-053 validation ran inside the Listing
 * provider's ordinary `boot()`, so it observed the bundle as unregistered
 * and raised `UnsupportedListingException` even though the App provider's
 * later boot would have registered it moments after. After the repair,
 * validation runs from `finalizeProviderBoot()`, which
 * {@see ProviderRegistry::boot()} invokes only once every provider's
 * ordinary `boot()` — including the late App provider's — has completed.
 *
 * Uses the real {@see ProviderRegistry} and the real Listing
 * {@see ListingServiceProvider} (no mocks) so the assertion is about actual
 * boot ordering, not a stand-in for it.
 */
#[CoversClass(ListingServiceProvider::class)]
final class ListingProviderBootFinalizationTest extends TestCase
{
    #[Test]
    public function lateAppProviderBundleFieldsValidateOnlyAfterAllProviderBootsComplete(): void
    {
        [$registry, $providers, $entityTypeManager] = $this->bootstrap([
            ListingServiceProvider::class,
            LateBundleAppProviderFixture::class,
        ]);

        // The defect manifested as a boot-time UnsupportedListingException
        // here; a fixed lifecycle boundary lets this complete.
        $registry->boot($providers);

        $listingProvider = $providers[0];
        self::assertInstanceOf(ListingServiceProvider::class, $listingProvider);

        $definitionRegistry = $listingProvider->resolve(ListingDefinitionRegistry::class);
        self::assertInstanceOf(ListingDefinitionRegistry::class, $definitionRegistry);
        self::assertTrue($definitionRegistry->has('lifecycle_pages'));

        // The bundle field really was registered late, by the App provider's
        // own boot() — proving this is a real ordering race, not a fixture
        // that happened to already satisfy the validator.
        self::assertSame(
            ['page'],
            $entityTypeManager->getFieldRegistry()->bundleNamesFor('lifecycle_page'),
        );
    }

    #[Test]
    public function invalidListingStillRaisesUnsupportedListingExceptionAtFinalization(): void
    {
        [$registry, $providers] = $this->bootstrap([
            ListingServiceProvider::class,
            InvalidListingProviderFixture::class,
        ]);

        try {
            $registry->boot($providers);
            self::fail('Expected UnsupportedListingException');
        } catch (UnsupportedListingException $e) {
            self::assertSame('lifecycle_invalid', $e->listingId);
            self::assertStringContainsString('not registered', $e->reason);
        }
    }

    /**
     * @param list<class-string> $providerClasses
     * @return array{0: ProviderRegistry, 1: list<FoundationServiceProvider>, 2: EntityTypeManager}
     */
    private function bootstrap(array $providerClasses): array
    {
        $dispatcher = new EventDispatcher();
        $fieldRegistry = new FieldDefinitionRegistry();
        $entityTypeManager = new EntityTypeManager(
            eventDispatcher: $dispatcher,
            fieldRegistry: $fieldRegistry,
        );
        $database = DBALDatabase::createSqlite(':memory:');

        $registry = new ProviderRegistry(new NullLogger());
        $providers = $registry->discoverAndRegister(
            manifest: new PackageManifest(providers: $providerClasses),
            projectRoot: sys_get_temp_dir(),
            config: [],
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
        );

        return [$registry, $providers, $entityTypeManager];
    }
}

/**
 * @internal Minimal non-translatable entity stub for the `lifecycle_page`
 * entity type used across this test.
 */
final class LifecyclePageFixtureEntity
{
}

/**
 * @internal Mirrors a generated App provider (per the #2857 packaged proof):
 * registers its entity type at register()-time (collected before any boot()
 * runs, so it is always available), then registers its bundle's fields
 * inside its own boot() — deliberately late relative to the Listing package
 * provider, which precedes it in provider order.
 */
final class LateBundleAppProviderFixture extends FoundationServiceProvider implements HasListingsInterface
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'lifecycle_page',
            label: 'Lifecycle Page',
            class: LifecyclePageFixtureEntity::class,
            keys: ['id' => 'id', 'bundle' => 'type'],
        ));
    }

    public function boot(): void
    {
        $fieldRegistry = $this->resolve(FieldDefinitionRegistryInterface::class);
        if (!$fieldRegistry instanceof FieldDefinitionRegistryInterface) {
            throw new \LogicException('Kernel did not expose a field-definition registry.');
        }
        $fieldRegistry->registerBundleFields('lifecycle_page', 'page', [
            'headline' => new FieldDefinition(
                name: 'headline',
                type: 'string',
                targetEntityTypeId: 'lifecycle_page',
                targetBundle: 'page',
                stored: FieldStorage::Column,
            ),
        ]);
    }

    public function listings(): array
    {
        return [
            new ListingDefinition(
                id: 'lifecycle_pages',
                entityType: 'lifecycle_page',
                bundle: 'page',
                filters: [Filter::eq('headline', 'welcome')],
                pageSize: 20,
            ),
        ];
    }
}

/**
 * @internal Declares a listing against an entity type that is never
 * registered, so FR-050/FR-053 must still reject it — proving the
 * finalize-boundary repair preserves fail-fast rejection of genuinely
 * invalid listings.
 */
final class InvalidListingProviderFixture extends FoundationServiceProvider implements HasListingsInterface
{
    public function register(): void
    {
    }

    public function listings(): array
    {
        return [
            new ListingDefinition(
                id: 'lifecycle_invalid',
                entityType: 'no_such_lifecycle_entity_type',
            ),
        ];
    }
}
