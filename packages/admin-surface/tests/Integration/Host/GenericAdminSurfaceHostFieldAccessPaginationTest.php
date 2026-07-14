<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostFieldAccessPaginationTest extends TestCase
{
    #[Test]
    public function forbidden_filter_values_cannot_consume_the_visible_page(): void
    {
        $matchingForbidden = $this->filteredPage('needle');
        $nonMatchingForbidden = $this->filteredPage('other');

        self::assertTrue($matchingForbidden->ok);
        self::assertTrue($nonMatchingForbidden->ok);
        self::assertSame(1, $matchingForbidden->data['total']);
        self::assertSame(1, $nonMatchingForbidden->data['total']);
        self::assertSame(
            ['public-uuid'],
            array_column($matchingForbidden->data['entities'], 'id'),
            'A matching but Forbidden value must not consume the one-row page ahead of the public result.',
        );
        self::assertSame(
            ['public-uuid'],
            array_column($nonMatchingForbidden->data['entities'], 'id'),
        );
    }

    private function filteredPage(string $classifiedDescription): \Waaseyaa\AdminSurface\Host\AdminSurfaceResultData
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'access_oracle_document',
            label: 'Access oracle document',
            class: AccessOracleDocument::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            _fieldDefinitions: [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'classified' => ['type' => 'boolean'],
            ],
        );
        (new SqlSchemaHandler($entityType, $database))->ensureTable();

        $accessHandler = new EntityAccessHandler([$this->documentPolicy()]);
        $repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            new EventDispatcher(),
            database: $database,
            accessHandler: $accessHandler,
        );
        $repository->save($repository->create([
            'uuid' => 'classified-uuid',
            'title' => 'Classified',
            'description' => $classifiedDescription,
            'classified' => true,
        ]), validate: false);
        $repository->save($repository->create([
            'uuid' => 'public-uuid',
            'title' => 'Public',
            'description' => 'needle',
            'classified' => false,
        ]), validate: false);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('hasDefinition')->willReturn(true);
        $entityTypeManager->method('getDefinition')->willReturn($entityType);
        $entityTypeManager->method('resolveFieldDefinitions')->willReturn($entityType->getFieldDefinitions());
        $entityTypeManager->method('getRepository')->willReturn($repository);

        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);
        $request = Request::create('/');
        $request->attributes->set('_account', $this->account());
        self::assertNotNull($host->resolveSession($request));

        return $host->list('access_oracle_document', new SurfaceQuery(
            filters: [[
                'field' => 'description',
                'operator' => SurfaceFilterOperator::CONTAINS,
                'value' => 'needle',
            ]],
            limit: 1,
        ));
    }

    private function documentPolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'access_oracle_document';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('Documents are viewable.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('Documents are creatable.');
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'description' && $entity->get('classified') === true) {
                    return AccessResult::forbidden('Classified descriptions are hidden.');
                }

                return AccessResult::neutral('Public field.');
            }
        };
    }

    private function account(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['administrator']; }
            public function isAuthenticated(): bool { return true; }
        };
    }
}

final class AccessOracleDocument extends ContentEntityBase
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<string, mixed> $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = 'access_oracle_document',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
