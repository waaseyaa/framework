<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Taxonomy\TaxonomyPermissions;

final class TaxonomyPermissionsTest extends TestCase
{
    #[Test]
    public function it_builds_the_complete_vocabulary_family(): void
    {
        self::assertSame([
            'create terms in category',
            'delete terms in category',
            'edit terms in category',
        ], array_keys(TaxonomyPermissions::forVocabularies(['category'])));
    }

    #[Test]
    public function it_refuses_padded_subjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TaxonomyPermissions::edit(' category');
    }
}
