<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Exception\MissingQueryAccountException;

#[CoversClass(MissingQueryAccountException::class)]
final class MissingQueryAccountExceptionTest extends TestCase
{
    #[Test]
    public function forQueryMessageNamesEntityTypeAndBothResolutionPaths(): void
    {
        $entityType = $this->makeEntityType('article');

        $exception = MissingQueryAccountException::forQuery($entityType);

        $message = $exception->getMessage();

        self::assertStringContainsString(
            '"article"',
            $message,
            'Message must quote the entity type id so the failing query is identifiable.',
        );
        self::assertStringContainsString(
            'setAccount()',
            $message,
            'Message must name the setAccount() resolution path.',
        );
        self::assertStringContainsString(
            'accessCheck(false)',
            $message,
            'Message must name the accessCheck(false) opt-out path.',
        );
    }

    #[Test]
    public function instanceIsRuntimeException(): void
    {
        $exception = MissingQueryAccountException::forQuery($this->makeEntityType('node'));

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function constructorIsPrivateSoFactoryIsTheOnlyConstructionRoute(): void
    {
        $reflection = new \ReflectionClass(MissingQueryAccountException::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor, 'Exception must declare a constructor.');
        self::assertTrue(
            $constructor->isPrivate(),
            'Constructor must be private — forQuery() is the only sanctioned construction path.',
        );
    }

    private function makeEntityType(string $id): EntityType
    {
        /** @var class-string<EntityInterface> $entityClass */
        $entityClass = EntityInterface::class;

        return new EntityType(
            id: $id,
            label: ucfirst($id),
            class: $entityClass,
            storageClass: 'Waaseyaa\\EntityStorage\\EntityRepository',
            keys: ['id' => 'id'],
        );
    }
}
