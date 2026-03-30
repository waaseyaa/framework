<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Relationship\RelationshipAccessPolicy;

final class RelationshipAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new RelationshipAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'relationship';
    }

    protected function createEntityStub(): EntityInterface
    {
        return new class () implements EntityInterface {
            public function id(): int|string|null
            {
                return 1;
            }

            public function uuid(): string
            {
                return 'rel-uuid-001';
            }

            public function label(): string
            {
                return 'Test Relationship';
            }

            public function getEntityTypeId(): string
            {
                return 'relationship';
            }

            public function bundle(): string
            {
                return 'relationship';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return match ($name) {
                    'status' => 1,
                    default => null,
                };
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return ['id' => 1, 'status' => 1];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
