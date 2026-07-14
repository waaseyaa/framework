<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipAccessPolicy;
use Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureAccessPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureEntity;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityRepository;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityStorage;
use Waaseyaa\Relationship\Tests\Fixtures\ArrayAccount;

/**
 * Audit-remediation R5: `GET /api/relationship` (collection AND single)
 * exposed the endpoint identity (`to_entity_type`/`to_entity_id`) of a hidden
 * endpoint to any baseline (including anonymous) caller, because
 * {@see RelationshipAccessPolicy} only gates the edge's own status/permission
 * and never checks endpoint visibility. This test exercises the REAL
 * {@see JsonApiController} + {@see ResourceSerializer} + {@see EntityAccessHandler}
 * stack — the same field-access plumbing production wires — to prove the
 * leak is closed by {@see RelationshipEndpointVisibilityPolicy}.
 *
 * Fixture topology:
 *  - endpoint #10 ("Public"): published => visible to every account.
 *  - endpoint #20 ("Hidden"): unpublished => visible only to 'administer nodes'.
 *  - endpoint #30 ("Public2"): published => visible to every account.
 *  - relationship rid=1: 10 -> 20 (one hidden endpoint).
 *  - relationship rid=2: 10 -> 30 (both endpoints visible; positive control).
 */
#[CoversNothing]
final class RelationshipEndpointVisibilityRestTest extends TestCase
{
    private function endpoint(int $id, string $title, bool $published): EndpointFixtureEntity
    {
        return new EndpointFixtureEntity([
            'id' => $id,
            'uuid' => 'endpoint-uuid-' . $id,
            'title' => $title,
            'published' => $published,
        ]);
    }

