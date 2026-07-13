<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Republish;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\Republish\RepublishMarker;

/**
 * @covers \Waaseyaa\Workflows\Republish\RepublishMarker
 */
#[CoversClass(RepublishMarker::class)]
final class RepublishMarkerTest extends TestCase
{
    private function entity(): EntityInterface
    {
        return new class implements EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'u-1'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    #[Test]
    public function an_unarmed_entity_does_not_consume(): void
    {
        $marker = new RepublishMarker();
        $entity = $this->entity();

        $this->assertFalse($marker->consume($entity));
    }

    #[Test]
    public function an_armed_entity_consumes_exactly_once(): void
    {
        $marker = new RepublishMarker();
        $entity = $this->entity();

        $marker->arm($entity);

        $this->assertTrue($marker->consume($entity));
        $this->assertFalse($marker->consume($entity), 'A second consume() without an intervening arm() must not re-fire.');
    }

    #[Test]
    public function arming_is_per_object_not_per_id(): void
    {
        $marker = new RepublishMarker();
        $armedEntity = $this->entity();
        $otherEntity = $this->entity(); // same id() (1), distinct object

        $marker->arm($armedEntity);

        $this->assertFalse($marker->consume($otherEntity), 'A distinct object instance sharing the same entity id must not consume an unrelated arm.');
        $this->assertTrue($marker->consume($armedEntity));
    }

    #[Test]
    public function clear_removes_an_arm_without_acting_on_it(): void
    {
        // Fix-wave (#1920 PR-2 adversarial review, stale-arm fix): the
        // guard clears unconditionally at the start of every guarded save,
        // so an arm left behind by a PRE_SAVE-aborted save can never be
        // consumed by a later, unrelated save of the same object.
        $marker = new RepublishMarker();
        $entity = $this->entity();

        $marker->arm($entity);
        $marker->clear($entity);

        $this->assertFalse($marker->consume($entity));
    }

    #[Test]
    public function clear_on_an_unarmed_entity_is_a_no_op(): void
    {
        $marker = new RepublishMarker();
        $entity = $this->entity();

        $marker->clear($entity);

        $this->assertFalse($marker->consume($entity));
    }
}
