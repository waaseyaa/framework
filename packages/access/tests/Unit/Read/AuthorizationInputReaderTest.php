<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Read;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Read\AuthorizationInputReader;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;

#[CoversClass(AuthorizationInputReader::class)]
final class AuthorizationInputReaderTest extends TestCase
{
    #[Test]
    public function itReturnsOnlyTheCompiledAuthorizationInputFieldsRegardlessOfTheirReadLevel(): void
    {
        $entity = $this->sealedEntity([
            'title' => 'Public title',
            'author' => 42,
            'workflow_state' => 'draft',
        ], authorizationInputs: ['author', 'workflow_state']);

        $values = new AuthorizationInputReader()->read($entity);

        self::assertSame(['author' => 42, 'workflow_state' => 'draft'], $values);
    }

    #[Test]
    public function itReturnsAnEmptyArrayWhenTheEntityDeclaresNoAuthorizationInputs(): void
    {
        $entity = $this->sealedEntity(['title' => 'Public title'], authorizationInputs: []);

        self::assertSame([], new AuthorizationInputReader()->read($entity));
    }

    /** @param array<string, mixed> $values @param list<string> $authorizationInputs */
    private function sealedEntity(array $values, array $authorizationInputs): AuthorizationInputReaderTestEntity
    {
        $levels = [];
        foreach (array_keys($values) as $field) {
            $levels[$field] = \in_array($field, $authorizationInputs, true) ? FieldReadLevel::Protected : FieldReadLevel::Public;
        }
        $layout = new EntityReadLayout(new EntityReadLayoutGeneration(), $levels, $authorizationInputs);
        $structure = new EntityStructure(
            entityTypeId: 'fixture',
            bundleId: 'fixture',
            id: $values['id'] ?? null,
            uuid: null,
            fieldNames: array_keys($values),
        );
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: $layout,
            structure: $structure,
            entityTypeId: 'fixture',
            entityKeys: ['id' => 'id', 'label' => 'title'],
        );
        $entity = $boundary->installer()->instantiate(AuthorizationInputReaderTestEntity::class, $payload);
        self::assertInstanceOf(AuthorizationInputReaderTestEntity::class, $entity);

        return $entity;
    }
}

final class AuthorizationInputReaderTestEntity extends EntityBase
{
    protected string $entityTypeId = 'fixture';
}
