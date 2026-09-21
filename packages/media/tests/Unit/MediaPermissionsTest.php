<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\MediaPermissions;

final class MediaPermissionsTest extends TestCase
{
    #[Test]
    public function it_builds_all_owner_scoped_and_any_scoped_permissions(): void
    {
        $definitions = MediaPermissions::forTypes(['image']);

        self::assertSame([
            'create image media',
            'delete any image media',
            'delete own image media',
            'edit any image media',
            'edit own image media',
        ], array_keys($definitions));
    }

    #[Test]
    public function it_refuses_non_string_subjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaPermissions::forTypes([7]);
    }
}
