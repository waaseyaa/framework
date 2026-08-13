<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;

final class RevisionPreviewGrantTest extends TestCase
{
    #[Test]
    public function completeSameOriginGrantIsAccepted(): void
    {
        $grant = new RevisionPreviewGrant('42', 9, 1_000_600, 'signed', '/preview/42?revision=9');
        self::assertSame('/preview/42?revision=9', $grant->previewUrl);
    }

    /** @return iterable<string, array{string, int, int, string, string}> */
    public static function invalidGrants(): iterable
    {
        yield 'missing entity' => ['', 9, 1_000_600, 'signed', '/preview/42'];
        yield 'missing revision' => ['42', 0, 1_000_600, 'signed', '/preview/42'];
        yield 'missing expiry' => ['42', 9, 0, 'signed', '/preview/42'];
        yield 'missing signature' => ['42', 9, 1_000_600, '', '/preview/42'];
        yield 'empty url' => ['42', 9, 1_000_600, 'signed', ''];
        yield 'relative url' => ['42', 9, 1_000_600, 'signed', 'preview/42'];
        yield 'scheme relative url' => ['42', 9, 1_000_600, 'signed', '//evil.example/preview'];
        yield 'absolute url' => ['42', 9, 1_000_600, 'signed', 'https://evil.example/preview'];
    }

    #[Test]
    #[DataProvider('invalidGrants')]
    public function incompleteOrCrossOriginGrantFailsClosed(
        string $entityId,
        int $revisionId,
        int $expiresAt,
        string $signature,
        string $previewUrl,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new RevisionPreviewGrant($entityId, $revisionId, $expiresAt, $signature, $previewUrl);
    }
}
