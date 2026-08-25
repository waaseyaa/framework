<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;

/**
 * #2552: lossless HTML is an explicit opt-in on ResourceSerializer. If the
 * default flips, every JSON:API, GraphQL-adjacent, markdown, and admin
 * serialize() callsite starts serving stored markup.
 */
#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerLosslessHtmlDefaultTest extends TestCase
{
    private const STORED = '<div class="sfn-program-contact">x</div>';

    #[Test]
    public function losslessHtmlDefaultsToFalse(): void
    {
        $parameter = null;
        foreach (new ReflectionMethod(ResourceSerializer::class, 'serialize')->getParameters() as $candidate) {
            if ($candidate->getName() === 'losslessHtml') {
                $parameter = $candidate;
                break;
            }
        }

        self::assertNotNull($parameter, 'serialize() must keep the losslessHtml flag so the editor opt-in stays explicit.');
        self::assertTrue($parameter->isDefaultValueAvailable());
        self::assertFalse($parameter->getDefaultValue(), 'Lossless HTML must stay off unless a caller opts in.');
    }

    #[Test]
    public function serializeWithoutTheFlagKeepsTheSanitizedProjection(): void
    {
        $serializer = $this->serializer();
        $entity = $this->entity();

        $resource = $serializer->serialize($entity);

        self::assertSame('<div>x</div>', $resource->attributes['body']);
        self::assertStringNotContainsString('class=', $resource->attributes['body']);
    }

    #[Test]
    public function serializeWithLosslessHtmlTrueReturnsStoredBytesBecauseAuthorizationIsTheCallersJob(): void
    {
        $serializer = $this->serializer();
        $entity = $this->entity();

        $resource = $serializer->serialize($entity, losslessHtml: true);

        self::assertSame(self::STORED, $resource->attributes['body']);
    }

    private function serializer(): ResourceSerializer
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: ['body' => new FieldDefinition(name: 'body', type: 'text_long')],
        ));

        return new ResourceSerializer($manager);
    }

    private function entity(): TestEntity
    {
        return new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-lossless-default',
            'title' => 'T',
            'type' => 'article',
            'body' => self::STORED,
        ]);
    }
}
