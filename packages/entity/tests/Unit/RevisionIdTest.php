<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\RevisionId;
use Waaseyaa\Entity\RevisionMetadata;

#[CoversClass(RevisionId::class)]
final class RevisionIdTest extends TestCase
{
    #[Test]
    public function prefers_legacy_int_revision_id(): void
    {
        $entity = new class implements RevisionableInterface, EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'x'; }
            public function language(): string { return 'en'; }
            public function getEntityTypeId(): string { return 'n'; }
            public function bundle(): string { return 'n'; }
            public function isNew(): bool { return false; }
            public function label(): string { return ''; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function getRevisionId(): ?int { return 7; }
            public function setNewRevision(bool $value): void {}
            public function isNewRevision(): ?bool { return null; }
            public function isDefaultRevision(): bool { return true; }
            public function isLatestRevision(): bool { return true; }
            public function setRevisionLog(?string $log): void {}
            public function getRevisionLog(): ?string { return null; }
        };

        self::assertSame(7, RevisionId::of($entity));
    }

    #[Test]
    public function coerces_digit_string_revision_ids(): void
    {
        $entity = new class implements RevisionableEntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'x'; }
            public function language(): string { return 'en'; }
            public function getEntityTypeId(): string { return 'n'; }
            public function bundle(): string { return 'n'; }
            public function isNew(): bool { return false; }
            public function label(): string { return ''; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function revisionId(): int|string|null { return '12'; }
            public function isCurrentRevision(): bool { return true; }
            public function revisionMetadata(): ?RevisionMetadata { return null; }
        };

        self::assertSame(12, RevisionId::of($entity));
    }

    #[Test]
    public function treats_zero_and_empty_as_missing(): void
    {
        $entity = new class implements RevisionableEntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'x'; }
            public function language(): string { return 'en'; }
            public function getEntityTypeId(): string { return 'n'; }
            public function bundle(): string { return 'n'; }
            public function isNew(): bool { return false; }
            public function label(): string { return ''; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function revisionId(): int|string|null { return 0; }
            public function isCurrentRevision(): bool { return true; }
            public function revisionMetadata(): ?RevisionMetadata { return null; }
        };

        self::assertNull(RevisionId::of($entity));
    }
}
