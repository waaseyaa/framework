<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\SaveContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\SaveContext;

/**
 * Value-object behaviour for {@see SaveContext::withExpectedRevisionId()}
 * (mission optimistic-locking-01KTXCHY, WP01/T001, FR-001, contract
 * conflict-detection.md §1): builder validation, null pass-through,
 * immutability, and the full re-threading matrix in both directions.
 */
#[CoversClass(SaveContext::class)]
final class SaveContextExpectedRevisionTest extends TestCase
{
    #[Test]
    public function defaultContextHasNullExpectation(): void
    {
        self::assertNull(SaveContext::default()->expectedRevisionId());
    }

    #[Test]
    public function builderSetsExpectationOnNewInstance(): void
    {
        $original = SaveContext::default();
        $stated = $original->withExpectedRevisionId(7);

        self::assertNotSame($original, $stated);
        self::assertSame(7, $stated->expectedRevisionId());
        // Immutability: the original is unchanged.
        self::assertNull($original->expectedRevisionId());
    }

    #[Test]
    public function nullIsTheExplicitNoExpectationPassThrough(): void
    {
        $ctx = SaveContext::default()->withExpectedRevisionId(null);

        self::assertNull($ctx->expectedRevisionId());
    }

    #[Test]
    public function nullClearsAPreviouslyStatedExpectation(): void
    {
        $ctx = SaveContext::default()
            ->withExpectedRevisionId(3)
            ->withExpectedRevisionId(null);

        self::assertNull($ctx->expectedRevisionId());
    }

    #[Test]
    public function zeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SaveContext::default()->withExpectedRevisionId(0);
    }

    #[Test]
    public function negativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SaveContext::default()->withExpectedRevisionId(-5);
    }

    #[Test]
    public function everyOtherBuilderPreservesTheExpectation(): void
    {
        $base = SaveContext::default()->withExpectedRevisionId(11);

        self::assertSame(11, $base->withActorUid(42)->expectedRevisionId());
        self::assertSame(11, $base->withoutNewRevision()->expectedRevisionId());
        self::assertSame(11, $base->withLangcode('oj')->expectedRevisionId());
        self::assertSame(11, $base->asImport()->expectedRevisionId());
        self::assertSame(11, $base->withTranslations(['en', 'oj'])->expectedRevisionId());
    }

    #[Test]
    public function expectationBuilderPreservesEveryOtherField(): void
    {
        $ctx = SaveContext::default()
            ->withoutNewRevision()
            ->withLangcode('fr')
            ->asImport()
            ->withTranslations(['en', 'fr'])
            ->withActorUid(null)
            ->withExpectedRevisionId(9);

        self::assertTrue($ctx->withoutNewRevision);
        self::assertSame('fr', $ctx->langcode);
        self::assertTrue($ctx->isImport);
        self::assertSame(['en', 'fr'], $ctx->translations);
        // Actor pair: the explicit withActorUid(null) override must survive.
        self::assertNull($ctx->actorUid());
        self::assertTrue($ctx->actorOverridden());
        self::assertSame(9, $ctx->expectedRevisionId());
    }
}
