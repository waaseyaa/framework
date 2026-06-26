<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Backend\SqlColumnSchemaBuilder;
use Waaseyaa\EntityStorage\Schema\ColumnSpecMap;
use Waaseyaa\EntityStorage\Schema\RevisionTableBuilder;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(ColumnSpecMap::class)]
final class ColumnSpecMapTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function abstractTypeProvider(): array
    {
        return [
            'string→varchar'         => ['string', 'varchar'],
            'STRING→varchar (case)'  => ['STRING', 'varchar'],
            'text→text'              => ['text', 'text'],
            'int→int'                => ['int', 'int'],
            'integer→int'            => ['integer', 'int'],
            'bigint→int'             => ['bigint', 'int'],
            'bool→boolean'           => ['bool', 'boolean'],
            'boolean→boolean'        => ['boolean', 'boolean'],
            'datetime→text'          => ['datetime', 'text'],
            'json→text'              => ['json', 'text'],
            'uuid→varchar'           => ['uuid', 'varchar'],
            'float→float'            => ['float', 'float'],
            'decimal→text'           => ['decimal', 'text'],
            'numeric→text'           => ['numeric', 'text'],
            'unknown→text'           => ['mystery_type', 'text'],
        ];
    }

    #[Test]
    #[DataProvider('abstractTypeProvider')]
    public function itMapsFieldTypeToAbstractType(string $fieldType, string $expectedAbstractType): void
    {
        self::assertSame($expectedAbstractType, ColumnSpecMap::abstractTypeFor($fieldType));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedTypeProvider(): array
    {
        return [
            'string'   => ['string'],
            'text'     => ['text'],
            'int'      => ['int'],
            'integer'  => ['integer'],
            'bigint'   => ['bigint'],
            'bool'     => ['bool'],
            'boolean'  => ['boolean'],
            'datetime' => ['datetime'],
            'json'     => ['json'],
            'uuid'     => ['uuid'],
            'float'    => ['float'],
            'decimal'  => ['decimal'],
            'numeric'  => ['numeric'],
        ];
    }

    #[Test]
    #[DataProvider('supportedTypeProvider')]
    public function allThreeCallSitesAgreeOnAbstractType(string $fieldType): void
    {
        $expected = ColumnSpecMap::abstractTypeFor($fieldType);

        // --- RevisionTableBuilder::columnSpecForType (private) ---
        $rtb = new \ReflectionClass(RevisionTableBuilder::class)->newInstanceWithoutConstructor();
        $rtbMethod = new \ReflectionMethod(RevisionTableBuilder::class, 'columnSpecForType');
        /** @var array<string, mixed> $rtbSpec */
        $rtbSpec = $rtbMethod->invoke($rtb, $fieldType, []);
        self::assertSame($expected, $rtbSpec['type'], "RevisionTableBuilder disagrees for type '{$fieldType}'");

        // --- SqlColumnSchemaBuilder::buildColumnSpec (private) ---
        $scsb = new \ReflectionClass(SqlColumnSchemaBuilder::class)->newInstanceWithoutConstructor();
        $scsbMethod = new \ReflectionMethod(SqlColumnSchemaBuilder::class, 'buildColumnSpec');
        /** @var array<string, mixed> $scsbSpec */
        $scsbSpec = $scsbMethod->invoke($scsb, $fieldType, []);
        self::assertSame($expected, $scsbSpec['type'], "SqlColumnSchemaBuilder disagrees for type '{$fieldType}'");

        // --- TranslationSchemaHandler::deriveValueColumnSpec (private) ---
        $tsh = new \ReflectionClass(TranslationSchemaHandler::class)->newInstanceWithoutConstructor();
        $tshMethod = new \ReflectionMethod(TranslationSchemaHandler::class, 'deriveValueColumnSpec');
        $fieldDef = new FieldDefinition(name: '_test', type: $fieldType);
        /** @var array<string, mixed> $tshSpec */
        $tshSpec = $tshMethod->invoke($tsh, $fieldDef);
        self::assertSame($expected, $tshSpec['type'], "TranslationSchemaHandler disagrees for type '{$fieldType}'");
    }
}
