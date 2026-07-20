<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

require_once __DIR__ . '/../../src/Relationship.php';
require_once __DIR__ . '/../../src/VisibilityFilterInterface.php';
require_once __DIR__ . '/../../src/RelationshipTraversalService.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Relationship\AuthorizedRelationshipEdge;
use Waaseyaa\Relationship\AuthorizedRelationshipTraversal;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipAccessPolicy;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Relationship\VisibilityFilterInterface;

#[CoversClass(RelationshipTraversalService::class)]
final class RelationshipTraversalServiceTest extends TestCase
{
    public function testBrowsePublishedExcludesEdgesToUnpublishedNodeEndpoints(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $visibilityFilter = new TraversalVisibilityFilter();
        $service = new RelationshipTraversalService($manager, $database, $visibilityFilter);

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(1, $result['counts']['total']);
        $this->assertCount(1, $result['outbound']);
        $this->assertSame('3', $result['outbound'][0]['related_entity_id']);
    }

    public function testBrowsePublishedFailsClosedWithoutVisibilityFilter(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        // A published relationship pointing at an unpublished (draft) node.
        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // No visibility filter wired — the service cannot prove the related
        // node is public, so it must withhold the edge (fail-closed) rather
        // than leaking the draft node's label/path. Pre-fix this returned the
        // edge (isEntityPublic() defaulted to true).
        $service = new RelationshipTraversalService($manager, $database);

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(0, $result['counts']['total'], 'Unwired visibility filter must fail closed');
        $this->assertSame([], $result['outbound']);
        $relatedIds = array_map(
            static fn(array $edge): string => $edge['related_entity_id'],
            $result['outbound'],
        );
        $this->assertNotContains('2', $relatedIds, 'Draft node leaked through unfiltered traversal');
    }

    public function testTraversePublishedFailsClosedWithoutVisibilityFilter(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        // A published relationship pointing at an unpublished (draft) node.
        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // No visibility filter wired — traverse() must fail closed exactly like
        // browse(): withhold the edge rather than leak the draft endpoint's
        // identity (to_entity_type/to_entity_id) through the returned
        // Relationship entities. Pre-fix traverse() returned the edge.
        $service = new RelationshipTraversalService($manager, $database);

        $result = $service->traverse('node', 1, ['status' => 'published']);

        $this->assertSame([], $result, 'Unwired visibility filter must fail closed for traverse()');
    }

    public function testTraversePublishedExcludesEdgesToUnpublishedEndpoints(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipTraversalService($manager, $database, new TraversalVisibilityFilter());

        $result = $service->traverse('node', 1, ['status' => 'published']);

        $this->assertCount(1, $result, 'Edge to the draft endpoint must be withheld');
        $this->assertSame('3', new \Waaseyaa\Relationship\RelationshipTopologyReader()->read($result[0])?->toId);
    }

    public function testTraverseAllModeReturnsMixedStateEdgesUnfiltered(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // Explicit 'all' mode keeps browse() parity: no endpoint filtering.
        // Callers opting into 'all' own the exposure decision.
        $service = new RelationshipTraversalService($manager, $database);

        $result = $service->traverse('node', 1, ['status' => 'all']);

        $this->assertCount(2, $result);
    }

    public function testTraverseUnpublishedFailsClosedWithoutVisibilityFilter(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        // An unpublished relationship pointing at a draft node.
        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 0);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 0,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // No visibility filter wired: with nothing provable about endpoint
        // visibility in EITHER direction, unpublished mode must be as
        // fail-closed as published mode — "not provably public" is NOT
        // "provably draft", so returning the edges would leak draft endpoint
        // identities to an unwired caller.
        $service = new RelationshipTraversalService($manager, $database);

        $result = $service->traverse('node', 1, ['status' => 'unpublished']);

