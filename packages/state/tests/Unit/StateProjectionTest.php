<?php

declare(strict_types=1);

namespace Waaseyaa\State\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\State\PublicStateProjection;

final class StateProjectionTest extends TestCase
{
    #[Test]
    public function projectionCarriesOnlyExplicitScalarAndArrayData(): void
    {
        $projection = new PublicStateProjection('user', '17', ['display_name' => 'A. Member']);

        self::assertSame(['entity_type' => 'user', 'entity_id' => '17', 'public_values' => ['display_name' => 'A. Member']], $projection->toArray());
        $this->expectException(\InvalidArgumentException::class);
        new PublicStateProjection('user', '17', ['entity' => new \stdClass()]);
    }
}
