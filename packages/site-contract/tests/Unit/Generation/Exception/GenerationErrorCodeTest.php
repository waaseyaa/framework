<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;

#[CoversClass(GenerationErrorCode::class)]
final class GenerationErrorCodeTest extends TestCase
{
    #[Test]
    public function everyIdIsWellFormedAndNumberedOnce(): void
    {
        $numbers = [];
        foreach (GenerationErrorCode::cases() as $case) {
            self::assertMatchesRegularExpression('/^GEN[0-9]{3}_[A-Z][A-Z_]*[A-Z]$/D', $case->value, $case->name);
            $numbers[] = substr($case->value, 3, 3);
        }

        self::assertSame($numbers, array_unique($numbers), 'Two codes must never share a number.');
        self::assertSame($numbers, array_values(array_filter($numbers, static fn(string $n): bool => $n !== '000')));
    }

    #[Test]
    public function eachIdResolvesFromItsBackingString(): void
    {
        foreach (GenerationErrorCode::cases() as $case) {
            $id = $case->value;
            self::assertSame($id, GenerationErrorCode::from($id)->value);
        }
    }

    #[Test]
    public function anUnreservedIdDoesNotResolve(): void
    {
        self::assertNull(GenerationErrorCode::tryFrom('GEN016_NOT_YET_DECIDED'));
        self::assertNull(GenerationErrorCode::tryFrom('SITE001_UNKNOWN_KEY'));
    }

    #[Test]
    public function itIsDistinctFromTheManifestContentFamilyItSitsBeside(): void
    {
        foreach (GenerationErrorCode::cases() as $case) {
            self::assertStringStartsNotWith('SITE', $case->value);
        }
    }
}
