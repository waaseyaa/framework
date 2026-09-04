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
    /**
     * ADR-025 D-5's table is the enumeration, and the ADR is explicit that
     * "Any additional id is an amendment to this ADR, not a silent addition".
     * This list is that table, in numeric order.
     */
    private const array RESERVED_IDS = [
        'GEN001_UNSAFE_PATH',
        'GEN002_SYMLINK_REJECTED',
        'GEN003_COLLISION_REFUSED',
        'GEN004_AMBIGUOUS_EXTENSION_REGION',
        'GEN005_STALE_PLAN',
        'GEN006_MALICIOUS_IDENTIFIER',
        'GEN007_UNSUPPORTED_DECLARATION',
        'GEN008_LOCKED',
        'GEN009_UNDECLARED_UNIT_RETIREMENT',
        'GEN010_UNIT_PATH_CONFLICT',
        'GEN011_UNAUTHORIZED_SET_DELTA',
        'GEN012_REGISTRATION_OWNERSHIP_CONFLICT',
        'GEN013_SEEDED_REGISTRATION_REDECLARED',
        'GEN014_INVALID_COMPOSER_PROVIDER_STATE',
        'GEN015_INVALID_REGISTRATION_ROSTER',
    ];

    #[Test]
    public function itReservesExactlyTheIdsTheDecisionTableEnumerates(): void
    {
        self::assertSame(
            self::RESERVED_IDS,
            array_map(static fn(GenerationErrorCode $case): string => $case->value, GenerationErrorCode::cases()),
            'The GEN0xx family is closed by ADR-025 D-5; adding an id is an amendment, not a code change.',
        );
    }

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
        foreach (self::RESERVED_IDS as $id) {
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