        $this->assertSame([], $result, 'Unwired visibility filter must fail closed for unpublished mode too');
    }

    public function testTraverseUnpublishedReturnsDraftEndpointsWithWiredFilter(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 0);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 0);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 0,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 0,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // Wired filter, browse parity: unpublished mode keeps provably
        // NON-public endpoints and drops provably public ones.
        $service = new RelationshipTraversalService($manager, $database, new TraversalVisibilityFilter());

        $result = $service->traverse('node', 1, ['status' => 'unpublished']);

        $this->assertCount(1, $result);
        $this->assertSame('2', new \Waaseyaa\Relationship\RelationshipTopologyReader()->read($result[0])?->toId);
    }

    public function testBrowseAllIncludesMixedStateEndpointsDeterministically(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipTraversalService($manager, $database);
        $result = $service->browse('node', 1, ['status' => 'all']);

        $this->assertSame(2, $result['counts']['total']);
        $this->assertCount(2, $result['outbound']);
        $this->assertSame('2', $result['outbound'][0]['related_entity_id']);
        $this->assertSame('3', $result['outbound'][1]['related_entity_id']);
    }

    public function testBrowseCachesRepeatedRelatedEntitySummaryLoadsAcrossDirections(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 3, 'node', '2', 'node', '1', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'context',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            3 => new Relationship([
                'rid' => 3,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '2',
                'to_entity_type' => 'node',
                'to_entity_id' => '1',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Shared Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $visibilityFilter = new TraversalVisibilityFilter();
        $service = new RelationshipTraversalService($manager, $database, $visibilityFilter);
        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(3, $result['counts']['total']);
        $this->assertSame(0, $nodeStorage->loadCalls);
        $this->assertSame(1, $nodeStorage->loadMultipleCalls);
    }

    public function testBrowseUsesSingleLoadMultipleForManyDistinctRelatedNodes(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $relationshipStorageMap = [];
        $rid = 1;
        foreach (['2', '3', '4', '5'] as $targetId) {
            $this->insertRelationship($database, $rid, 'node', '1', 'node', $targetId, 1);
            $relationshipStorageMap[$rid] = new Relationship([
                'rid' => $rid,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => $targetId,
                'directionality' => 'directed',
                'status' => 1,
            ]);
            $rid++;
        }

        $relationshipStorage = new TraversalRelationshipStorage($relationshipStorageMap);
        $nodeEntities = [];
        foreach (['2', '3', '4', '5'] as $targetId) {
            $nodeEntities[$targetId] = new TraversalTestEntity('node', 'article', (int) $targetId, 'Node ' . $targetId, [
                'nid' => (int) $targetId,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]);
        }
        $nodeStorage = new TraversalEntityStorage($nodeEntities);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipTraversalService($manager, $database, new TraversalVisibilityFilter());
        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(4, $result['counts']['total']);
        $this->assertSame(0, $nodeStorage->loadCalls);
        $this->assertSame(1, $nodeStorage->loadMultipleCalls);
        $this->assertSame([4], $nodeStorage->loadMultipleBatchSizes);
    }

    // --- R7 WP2 (audit R5 residual #1): access-aware endpoint visibility ---

    public function testBrowsePublishedDropsPublishedButAccessRestrictedEndpoint(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        // Node 2 is PUBLISHED (workflow-public) but access-restricted for this
        // account — the leak this PR closes: WorkflowVisibilityFilter alone
        // would disclose it because it only ever checks publish status.
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Restricted Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $accessHandler = new EntityAccessHandler([new TraversalForbidNodeAccessPolicy(['2'])]);
        $account = new TraversalTestAccount();
        $service = new RelationshipTraversalService(
            $manager,
            $database,
            new TraversalVisibilityFilter(),
            $accessHandler,
            $account,
        );

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(0, $result['counts']['total'], 'Published-but-access-restricted endpoint must not leak through discovery');
        $this->assertSame([], $result['outbound']);
    }

    public function testBrowsePublishedKeepsViewableEndpointWithAccessHandlerWired(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Viewable Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        // Positive control: nothing is forbidden, so wiring the access gate
        // must not over-drop a legitimately viewable published endpoint.
        $accessHandler = new EntityAccessHandler([new TraversalForbidNodeAccessPolicy([])]);
        $account = new TraversalTestAccount();
        $service = new RelationshipTraversalService(
            $manager,
            $database,
            new TraversalVisibilityFilter(),
            $accessHandler,
            $account,
        );

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(1, $result['counts']['total']);
        $this->assertSame('2', $result['outbound'][0]['related_entity_id']);
    }

    public function testBrowsePublishedFailsClosedWhenAccessGateWiredButEndpointUnloadable(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '404', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '404',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        // Endpoint node 404 does not exist in storage.
        $nodeStorage = new TraversalEntityStorage([]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $accessHandler = new EntityAccessHandler([new TraversalForbidNodeAccessPolicy([])]);
        $account = new TraversalTestAccount();
        $service = new RelationshipTraversalService(
            $manager,
            $database,
            new TraversalVisibilityFilter(),
            $accessHandler,
            $account,
        );

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(0, $result['counts']['total'], 'Unloadable endpoint must fail closed, never disclosed');
    }

    public function testTraversePublishedDropsPublishedButAccessRestrictedEndpoint(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Restricted Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $accessHandler = new EntityAccessHandler([new TraversalForbidNodeAccessPolicy(['2'])]);
        $account = new TraversalTestAccount();
        $service = new RelationshipTraversalService(
            $manager,
            $database,
            new TraversalVisibilityFilter(),
            $accessHandler,
            $account,
        );

        $result = $service->traverse('node', 1, ['status' => 'published']);

        $this->assertSame([], $result, 'Published-but-access-restricted endpoint must not leak via traverse() either');
    }

    public function testAuthorizedTraversalReturnsOnlyLiveViewableMembershipEdgesWithoutConsumerCapabilities(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'user', '7', 'group', '10', 1, 'group_membership');
        $this->insertRelationship($database, 2, 'user', '8', 'group', '10', 1, 'group_membership');
        $this->insertRelationship($database, 3, 'user', '9', 'group', '10', 0, 'group_membership');
        $this->insertRelationship($database, 4, 'user', '11', 'group', '10', 1, 'group_membership');

        $relationshipStorage = new TraversalRelationshipStorage([
            1 => $this->membership(1, '7', 1),
            2 => $this->membership(2, '8', 1),
            3 => $this->membership(3, '9', 0),
            4 => $this->membership(4, '11', 1),
        ]);
        $groupStorage = new TraversalEntityStorage([
            '10' => new TraversalTestEntity('group', 'department', 10, 'Language Department', ['id' => 10]),
        ]);
        $userStorage = new TraversalEntityStorage([
            '7' => new TraversalTestEntity('user', 'user', 7, 'Visible Member', ['uid' => 7]),
            '8' => new TraversalTestEntity('user', 'user', 8, 'Hidden Member', ['uid' => 8]),
            '9' => new TraversalTestEntity('user', 'user', 9, 'Former Member', ['uid' => 9]),
            '11' => new TraversalTestEntity('user', 'user', 11, 'Hidden Edge Member', ['uid' => 11]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'group' => $groupStorage,
            'user' => $userStorage,
        ]);
        $principal = new AuthorizationPrincipal(
            accountId: 42,
            authenticated: true,
            roles: ['member-manager'],
            permissions: ['access content'],
            claimsGeneration: 'gap-2-test',
        );
        $accessHandler = new EntityAccessHandler([
            new class implements AccessPolicyInterface {
                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group' || $entityTypeId === 'user';
                }

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    if ($operation !== 'view') {
                        return AccessResult::neutral();
                    }

                    return $entity->getEntityTypeId() === 'user' && (string) $entity->id() === '8'
                        ? AccessResult::forbidden('Hidden member.')
                        : AccessResult::allowed('Source or member is viewable.');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
            new class implements AccessPolicyInterface {
                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'relationship';
                }

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' && (string) $entity->id() === '4'
                        ? AccessResult::forbidden('Relationship edge is hidden.')
                        : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
            new RelationshipAccessPolicy(),
        ]);
        $scope = new AccountFieldReadScope();
        $service = new AuthorizedRelationshipTraversal($manager, $database, $accessHandler, $scope);

        $edges = $service->edges($principal, 'group', '10', [
            'direction' => 'inbound',
            'relationship_types' => ['group_membership'],
        ]);

        self::assertCount(1, $edges);
        self::assertContainsOnlyInstancesOf(AuthorizedRelationshipEdge::class, $edges);
        self::assertSame('7', $edges[0]->relatedEntityId);
        self::assertSame('Visible Member', $edges[0]->relatedEntityLabel);
        self::assertNull($scope->current(), 'The facade must restore account scope after traversal.');
    }

    public function testAuthorizedTraversalConcealsViewDeniedSourceAsEmpty(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);
        $this->insertRelationship($database, 1, 'user', '7', 'group', '10', 1, 'group_membership');

        $manager = new TraversalEntityTypeManager([
            'relationship' => new TraversalRelationshipStorage([1 => $this->membership(1, '7', 1)]),
            'group' => new TraversalEntityStorage([
                '10' => new TraversalTestEntity('group', 'department', 10, 'Hidden Department', ['id' => 10]),
            ]),
            'user' => new TraversalEntityStorage([
                '7' => new TraversalTestEntity('user', 'user', 7, 'Member', ['uid' => 7]),
            ]),
        ]);
        $principal = new AuthorizationPrincipal(42, true, ['authenticated'], ['access content'], 'gap-2-test');
        $accessHandler = new EntityAccessHandler([
            new class implements AccessPolicyInterface {
                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group' || $entityTypeId === 'user';
                }

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' && $entity->getEntityTypeId() === 'user'
                        ? AccessResult::allowed('Member is viewable.')
                        : AccessResult::forbidden('Source is hidden.');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
            new RelationshipAccessPolicy(),
        ]);

        $service = new AuthorizedRelationshipTraversal($manager, $database, $accessHandler, new AccountFieldReadScope());

        self::assertSame([], $service->edges($principal, 'group', '10', [
            'direction' => 'inbound',
            'relationship_types' => ['group_membership'],
        ]));
    }

    private function createRelationshipTable(DBALDatabase $database): void
    {
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
            CREATE TABLE relationship (
              rid INTEGER PRIMARY KEY,
              relationship_type TEXT NOT NULL,
              from_entity_type TEXT NOT NULL,
              from_entity_id TEXT NOT NULL,
              to_entity_type TEXT NOT NULL,
              to_entity_id TEXT NOT NULL,
              directionality TEXT NOT NULL DEFAULT 'directed',
              status INTEGER NOT NULL DEFAULT 1,
              weight REAL DEFAULT NULL,
              confidence REAL DEFAULT NULL,
              start_date INTEGER DEFAULT NULL,
              end_date INTEGER DEFAULT NULL
            )
            SQL);
    }

    private function insertRelationship(
        DBALDatabase $database,
        int $rid,
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        int $status,
        string $relationshipType = 'references',
    ): void {
        $database->query(
            'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, $relationshipType, $fromType, $fromId, $toType, $toId, 'directed', $status],
        );
    }

    private function membership(int $rid, string $userId, int $status): Relationship
    {
        return new Relationship([
            'rid' => $rid,
            'relationship_type' => 'group_membership',
            'from_entity_type' => 'user',
            'from_entity_id' => $userId,
            'to_entity_type' => 'group',
            'to_entity_id' => '10',
            'directionality' => 'directed',
            'status' => $status,
        ]);
    }
}

