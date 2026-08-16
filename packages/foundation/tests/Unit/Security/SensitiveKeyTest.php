<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Security\SensitiveKey;

#[CoversClass(SensitiveKey::class)]
final class SensitiveKeyTest extends TestCase
{
    #[Test]
    public function clone_is_refused(): void
    {
        $key = new SensitiveKey('CFG04-SYNTHETIC-DERIVED-KEY');

        $this->expectException(\Error::class);

        clone $key;
    }
}
