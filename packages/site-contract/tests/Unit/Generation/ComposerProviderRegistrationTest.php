<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;

#[CoversClass(ComposerProviderRegistration::class)]
final class ComposerProviderRegistrationTest extends TestCase
{
    #[Test]
    public function itCarriesTheFqcnAndOmitsAnAbsentGroup(): void
    {
        $registration = new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider');

        self::assertNull($registration->group);
        self::assertSame(['fqcn' => 'App\\Provider\\StoryServiceProvider'], $registration->toArray());
    }

    #[Test]
    public function itEmitsAGroupOnlyWhenOneIsDeclared(): void
    {
        $registration = new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'cli');

        self::assertSame(
            ['fqcn' => 'App\\Provider\\StoryServiceProvider', 'group' => 'cli'],
            $registration->toArray(),
        );
    }

    #[Test]
    public function itRefusesAnEmptyFqcn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer provider registration fqcn must not be empty.');

        new ComposerProviderRegistration('');
    }

    #[Test]
    public function itRefusesAnEmptyGroupRatherThanTreatingItAsAbsent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composer provider registration group must not be empty when declared.');

        new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', '');
    }
}