final class TraversalEntityTypeManager implements EntityTypeManagerInterface
{
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return [];
    }
    /**
     * @param array<string, EntityStorageInterface> $storages
     */
    public function __construct(private readonly array $storages) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getDefinitions(): array
    {
        return [];
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->storages[$entityTypeId]);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        if (!isset($this->storages[$entityTypeId])) {
            throw new \InvalidArgumentException(sprintf('Storage missing for "%s".', $entityTypeId));
        }

        return $this->storages[$entityTypeId];
    }

    public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        // C-22 WP3: read path now goes through the canonical repository.
        return new StorageBackedStubRepository($this->getStorage($entityTypeId));
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \RuntimeException('Not needed in test.');
    }
}

final class TraversalRelationshipStorage implements EntityStorageInterface
{
    /**
     * @param array<int, Relationship> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        $resolved = (int) $id;
        return $this->entities[$resolved] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $result = [];
        foreach ($ids as $id) {
            $resolved = (int) $id;
            if (isset($this->entities[$resolved])) {
                $result[$resolved] = $this->entities[$resolved];
            }
        }

        return $result;
    }

    public function save(EntityInterface $entity): int
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function delete(array $entities): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getQuery(): EntityQueryInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getEntityTypeId(): string
    {
        return 'relationship';
    }
}

final class TraversalEntityStorage implements EntityStorageInterface
{
    public int $loadCalls = 0;

    public int $loadMultipleCalls = 0;

    /** @var list<int> */
    public array $loadMultipleBatchSizes = [];

