<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * Validates the auto-generated GraphQL schema structure.
 *
 * These tests verify that SchemaFactory produces correct query fields,
 * mutation fields, type mappings, and input types for registered entity types.
 */
#[CoversNothing]
final class SchemaValidationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: EntityBase::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'boolean'],
                'created' => ['type' => 'timestamp'],
                'view_count' => ['type' => 'integer'],
                'rating' => ['type' => 'float'],
            ],
        );
        $this->entityTypeManager->registerCoreEntityType($articleType);
    }

    private function buildSchema(): \GraphQL\Type\Schema
    {
        $accessHandler = new EntityAccessHandler([]);
        $account = $this->createStub(AccountInterface::class);
        $guard = new GraphQlAccessGuard($accessHandler, $account);
        $resolver = new EntityResolver($this->entityTypeManager, $guard);
        $referenceLoader = new ReferenceLoader($this->entityTypeManager, $guard);
        $factory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            entityResolver: $resolver,
            referenceLoader: $referenceLoader,
        );

        return $factory->build();
    }

    #[Test]
    public function queryFieldsAreGeneratedForEntityType(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();

        self::assertNotNull($queryType);

        // Single query field
        self::assertTrue($queryType->hasField('article'));
        $singleField = $queryType->getField('article');
        $singleArgs = $singleField->args;
        $argNames = array_map(fn($a) => $a->name, $singleArgs);
        self::assertContains('id', $argNames);

        // The id arg should be NonNull(ID)
        $idArg = $singleField->getArg('id');
        self::assertNotNull($idArg);
        $idArgType = $idArg->getType();
        self::assertInstanceOf(NonNull::class, $idArgType);

        // List query field
        self::assertTrue($queryType->hasField('articleList'));
        $listField = $queryType->getField('articleList');
        $listArgNames = array_map(fn($a) => $a->name, $listField->args);
        self::assertContains('filter', $listArgNames);
        self::assertContains('sort', $listArgNames);
        self::assertContains('offset', $listArgNames);
        self::assertContains('limit', $listArgNames);
    }

    #[Test]
    public function listResultTypeHasItemsAndTotal(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('articleList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertSame('ArticleListResult', $listType->name);
        self::assertTrue($listType->hasField('items'));
        self::assertTrue($listType->hasField('total'));

        // items should be a list type
        $itemsField = $listType->getField('items');
        $itemsType = $itemsField->getType();
        self::assertInstanceOf(NonNull::class, $itemsType);
        $innerList = $itemsType->getWrappedType();
        self::assertInstanceOf(ListOfType::class, $innerList);

        // total should be NonNull(Int)
        $totalField = $listType->getField('total');
        $totalType = $totalField->getType();
        self::assertInstanceOf(NonNull::class, $totalType);
        self::assertSame('Int', $totalType->getWrappedType()->name);
    }

    #[Test]
    public function mutationFieldsAreGenerated(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createArticle'));
        self::assertTrue($mutationType->hasField('updateArticle'));
        self::assertTrue($mutationType->hasField('deleteArticle'));

        // createArticle should have an 'input' arg
        $createField = $mutationType->getField('createArticle');
        $inputArg = $createField->getArg('input');
        self::assertNotNull($inputArg);
        $inputArgType = $inputArg->getType();
        self::assertInstanceOf(NonNull::class, $inputArgType);

        // updateArticle should have 'id' and 'input' args
        $updateField = $mutationType->getField('updateArticle');
        self::assertNotNull($updateField->getArg('id'));
        self::assertNotNull($updateField->getArg('input'));

        // deleteArticle should have 'id' arg and return DeleteResult
        $deleteField = $mutationType->getField('deleteArticle');
        self::assertNotNull($deleteField->getArg('id'));
        $deleteReturnType = $deleteField->getType();
        self::assertInstanceOf(ObjectType::class, $deleteReturnType);
        self::assertSame('DeleteResult', $deleteReturnType->name);
    }

    #[Test]
    public function fieldTypesMapCorrectly(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $articleField = $queryType->getField('article');
        $articleType = $articleField->getType();

        self::assertInstanceOf(ObjectType::class, $articleType);

        // integer → Int (using view_count, not id which is mapped to ID scalar)
        $viewCountField = $articleType->getField('view_count');
        self::assertSame('Int', $this->unwrapTypeName($viewCountField->getType()));

        // float → Float
        $ratingField = $articleType->getField('rating');
        self::assertSame('Float', $this->unwrapTypeName($ratingField->getType()));

        // boolean → Boolean
        $statusField = $articleType->getField('status');
        self::assertSame('Boolean', $this->unwrapTypeName($statusField->getType()));

        // string → String
        $titleField = $articleType->getField('title');
        self::assertSame('String', $this->unwrapTypeName($titleField->getType()));

        // timestamp → String
        $createdField = $articleType->getField('created');
        self::assertSame('String', $this->unwrapTypeName($createdField->getType()));
    }

    #[Test]
    public function filterInputTypeHasCorrectStructure(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('articleList');
        $filterArg = $listField->getArg('filter');

        self::assertNotNull($filterArg);

        // filter arg type is [FilterInput!] — unwrap the list
        $filterArgType = $filterArg->getType();
        self::assertInstanceOf(ListOfType::class, $filterArgType);
        $innerType = $filterArgType->getWrappedType();

        // Unwrap NonNull wrapper
        if ($innerType instanceof NonNull) {
            $innerType = $innerType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $innerType);
        self::assertSame('FilterInput', $innerType->name);

        // Verify FilterInput fields
        self::assertTrue($innerType->hasField('field'));
        self::assertTrue($innerType->hasField('value'));
        self::assertTrue($innerType->hasField('operator'));

        // field and value are NonNull(String)
        $fieldType = $innerType->getField('field')->getType();
        self::assertInstanceOf(NonNull::class, $fieldType);
        self::assertSame('String', $fieldType->getWrappedType()->name);

        $valueType = $innerType->getField('value')->getType();
        self::assertInstanceOf(NonNull::class, $valueType);
        self::assertSame('String', $valueType->getWrappedType()->name);

        // operator is optional String with default '='
        $operatorField = $innerType->getField('operator');
        self::assertSame('String', $this->unwrapTypeName($operatorField->getType()));
        self::assertSame('=', $operatorField->defaultValue);
    }

    #[Test]
    public function createAndUpdateInputTypesAreSeparate(): void
    {
        $schema = $this->buildSchema();

        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createArticle');
        $updateField = $mutationType->getField('updateArticle');

        // Get input types (unwrap NonNull)
        $createInputType = $createField->getArg('input')->getType();
        if ($createInputType instanceof NonNull) {
            $createInputType = $createInputType->getWrappedType();
        }
        $updateInputType = $updateField->getArg('input')->getType();
        if ($updateInputType instanceof NonNull) {
            $updateInputType = $updateInputType->getWrappedType();
        }

        // They should be different types
        self::assertInstanceOf(InputObjectType::class, $createInputType);
        self::assertInstanceOf(InputObjectType::class, $updateInputType);
        self::assertSame('ArticleCreateInput', $createInputType->name);
        self::assertSame('ArticleUpdateInput', $updateInputType->name);

        // Create input: required fields (title) are NonNull
        $createTitle = $createInputType->getField('title');
        self::assertInstanceOf(NonNull::class, $createTitle->getType());

        // Update input: all fields nullable (PATCH semantics)
        $updateTitle = $updateInputType->getField('title');
        self::assertNotInstanceOf(NonNull::class, $updateTitle->getType());

        // Both should have the same non-key, non-readOnly fields
        $createFieldNames = array_keys($createInputType->getFields());
        $updateFieldNames = array_keys($updateInputType->getFields());
        self::assertSame($createFieldNames, $updateFieldNames);
    }

    #[Test]
    public function listQueryHasPaginationArguments(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('articleList');

        // limit should be Int
        $limitArg = $listField->getArg('limit');
        self::assertNotNull($limitArg);
        self::assertSame('Int', $this->unwrapTypeName($limitArg->getType()));

        // offset should be Int
        $offsetArg = $listField->getArg('offset');
        self::assertNotNull($offsetArg);
        self::assertSame('Int', $this->unwrapTypeName($offsetArg->getType()));
    }

    /**
     * Documents that EntityResolver::resolveList() enforces pagination bounds:
     * - Default limit: EntityResolver::DEFAULT_LIMIT (50)
     * - Max limit: EntityResolver::MAX_LIMIT (100) — requests above are clamped
     * - Min limit: 1 — zero or negative values are clamped to 1
     * - Min offset: 0 — negative offsets are clamped to 0
     *
     * These are enforced at the resolver level (not schema level) so that
     * clients always get safe pagination regardless of the arguments passed.
     */
    #[Test]
    public function paginationConstantsAreDefined(): void
    {
        $reflection = new \ReflectionClass(EntityResolver::class);

        $defaultLimit = $reflection->getConstant('DEFAULT_LIMIT');
        self::assertSame(50, $defaultLimit);

        $maxLimit = $reflection->getConstant('MAX_LIMIT');
        self::assertSame(100, $maxLimit);
    }

    /**
     * Unwrap NonNull wrappers to get the named type.
     */
    private function unwrapTypeName(Type $type): string
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        return $type->name;
    }
}
