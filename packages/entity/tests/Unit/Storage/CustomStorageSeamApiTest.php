<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversNothing]
final class CustomStorageSeamApiTest extends TestCase
{
    #[Test]
    public function custom_storage_seam_interfaces_are_marked_api(): void
    {
        foreach ([EntityStorageInterface::class, EntityQueryInterface::class] as $class) {
            $doc = (new \ReflectionClass($class))->getDocComment();
            self::assertIsString($doc, $class . ' must have a class-level PHPDoc.');
            self::assertMatchesRegularExpression(
                '/^\s*\*\s*@api\s*$/m',
                $doc,
                $class . ' is the public custom-storage seam and must stay class-level @api.',
            );
        }
    }
}