    /**
     * @param array<string, TraversalTestEntity> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        $this->loadCalls++;
        return $this->entities[(string) $id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        $this->loadMultipleCalls++;
        $this->loadMultipleBatchSizes[] = count($ids);
        $result = [];
        foreach ($ids as $id) {
            $stringId = (string) $id;
            if (isset($this->entities[$stringId])) {
                $result[$stringId] = $this->entities[$stringId];
            }
        }

        return $result;
    }

    public function save(EntityInterface $entity): int
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function delete(array $entities): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getQuery(): EntityQueryInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }
}

final class TraversalVisibilityFilter implements VisibilityFilterInterface
{
    public function isEntityPublic(string $entityType, array $values): bool
    {
        if ($entityType === 'node') {
            $state = $values['workflow_state'] ?? null;
            if ($state !== null) {
                return $state === 'published';
            }

            return (int) ($values['status'] ?? 0) === 1;
        }

        return true;
    }
}

final class TraversalTestAccount implements AccountInterface
{
    public function id(): int|string
    {
        return 42;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return ['authenticated'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}

/**
 * Allows 'view' on every 'node' entity except the ids listed in
 * $forbiddenIds — mirrors a real restrictive AccessPolicyInterface
 * (e.g. NodeAccessPolicy denying a private node) without depending on
 * waaseyaa/node from this package's tests.
 */
final class TraversalForbidNodeAccessPolicy implements AccessPolicyInterface
{
    /**
     * @param list<string> $forbiddenIds
     */
    public function __construct(private readonly array $forbiddenIds) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        if (in_array((string) $entity->id(), $this->forbiddenIds, true)) {
            return AccessResult::forbidden('Node is access-restricted for this test.');
        }

        return AccessResult::allowed('Node is viewable.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}

final class TraversalTestEntity implements EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $bundle,
        private readonly int|string $id,
        private readonly string $label,
        private readonly array $values,
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return '';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function bundle(): string
    {
        return $this->bundle;
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;
        return $this;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