    private function edge(int $rid, string $fromId, string $toId): Relationship
    {
        return new Relationship([
            'rid' => $rid,
            'uuid' => 'edge-uuid-' . $rid,
            'relationship_type' => 'references',
            'from_entity_type' => 'endpoint_entity',
            'from_entity_id' => $fromId,
            'to_entity_type' => 'endpoint_entity',
            'to_entity_id' => $toId,
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }

    private function controller(AccountInterface $account): JsonApiController
    {
        $endpointStorage = new PresetEntityStorage(
            [
                $this->endpoint(10, 'Public', published: true),
                $this->endpoint(20, 'Hidden', published: false),
                $this->endpoint(30, 'Public2', published: true),
            ],
            'endpoint_entity',
        );
        $relationshipStorage = new PresetEntityStorage(
            [
                $this->edge(1, '10', '20'),
                $this->edge(2, '10', '30'),
            ],
            'relationship',
        );

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

        // Mirrors production: RelationshipServiceProvider::configureHttpKernel()
        // adds RelationshipEndpointVisibilityPolicy to the handler AFTER the
        // handler already exists (late registration), because the policy needs
        // the handler itself to delegate to the endpoint entity's own policy.
        $accessHandler = new EntityAccessHandler([
            new RelationshipAccessPolicy(),
            new EndpointFixtureAccessPolicy(),
        ]);
        $accessHandler->addPolicy(new RelationshipEndpointVisibilityPolicy($etm, $accessHandler));

        return new JsonApiController(
            $etm,
            new ResourceSerializer($etm),
            $accessHandler,
            $account,
        );
    }

    private function baselineAccount(): AccountInterface
    {
        // Baseline: can view published content but is NOT an administrator —
        // the task's "any baseline caller (including anonymous on a public
        // install)" scenario.
        return new ArrayAccount(0, ['access content']);
    }

    private function adminAccount(): AccountInterface
    {
        return new ArrayAccount(0, ['access content', 'administer nodes']);
    }

    // -----------------------------------------------------------------------
    // GET /api/relationship (collection)
    // -----------------------------------------------------------------------

    #[Test]
    public function collection_redacts_hidden_to_endpoint_for_baseline_account(): void
    {
        $doc = $this->controller($this->baselineAccount())->index('relationship');
        $array = $doc->toArray();

        $edge1 = $this->findResource($array, 'edge-uuid-1');
        self::assertNotNull($edge1, 'relationship rid=1 must be present (edge itself is published/viewable).');
        self::assertArrayNotHasKey(
            'to_entity_id',
            $edge1['attributes'],
            'to_entity_id for the HIDDEN endpoint (#20, unpublished) must not leak to a baseline account.',
        );
        self::assertArrayNotHasKey(
            'to_entity_type',
            $edge1['attributes'],
            'to_entity_type for the HIDDEN endpoint must not leak to a baseline account.',
        );
        // The 'from' endpoint (#10, published) IS visible — only the hidden
        // endpoint's pair is redacted, not the whole record.
        self::assertSame('endpoint_entity', $edge1['attributes']['from_entity_type']);
        self::assertSame('10', $edge1['attributes']['from_entity_id']);
    }

    #[Test]
    public function collection_includes_both_endpoints_when_both_are_viewable(): void
    {
        $doc = $this->controller($this->baselineAccount())->index('relationship');
        $array = $doc->toArray();

        $edge2 = $this->findResource($array, 'edge-uuid-2');
        self::assertNotNull($edge2, 'relationship rid=2 must be present.');
        self::assertSame('endpoint_entity', $edge2['attributes']['from_entity_type']);
        self::assertSame('10', $edge2['attributes']['from_entity_id']);
        self::assertSame('endpoint_entity', $edge2['attributes']['to_entity_type']);
        self::assertSame('30', $edge2['attributes']['to_entity_id']);
    }

    #[Test]
    public function collection_includes_hidden_endpoint_for_privileged_account(): void
    {
        $doc = $this->controller($this->adminAccount())->index('relationship');
        $array = $doc->toArray();

        $edge1 = $this->findResource($array, 'edge-uuid-1');
        self::assertNotNull($edge1);
        self::assertSame(
            'endpoint_entity',
            $edge1['attributes']['to_entity_type'] ?? null,
            'administer nodes can view the hidden endpoint, so the field must be present.',
        );
        self::assertSame('20', $edge1['attributes']['to_entity_id'] ?? null);
    }

    // -----------------------------------------------------------------------
    // GET /api/relationship/{id} (single)
    // -----------------------------------------------------------------------

    #[Test]
    public function show_redacts_hidden_to_endpoint_for_baseline_account(): void
    {
        $doc = $this->controller($this->baselineAccount())->show('relationship', 1);
        $array = $doc->toArray();

        self::assertSame(200, $doc->statusCode);
        self::assertArrayNotHasKey('to_entity_id', $array['data']['attributes']);
        self::assertArrayNotHasKey('to_entity_type', $array['data']['attributes']);
        self::assertSame('10', $array['data']['attributes']['from_entity_id']);
    }

    #[Test]
    public function show_includes_both_endpoints_for_the_fully_public_edge(): void
    {
        $doc = $this->controller($this->baselineAccount())->show('relationship', 2);
        $array = $doc->toArray();

        self::assertSame(200, $doc->statusCode);
        self::assertSame('10', $array['data']['attributes']['from_entity_id']);
        self::assertSame('30', $array['data']['attributes']['to_entity_id']);
    }

    #[Test]
    public function show_includes_hidden_endpoint_for_privileged_account(): void
    {
        $doc = $this->controller($this->adminAccount())->show('relationship', 1);
        $array = $doc->toArray();

        self::assertSame(200, $doc->statusCode);
        self::assertSame('20', $array['data']['attributes']['to_entity_id']);
        self::assertSame('endpoint_entity', $array['data']['attributes']['to_entity_type']);
    }

    // -----------------------------------------------------------------------
    // Both-endpoints-hidden edge-existence metadata
    // -----------------------------------------------------------------------

    #[Test]
    public function both_endpoints_hidden_conceals_the_entire_edge(): void
    {
        $doc = $this->controllerWithBothEndpointsHidden($this->baselineAccount())->show('relationship', 5);
        $array = $doc->toArray();

        self::assertSame(404, $doc->statusCode);
        self::assertArrayNotHasKey('data', $array);
        self::assertSame('404', $array['errors'][0]['status']);
    }

    private function controllerWithBothEndpointsHidden(AccountInterface $account): JsonApiController
    {
        $endpointStorage = new PresetEntityStorage(
            [
                $this->endpoint(40, 'HiddenA', published: false),
                $this->endpoint(50, 'HiddenB', published: false),
            ],
            'endpoint_entity',
        );
        $relationshipStorage = new PresetEntityStorage(
            [$this->edge(5, '40', '50')],
            'relationship',
        );

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

        $accessHandler = new EntityAccessHandler([
            new RelationshipAccessPolicy(),
            new EndpointFixtureAccessPolicy(),
        ]);
        $accessHandler->addPolicy(new RelationshipEndpointVisibilityPolicy($etm, $accessHandler));

        return new JsonApiController($etm, new ResourceSerializer($etm), $accessHandler, $account);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function findResource(array $document, string $id): ?array
    {
        foreach ($document['data'] as $resource) {
            if ($resource['id'] === $id) {
                return $resource;
            }
        }

        return null;
    }
}
