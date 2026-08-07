<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\Form\FormDescriptorBuilder;
use Waaseyaa\Field\Form\FormFieldDescriptor;

#[CoversClass(FormDescriptorBuilder::class)]
class FormDescriptorBuilderTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a minimal FieldDefinition for a given entity type + bundle.
     */
    private function makeField(
        string $name,
        string $entityTypeId,
        string $bundle,
        string $type = 'string',
        string $label = '',
        string $group = '',
        bool $readOnly = false,
        bool $required = false,
    ): FieldDefinition {
        return new FieldDefinition(
            name: $name,
            type: $type,
            targetEntityTypeId: $entityTypeId,
            targetBundle: $bundle,
            label: $label,
            group: $group,
            readOnly: $readOnly,
            required: $required,
        );
    }

    /**
     * Build a registry stub that returns a fixed map of fields for one bundle.
     *
     * @param array<string, FieldDefinition> $fields
     */
    private function makeRegistry(
        string $entityTypeId,
        string $bundle,
        array $fields,
    ): FieldDefinitionRegistryInterface {
        return new class ($entityTypeId, $bundle, $fields) implements FieldDefinitionRegistryInterface {
            /** @param array<string, FieldDefinition> $fields */
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $bundle,
                private readonly array $fields,
            ) {}

            public function registerCoreFields(string $entityTypeId, array $fields): void {}

            public function mergeCoreFields(string $entityTypeId, array $fields): void {}

            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}

            public function coreFieldsFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundleFieldsFor(string $entityTypeId, string $bundle): array
            {
                if ($entityTypeId === $this->entityTypeId && $bundle === $this->bundle) {
                    return $this->fields;
                }

                return [];
            }

            public function bundleNamesFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
            {
                return [];
            }
        };
    }

    /**
     * Build an entity stub that returns a fixed map of values.
     *
     * @param array<string, mixed> $values
     */
    private function makeEntity(
        string $entityTypeId,
        string $bundle,
        array $values = [],
    ): EntityInterface {
        return new class ($entityTypeId, $bundle, $values) implements EntityInterface {
            /** @param array<string, mixed> $values */
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $bundle,
                private readonly array $values,
            ) {}

            public function id(): int|string|null
            {
                return null;
            }

            public function uuid(): string
            {
                return '';
            }

            public function label(): string
            {
                return '';
            }

            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }

            public function bundle(): string
            {
                return $this->bundle;
            }

            public function isNew(): bool
            {
                return true;
            }

            public function get(string $name): mixed
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return $this->values;
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function bundleWithThreeFieldsReturnsThreeDescriptorsInOrder(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $fieldA = $this->makeField('title', $entityTypeId, $bundle, label: 'Title');
        $fieldB = $this->makeField('body', $entityTypeId, $bundle, label: 'Body');
        $fieldC = $this->makeField('status', $entityTypeId, $bundle, label: 'Status');

        $registry = $this->makeRegistry($entityTypeId, $bundle, [
            'title' => $fieldA,
            'body'  => $fieldB,
            'status' => $fieldC,
        ]);

        $entity = $this->makeEntity($entityTypeId, $bundle, [
            'title'  => 'Hello',
            'body'   => 'World',
            'status' => true,
        ]);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertCount(3, $descriptors);
        $this->assertInstanceOf(FormFieldDescriptor::class, $descriptors[0]);
        $this->assertSame('title', $descriptors[0]->name);
        $this->assertSame('body', $descriptors[1]->name);
        $this->assertSame('status', $descriptors[2]->name);
    }

    #[Test]
    public function descriptorValuesMatchEntityGet(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('title', $entityTypeId, $bundle, label: 'Title');
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $field]);

        $entity = $this->makeEntity($entityTypeId, $bundle, ['title' => 'My Article']);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertSame('My Article', $descriptors[0]->value);
    }

    #[Test]
    public function readOnlyFalseWhenDefinitionFalseAndNoAccessHandler(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('title', $entityTypeId, $bundle, readOnly: false);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertFalse($descriptors[0]->readOnly);
    }

    #[Test]
    public function readOnlyTrueWhenDefinitionTrueRegardlessOfAccessHandler(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('uid', $entityTypeId, $bundle, readOnly: true);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['uid' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        // No access handler — readOnly from definition wins.
        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertTrue($descriptors[0]->readOnly);
    }

    #[Test]
    public function readOnlyBecomesTrueWhenAccessHandlerReturnsForbidden(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('secret', $entityTypeId, $bundle, readOnly: false);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['secret' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $account = $this->createStub(AccountInterface::class);

        $accessHandler = $this->createStub(EntityAccessHandler::class);
        $accessHandler
            ->method('checkFieldAccess')
            ->willReturn(AccessResult::forbidden('Not allowed.'));

        $builder = new FormDescriptorBuilder($registry, $accessHandler);
        $descriptors = $builder->build($entity, $bundle, $account);

        $this->assertTrue($descriptors[0]->readOnly);
    }

    #[Test]
    public function readOnlyStaysFalseWhenAccessHandlerReturnsAllowed(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('title', $entityTypeId, $bundle, readOnly: false);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $account = $this->createStub(AccountInterface::class);

        $accessHandler = $this->createStub(EntityAccessHandler::class);
        $accessHandler
            ->method('checkFieldAccess')
            ->willReturn(AccessResult::allowed());

        $builder = new FormDescriptorBuilder($registry, $accessHandler);
        $descriptors = $builder->build($entity, $bundle, $account);

        $this->assertFalse($descriptors[0]->readOnly);
    }

    #[Test]
    public function readOnlyRetainsDefinitionValueWhenAccessHandlerReturnsNeutral(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        // Field is readOnly=false — neutral should not flip it to true.
        $fieldFalse = $this->makeField('title', $entityTypeId, $bundle, readOnly: false);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $fieldFalse]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $account = $this->createStub(AccountInterface::class);

        $accessHandler = $this->createStub(EntityAccessHandler::class);
        $accessHandler
            ->method('checkFieldAccess')
            ->willReturn(AccessResult::neutral());

        $builder = new FormDescriptorBuilder($registry, $accessHandler);
        $descriptors = $builder->build($entity, $bundle, $account);

        $this->assertFalse($descriptors[0]->readOnly);
    }

    #[Test]
    public function emptyBundleReturnsEmptyListWithoutException(): void
    {
        $entityTypeId = 'node';
        $bundle = 'no_fields_here';

        // Registry returns [] for any unregistered bundle.
        $registry = $this->makeRegistry($entityTypeId, 'article', []);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $result = $builder->build($entity, $bundle);

        $this->assertSame([], $result);
    }

    #[Test]
    public function labelFallsBackToUcfirstNameWhenEmpty(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        // Label is empty string.
        $field = $this->makeField('created_at', $entityTypeId, $bundle, label: '');
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['created_at' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertSame('Created_at', $descriptors[0]->label);
    }

    #[Test]
    public function labelFromDefinitionUsedWhenNonEmpty(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('body', $entityTypeId, $bundle, label: 'Article Body');
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['body' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertSame('Article Body', $descriptors[0]->label);
    }

    #[Test]
    public function groupIsPreservedThroughDescriptor(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('status', $entityTypeId, $bundle, group: 'publishing');
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['status' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertSame('publishing', $descriptors[0]->group);
    }

    #[Test]
    public function requiredIsPreservedThroughDescriptor(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('title', $entityTypeId, $bundle, required: true);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertTrue($descriptors[0]->required);
    }

    #[Test]
    public function nullEntityValueResultsInNullDescriptorValue(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('optional_field', $entityTypeId, $bundle);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['optional_field' => $field]);

        // Entity has no value set for this field — get() returns null.
        $entity = $this->makeEntity($entityTypeId, $bundle, []);

        $builder = new FormDescriptorBuilder($registry);
        $descriptors = $builder->build($entity, $bundle);

        $this->assertNull($descriptors[0]->value);
    }

    #[Test]
    public function accessHandlerIgnoredWhenAccountIsNull(): void
    {
        $entityTypeId = 'node';
        $bundle = 'article';

        $field = $this->makeField('title', $entityTypeId, $bundle, readOnly: false);
        $registry = $this->makeRegistry($entityTypeId, $bundle, ['title' => $field]);
        $entity = $this->makeEntity($entityTypeId, $bundle);

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        // checkFieldAccess must never be called when account is null.
        $accessHandler
            ->expects($this->never())
            ->method('checkFieldAccess');

        $builder = new FormDescriptorBuilder($registry, $accessHandler);
        // account = null → access handler branch is skipped entirely.
        $descriptors = $builder->build($entity, $bundle, null);

        $this->assertFalse($descriptors[0]->readOnly);
    }
}
