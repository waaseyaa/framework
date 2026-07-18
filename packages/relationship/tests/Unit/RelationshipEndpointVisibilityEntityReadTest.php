<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipAccessPolicy;
use Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\ArrayAccount;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureAccessPolicy;
use Waaseyaa\Relationship\Tests\Fixtures\EndpointFixtureEntity;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityRepository;
use Waaseyaa\Relationship\Tests\Fixtures\PresetEntityStorage;

/**
 * Audit-remediation R5, third read path: the MCP `entity.read` tool
 * ({@see EntityReadTool}) reads a relationship entity through
 * {@see \Waaseyaa\AI\Tools\AbstractAgentTool::applyFieldAccessFilter()}, which
 * calls the SAME {@see EntityAccessHandler::filterFields()} the JSON:API
 * serializer uses. Without {@see RelationshipEndpointVisibilityPolicy}
 * registered, `entity.read` on a relationship leaked the hidden endpoint's
 * identity exactly like the REST paths (see
 * {@see RelationshipEndpointVisibilityRestTest}).
 */
#[CoversNothing]
final class RelationshipEndpointVisibilityEntityReadTest extends TestCase
{
    private ?EntityAccessHandler $fieldReadAccessHandler = null;

    private function tool(): EntityReadTool
    {
        $endpointStorage = new PresetEntityStorage(
            [
                new EndpointFixtureEntity(['id' => 10, 'uuid' => 'endpoint-uuid-10', 'title' => 'Public', 'published' => true]),
                new EndpointFixtureEntity(['id' => 20, 'uuid' => 'endpoint-uuid-20', 'title' => 'Hidden', 'published' => false]),
            ],
            'endpoint_entity',
        );
        $relationshipStorage = new PresetEntityStorage(
            [
                new Relationship([
                    'rid' => 1,
                    'uuid' => 'edge-uuid-1',
                    'relationship_type' => 'references',
                    'from_entity_type' => 'endpoint_entity',
                    'from_entity_id' => '10',
                    'to_entity_type' => 'endpoint_entity',
                    'to_entity_id' => '20',
                    'directionality' => 'directed',
                    'status' => 1,
                ]),
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

        // Late-registration wiring, mirroring RelationshipServiceProvider::configureHttpKernel().
        $accessHandler = new EntityAccessHandler([
            new RelationshipAccessPolicy(),
            new EndpointFixtureAccessPolicy(),
        ]);
        $accessHandler->addPolicy(new RelationshipEndpointVisibilityPolicy($etm, $accessHandler));
        $this->fieldReadAccessHandler = $accessHandler;

        $tool = new EntityReadTool($etm);
        $tool->setAccessHandler($accessHandler);

        return $tool;
    }

    private function baselineAccount(): AccountInterface
    {
        return new ArrayAccount(0, ['access content', 'tool.entity.read']);
    }

    private function adminAccount(): AccountInterface
    {
        return new ArrayAccount(0, ['access content', 'administer nodes', 'tool.entity.read']);
    }

    /** @return array<string, mixed> */
    private function readValues(AccountInterface $account): array
    {
        if (!$account instanceof AuthorizationPrincipalInterface) {
            throw new \LogicException('Relationship entity.read tests require an immutable authorization principal.');
        }
        $tool = $this->tool();
        $accessHandler = $this->fieldReadAccessHandler ?? throw new \LogicException('Relationship access handler is unavailable.');
        $scope = new AccountFieldReadScope();
        $priorGuard = EntityReadRuntime::guard();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            $accessHandler->checkProtectedFieldRead(...),
        ));
        try {
            $result = $scope->run(
                $account,
                static fn() => $tool->execute(['entity_type' => 'relationship', 'id' => 1], $account),
            );
        } finally {
            EntityReadRuntime::installGuard($priorGuard);
        }
        self::assertFalse($result->isError, 'entity.read should succeed for a viewable relationship.');
        $data = $result->content[0]['data'] ?? [];
        self::assertIsArray($data);

        return \is_array($data['values'] ?? null) ? $data['values'] : [];
    }

    #[Test]
    public function entity_read_redacts_hidden_to_endpoint_for_baseline_account(): void
    {
        $values = $this->readValues($this->baselineAccount());

        self::assertArrayNotHasKey(
            'to_entity_id',
            $values,
            'entity.read must not leak the hidden endpoint (#20, unpublished) id to a baseline account.',
        );
        self::assertArrayNotHasKey('to_entity_type', $values);
        self::assertSame('10', $values['from_entity_id']);
        self::assertSame('endpoint_entity', $values['from_entity_type']);
    }

    #[Test]
    public function entity_read_includes_hidden_endpoint_for_privileged_account(): void
    {
        $values = $this->readValues($this->adminAccount());

        self::assertSame('20', $values['to_entity_id']);
        self::assertSame('endpoint_entity', $values['to_entity_type']);
    }
}
