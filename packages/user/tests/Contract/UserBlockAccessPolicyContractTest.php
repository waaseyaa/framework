<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\User\UserBlockAccessPolicy;

final class UserBlockAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new UserBlockAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'user_block';
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
                return 'block-uuid-001';
            }

            public function label(): string
            {
                return 'Test Block';
            }

            public function getEntityTypeId(): string
            {
                return 'user_block';
            }

            public function bundle(): string
            {
                return 'user_block';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return match ($name) {
                    'blocker_id' => 99,
                    default => null,
                };
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return ['id' => 1, 'blocker_id' => 99];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
