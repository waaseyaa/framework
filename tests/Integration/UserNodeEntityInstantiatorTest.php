<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\Node\Node;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\Tests\Support\ProtectedFieldRead;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

#[CoversNothing]
final class UserNodeEntityInstantiatorTest extends TestCase
{
    #[Test]
    public function entityInstantiatorLoadsUserViaFromStorage(): void
    {
        $entityType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: [
                'id' => 'uid',
                'uuid' => 'uuid',
                'label' => 'name',
            ],
        );

        $entity = new EntityInstantiator($entityType)->instantiate(User::class, [
            'uid' => 5,
            'name' => 'entity',
            'mail' => 'e@example.test',
            'status' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $this->assertInstanceOf(User::class, $entity);
        $this->assertSame(5, $entity->id());
        $admin = AuthorizationPrincipalFactory::authenticated(permissions: ['administer users']);
        $this->assertSame('entity', ProtectedFieldRead::run([new UserAccessPolicy()], $admin, $entity->getName(...)));
    }

    #[Test]
    public function entityInstantiatorLoadsNodeViaFromStorage(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Node',
            class: Node::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        );

        $entity = new EntityInstantiator($entityType)->instantiate(Node::class, [
            'nid' => 9,
            'type' => 'page',
            'title' => 'Hello',
            'uid' => 1,
            'uuid' => '660e8400-e29b-41d4-a716-446655440001',
        ]);

        $this->assertInstanceOf(Node::class, $entity);
        $this->assertSame(9, $entity->id());
        $this->assertSame('Hello', $entity->getTitle());
        $this->assertSame('page', $entity->getType());
    }
}
