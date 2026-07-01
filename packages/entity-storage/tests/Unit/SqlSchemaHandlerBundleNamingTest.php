<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Covers the canonical `{base}__{bundle}` naming helper introduced in WP03 of
 * mission #1257 (entity-storage-hardening, K1).
 *
 * The static helper is the single source of truth shared by SqlSchemaHandler,
 * the storage engine (EntityRepository), and SqlEntityQuery. The structural guard at
 * EntityTypeManager::addBundleFields() prevents bad input upstream; this
 * helper enforces the same guard at the formatting boundary as belt-and-
 * suspenders defense.
 */
#[CoversClass(SqlSchemaHandler::class)]
final class SqlSchemaHandlerBundleNamingTest extends TestCase
{
    #[Test]
    public function resolveSubtableNameJoinsBaseAndBundleWithDoubleUnderscore(): void
    {
        self::assertSame(
            'group__business',
            SqlSchemaHandler::resolveSubtableName('group', 'business'),
        );
    }

    #[Test]
    public function resolveSubtableNameAcceptsOptionalEntityTypeIdContext(): void
    {
        self::assertSame(
            'group__business',
            SqlSchemaHandler::resolveSubtableName('group', 'business', 'group'),
        );
    }

    #[Test]
    public function resolveSubtableNameThrowsWhenBundleContainsReservedSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved separator "__"');

        SqlSchemaHandler::resolveSubtableName('group', 'business__nested');
    }

    #[Test]
    public function resolveSubtableNameIncludesEntityTypeIdInErrorMessageWhenProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entity type "group"');

        SqlSchemaHandler::resolveSubtableName('group', 'business__nested', 'group');
    }

    #[Test]
    public function resolveSubtableNameOmitsEntityTypeContextFromMessageWhenAbsent(): void
    {
        try {
            SqlSchemaHandler::resolveSubtableName('group', 'a__b');
            self::fail('Expected InvalidArgumentException not thrown');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('entity type', $e->getMessage());
        }
    }
}
