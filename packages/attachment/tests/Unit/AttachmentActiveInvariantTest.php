<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentActiveInvariant;

#[CoversClass(AttachmentActiveInvariant::class)]
#[CoversClass(Attachment::class)]
final class AttachmentActiveInvariantTest extends TestCase
{
    /**
     * The strict allow-list: exactly the representations SQLite/MySQL
     * hydration and in-memory boolean assignment actually produce for a
     * truthy `is_active`. Everything else — including PHP-truthy garbage
     * like the string 'false' — is NOT active, so a malformed value can
     * never trigger a sibling demotion.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function isActiveCases(): array
    {
        return [
            'bool true' => [true, true],
            'int 1' => [1, true],
            'string 1' => ['1', true],
            'bool false' => [false, false],
            'int 0' => [0, false],
            'string 0' => ['0', false],
            'null' => [null, false],
            'empty string' => ['', false],
            // (bool) 'false' === true — the exact footgun the allow-list
            // exists to close. A garbage string must never read as active.
            'garbage string false' => ['false', false],
            'garbage string yes' => ['yes', false],
            'int 2' => [2, false],
        ];
    }

    #[Test]
    #[DataProvider('isActiveCases')]
    public function isActiveUsesAStrictAllowList(mixed $value, bool $expected): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'filename' => 'x.pdf',
        ]);
        // Bypass any constructor-time typed-property coercion: write the raw
        // value directly into the value bag the way hydration does.
        $attachment->set('is_active', $value);

        self::assertSame($expected, AttachmentActiveInvariant::isActive($attachment));
    }
}
