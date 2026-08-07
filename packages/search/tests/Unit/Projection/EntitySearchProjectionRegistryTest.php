<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Projection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Search\Document\SearchDocument;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;
use Waaseyaa\Search\SearchIndexableInterface;

#[CoversClass(EntitySearchProjectionRegistry::class)]
final class EntitySearchProjectionRegistryTest extends TestCase
{
    #[Test]
    public function a_self_indexable_entity_is_returned_unprojected(): void
    {
        $entity = $this->selfIndexableEntity('custom:9');
        $projector = new ProjectionSpy(supports: true, document: new SearchDocument('node:1', 't', 'b'));
        $registry = new EntitySearchProjectionRegistry([$projector]);

        self::assertSame($entity, $registry->resolveIndexable($entity));
        self::assertSame('custom:9', $registry->documentIdFor($entity));
        self::assertSame(0, $projector->projectCalls, 'Self-indexable entities never consult projectors.');
    }

    #[Test]
    public function the_first_supporting_projector_owns_the_entity(): void
    {
        $document = new SearchDocument('node:7', 'First', 'body');
        $first = new ProjectionSpy(supports: true, document: $document);
        $second = new ProjectionSpy(supports: true, document: new SearchDocument('node:7', 'Second', 'body'));
        $registry = new EntitySearchProjectionRegistry([$first, $second]);

        self::assertSame($document, $registry->resolveIndexable($this->entity('node', 7)));
        self::assertSame(0, $second->projectCalls, 'Later projectors must not be consulted once one supports the entity.');
    }

    #[Test]
    public function a_supporting_projector_declining_the_entity_is_final(): void
    {
        $declining = new ProjectionSpy(supports: true, document: null);
        $fallback = new ProjectionSpy(supports: true, document: new SearchDocument('node:7', 'Fallback', 'body'));
        $registry = new EntitySearchProjectionRegistry([$declining, $fallback]);

        self::assertNull(
            $registry->resolveIndexable($this->entity('node', 7)),
            'A supporting projector that declines must not be overridden by later projectors.',
        );
        self::assertSame(0, $fallback->projectCalls);
    }

    #[Test]
    public function a_projector_cannot_return_a_noncanonical_document_id(): void
    {
        $registry = new EntitySearchProjectionRegistry([
            new ProjectionSpy(supports: true, document: new SearchDocument('custom-node-7', 'Title', 'Body')),
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('must return canonical document id node:7');

        $registry->resolveIndexable($this->entity('node', 7));
    }

    #[Test]
    public function an_unsupported_entity_resolves_to_nothing(): void
    {
        $registry = new EntitySearchProjectionRegistry([new ProjectionSpy(supports: false, document: null)]);
        $entity = $this->entity('taxonomy_term', 3);

        self::assertFalse($registry->supports($entity));
        self::assertNull($registry->resolveIndexable($entity));
        self::assertNull($registry->documentIdFor($entity));
    }

    #[Test]
    public function document_ids_for_projected_entities_use_the_canonical_entity_form_without_projecting(): void
    {
        $projector = new ProjectionSpy(supports: true, document: new SearchDocument('node:7', 't', 'b'));
        $registry = new EntitySearchProjectionRegistry([$projector]);

        self::assertSame('node:7', $registry->documentIdFor($this->entity('node', 7)));
        self::assertSame(0, $projector->projectCalls, 'Deletion-path document ids must not require a full projection.');
    }

    #[Test]
    public function an_entity_without_an_id_has_no_document_id(): void
    {
        $registry = new EntitySearchProjectionRegistry([new ProjectionSpy(supports: true, document: null)]);

        self::assertNull($registry->documentIdFor($this->entity('node', null)));
    }

    #[Test]
    public function only_projector_instances_are_accepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (runtime API boundary) */
        new EntitySearchProjectionRegistry([new \stdClass()]);
    }

    private function entity(string $entityTypeId, int|string|null $id): EntityInterface
    {
        return new class ($entityTypeId, $id) implements EntityInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly int|string|null $entityId,
            ) {}

            public function id(): int|string|null { return $this->entityId; }
            public function uuid(): string { return 'uuid'; }
            public function label(): string { return 'Label'; }
            public function getEntityTypeId(): string { return $this->entityTypeId; }
            public function bundle(): string { return $this->entityTypeId; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    private function selfIndexableEntity(string $documentId): EntityInterface&SearchIndexableInterface
    {
        return new class ($documentId) implements EntityInterface, SearchIndexableInterface {
            public function __construct(private readonly string $documentId) {}

            public function id(): int|string|null { return 9; }
            public function uuid(): string { return 'uuid'; }
            public function label(): string { return 'Label'; }
            public function getEntityTypeId(): string { return 'custom'; }
            public function bundle(): string { return 'custom'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
            public function getSearchDocumentId(): string { return $this->documentId; }
            public function toSearchDocument(): array { return ['title' => 'T', 'body' => 'B']; }
            public function toSearchMetadata(): array { return ['entity_type' => 'custom']; }
        };
    }
}

final class ProjectionSpy implements EntitySearchProjectorInterface
{
    public int $projectCalls = 0;

    public function __construct(
        private readonly bool $supports,
        private readonly ?SearchIndexableInterface $document,
    ) {}

    public function supports(EntityInterface $entity): bool
    {
        return $this->supports;
    }

    public function project(EntityInterface $entity): ?SearchIndexableInterface
    {
        ++$this->projectCalls;

        return $this->document;
    }
}
