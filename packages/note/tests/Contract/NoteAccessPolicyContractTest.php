<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Note\NoteAccessPolicy;

final class NoteAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new NoteAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'note';
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
                return 'note-uuid-001';
            }

            public function label(): string
            {
                return 'Test Note';
            }

            public function getEntityTypeId(): string
            {
                return 'note';
            }

            public function bundle(): string
            {
                return 'note';
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
                return ['id' => 1, 'uuid' => 'note-uuid-001', 'title' => 'Test Note'];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
