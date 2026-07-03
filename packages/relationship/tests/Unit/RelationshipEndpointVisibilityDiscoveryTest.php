<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\KernelPolicyDependencyResolver;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipAccessPolicy;
use Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\ArrayAccount;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureAccessPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureEntity;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityRepository;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityStorage;

/**
 * Boot-wiring regression for {@see RelationshipEndpointVisibilityPolicy}.
 *
 * The other three tests HAND-WIRE the policy onto an `EntityAccessHandler`,
 * which is exactly how a ConsoleKernel wiring gap could slip through: an
 * earlier cut registered the policy only via
 * `RelationshipServiceProvider::configureHttpKernel()` — a hook `ConsoleKernel`
 * never invokes — so `entity.read` under `ai:run --inline` / `queue:work` (real
 * ConsoleKernel production callers) still leaked. This test instead proves the
 * policy is discovered and wired by the SAME two-phase
 * {@see AccessPolicyRegistry} the SHARED boot path runs (`AbstractKernel::discoverAccessPolicies()`),
 * which registers on BOTH kernels — so there is no kernel-specific gap.
 *
 * It fails if someone (a) removes `#[PolicyAttribute]` (e.g. reverting to the
 * `configureHttpKernel` approach), or (b) changes the constructor so the
 * two-phase registry can no longer resolve/deferred-inject its dependencies.
 */
#[CoversNothing]
final class RelationshipEndpointVisibilityDiscoveryTest extends TestCase
{
    #[Test]
    public function policy_carries_policy_attribute_for_relationship_so_it_is_discoverable(): void
    {
        // This is the discovery SIGNAL — the manifest compiler only records a
        // class in `manifest->policies` when it carries #[PolicyAttribute].
        // Reverting to configureHttpKernel would drop this attribute and this
        // assertion fails.
        $attrs = new \ReflectionClass(RelationshipEndpointVisibilityPolicy::class)
            ->getAttributes(PolicyAttribute::class);

        self::assertNotEmpty(
            $attrs,
            'RelationshipEndpointVisibilityPolicy must carry #[PolicyAttribute] so the SHARED-boot '
            . 'AccessPolicyRegistry registers it on BOTH HttpKernel and ConsoleKernel.',
        );
        self::assertContains('relationship', $attrs[0]->newInstance()->entityTypes);
    }

    #[Test]
    public function constructor_defers_to_phase_two_via_entity_access_handler_param(): void
    {
        // The two-phase registry DEFERS a policy whose constructor takes
        // EntityAccessHandler, then injects the preliminary handler. If the
        // constructor loses that parameter the policy can no longer delegate to
        // endpoint policies — assert the shape the registry keys on.
        $ctor = new \ReflectionClass(RelationshipEndpointVisibilityPolicy::class)->getConstructor();
        self::assertNotNull($ctor);

        $paramTypes = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $paramTypes[] = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        }

