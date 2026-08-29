<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ManagedRegion;

#[CoversClass(ManagedRegion::class)]
final class ManagedRegionTest extends TestCase
{
    #[Test]
    public function wrapsGeneratedContentBetweenTheMarkerPair(): void
    {
        $wrapped = ManagedRegion::wrap('generated body');

        self::assertStringStartsWith(ManagedRegion::BEGIN, $wrapped);
        self::assertStringEndsWith(ManagedRegion::END . "\n", $wrapped);
        self::assertStringContainsString('generated body', $wrapped);
    }

    #[Test]
    public function extractsOnlyTheTextBetweenTheMarkers(): void
    {
        $document = "above\n" . ManagedRegion::wrap('inner') . "below\n";

        $extracted = ManagedRegion::extract($document);

        self::assertNotNull($extracted);
        self::assertStringContainsString('inner', $extracted);
        self::assertStringNotContainsString('above', $extracted);
        self::assertStringNotContainsString('below', $extracted);
    }

    #[Test]
    public function splicePreservesEveryByteOutsideTheMarkers(): void
    {
        $existing = "# My own notes\n\nKeep this.\n\n"
            . ManagedRegion::wrap('old framework guidance')
            . "\nA trailing note I wrote by hand.\n";
        $generated = ManagedRegion::wrap('new framework guidance');

        $merged = ManagedRegion::splice($existing, $generated);

        self::assertNotNull($merged);
        self::assertStringContainsString('# My own notes', $merged);
        self::assertStringContainsString('Keep this.', $merged);
        self::assertStringContainsString('A trailing note I wrote by hand.', $merged);
        self::assertStringContainsString('new framework guidance', $merged);
        self::assertStringNotContainsString('old framework guidance', $merged);
    }

    #[Test]
    public function spliceIsIdempotent(): void
    {
        $generated = ManagedRegion::wrap('framework guidance');
        $existing = "preamble\n" . $generated . "epilogue\n";

        $once = ManagedRegion::splice($existing, $generated);
        self::assertNotNull($once);
        self::assertSame($existing, $once);
        self::assertSame($once, ManagedRegion::splice($once, $generated));
    }

    #[Test]
    public function refusesToSpliceADocumentWithNoMarkers(): void
    {
        self::assertNull(ManagedRegion::splice("hand-authored only\n", ManagedRegion::wrap('x')));
    }

    #[Test]
    public function refusesToSpliceAnAmbiguousDocumentWithDuplicateMarkers(): void
    {
        $ambiguous = ManagedRegion::wrap('one') . ManagedRegion::wrap('two');

        self::assertNull(ManagedRegion::extract($ambiguous));
        self::assertNull(ManagedRegion::splice($ambiguous, ManagedRegion::wrap('three')));
    }

    #[Test]
    public function refusesToSpliceAnInvertedMarkerPair(): void
    {
        $inverted = ManagedRegion::END . "\nbody\n" . ManagedRegion::BEGIN . "\n";

        self::assertNull(ManagedRegion::splice($inverted, ManagedRegion::wrap('x')));
    }
}
