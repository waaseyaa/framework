<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Taxonomy\TermAccessPolicy;

final class TermAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new TermAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'taxonomy_term';
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
                return 'term-uuid-001';
            }

            public function label(): string
            {
                return 'Test Term';
            }

            public function getEntityTypeId(): string
            {
                return 'taxonomy_term';
            }

            public function bundle(): string
            {
                return 'tags';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return ['id' => 1, 'uuid' => 'term-uuid-001', 'name' => 'Test Term'];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