        self::assertContains(
            \Waaseyaa\Access\EntityAccessHandler::class,
            $paramTypes,
            'The EntityAccessHandler constructor param is what triggers phase-2 deferral in AccessPolicyRegistry.',
        );
        self::assertContains(
            EntityTypeManagerInterface::class,
            $paramTypes,
            'EntityTypeManagerInterface is resolved by the kernel-services bus (ProviderRegistryKernelServices::get).',
        );
    }

    #[Test]
    public function discovered_handler_redacts_hidden_endpoint_without_hand_wiring(): void
    {
        [$handler, $etm] = $this->discoverHandler();

        $edge = $this->hiddenEdge();
        $baseline = new ArrayAccount(0, ['access content']);

        // The handler was produced ENTIRELY by AccessPolicyRegistry->discover()
        // — the policy was NOT hand-added. filterFields() must drop the hidden
        // endpoint's identity pair for a baseline account.
        $allowed = $handler->filterFields(
            $edge,
            ['from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'relationship_type'],
            'view',
            $baseline,
        );

        self::assertNotContains('to_entity_id', $allowed, 'Discovered handler must redact the hidden endpoint id.');
        self::assertNotContains('to_entity_type', $allowed, 'Discovered handler must redact the hidden endpoint type.');
        self::assertContains('from_entity_type', $allowed, 'The visible (published) endpoint must remain.');
        self::assertContains('from_entity_id', $allowed);

        // Sanity: the same discovered handler exposes the hidden endpoint for an
        // admin, proving delegation to the endpoint's own policy really runs.
        $admin = new ArrayAccount(1, ['access content', 'administer nodes']);
        $adminAllowed = $handler->filterFields(
            $edge,
            ['to_entity_type', 'to_entity_id'],
            'view',
            $admin,
        );
        self::assertContains('to_entity_id', $adminAllowed);
        self::assertContains('to_entity_type', $adminAllowed);

        // Silence "unused" on $etm — it is intentionally kept alive for the
        // handler's endpoint loads.
        self::assertTrue($etm->hasDefinition('endpoint_entity'));
    }

    /**
     * @return array{0: \Waaseyaa\Access\EntityAccessHandler, 1: EntityTypeManager}
     */
    private function discoverHandler(): array
    {
        $endpointStorage = new PresetEntityStorage(
            [
                new EndpointFixtureEntity(['id' => 10, 'uuid' => 'endpoint-uuid-10', 'title' => 'Public', 'published' => true]),
                new EndpointFixtureEntity(['id' => 20, 'uuid' => 'endpoint-uuid-20', 'title' => 'Hidden', 'published' => false]),
            ],
            'endpoint_entity',
        );
        $relationshipStorage = new PresetEntityStorage([$this->hiddenEdge()], 'relationship');

        $storages = [
            'endpoint_entity' => $endpointStorage,
            'relationship' => $relationshipStorage,
        ];

        $etm = new EntityTypeManager(
            new EventDispatcher(),
            storageFactory: fn(EntityTypeInterface $definition) => $storages[$definition->id()],
            repositoryFactory: fn(string $entityTypeId) => new PresetEntityRepository($storages[$entityTypeId]),
        );
        $etm->registerEntityType(new EntityType(
            id: 'endpoint_entity',
            label: 'Endpoint',
            class: EndpointFixtureEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $etm->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            group: 'content',
        ));

        // Minimal kernel-services bus resolving exactly what the resolver needs
        // for these policies' constructor params — EntityTypeManagerInterface.
        // (EntityAccessHandler is injected by the registry's phase-2, not this bus.)
        $kernelServices = new class ($etm) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $etm) {}

            public function get(string $abstract): ?object
            {
                return in_array($abstract, [EntityTypeManager::class, EntityTypeManagerInterface::class], true)
                    ? $this->etm
                    : null;
            }
        };

        // A manifest listing both policies exactly as PackageManifestCompiler
        // would after attribute-scanning packages/relationship/src. The
        // endpoint fixture policy stands in for a real endpoint type's own
        // AccessPolicy (e.g. NodeAccessPolicy).
        $manifest = new PackageManifest(policies: [
            RelationshipAccessPolicy::class => ['relationship'],
            RelationshipEndpointVisibilityPolicy::class => ['relationship'],
            EndpointFixtureAccessPolicy::class => ['endpoint_entity'],
        ]);

        $resolver = new KernelPolicyDependencyResolver($kernelServices);
        $handler = new AccessPolicyRegistry(new NullLogger(), $resolver)->discover($manifest);

        return [$handler, $etm];
    }

    private function hiddenEdge(): Relationship
    {
        return new Relationship([
            'rid' => 1,
            'uuid' => 'edge-uuid-1',
            'relationship_type' => 'references',
            'from_entity_type' => 'endpoint_entity',
            'from_entity_id' => '10',
            'to_entity_type' => 'endpoint_entity',
            'to_entity_id' => '20',
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }
}
