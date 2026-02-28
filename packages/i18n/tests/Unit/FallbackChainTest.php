<?php

declare(strict_types=1);

namespace Aurora\I18n\Tests\Unit;

use Aurora\I18n\FallbackChain;
use Aurora\I18n\Language;
use Aurora\I18n\LanguageManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FallbackChain::class)]
final class FallbackChainTest extends TestCase
{
    #[Test]
    public function it_returns_the_chain_in_order(): void
    {
        $chain = new FallbackChain(['fr-CA', 'fr', 'en']);

        $this->assertSame(['fr-CA', 'fr', 'en'], $chain->all());
    }

    #[Test]
    public function it_returns_the_primary_language(): void
    {
        $chain = new FallbackChain(['fr-CA', 'fr', 'en']);

        $this->assertSame('fr-CA', $chain->primary());
    }

    #[Test]
    public function it_returns_the_count(): void
    {
        $chain = new FallbackChain(['fr-CA', 'fr', 'en']);

        $this->assertSame(3, $chain->count());
    }

    #[Test]
    public function it_checks_containment(): void
    {
        $chain = new FallbackChain(['fr-CA', 'fr', 'en']);

        $this->assertTrue($chain->contains('fr'));
        $this->assertTrue($chain->contains('fr-CA'));
        $this->assertTrue($chain->contains('en'));
        $this->assertFalse($chain->contains('de'));
    }

    #[Test]
    public function it_rejects_empty_chain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one language');

        new FallbackChain([]);
    }

    #[Test]
    public function it_builds_from_language_manager(): void
    {
        $en = new Language(id: 'en', label: 'English', isDefault: true);
        $fr = new Language(id: 'fr', label: 'French', weight: 1);
        $frCA = new Language(id: 'fr-CA', label: 'French (Canada)', weight: 2);
        $manager = new LanguageManager([$en, $fr, $frCA]);

        $chain = FallbackChain::fromManager($manager, 'fr-CA');

        $this->assertSame(['fr-CA', 'fr', 'en'], $chain->all());
        $this->assertSame('fr-CA', $chain->primary());
        $this->assertSame(3, $chain->count());
    }

    #[Test]
    public function it_builds_single_language_chain_for_default(): void
    {
        $en = new Language(id: 'en', label: 'English', isDefault: true);
        $manager = new LanguageManager([$en]);

        $chain = FallbackChain::fromManager($manager, 'en');

        $this->assertSame(['en'], $chain->all());
        $this->assertSame(1, $chain->count());
    }

    #[Test]
    public function it_normalizes_numeric_keys(): void
    {
        // Ensure that even with an associative array, keys are sequential.
        $chain = new FallbackChain([2 => 'fr', 5 => 'en']);

        $this->assertSame(['fr', 'en'], $chain->all());
    }
}
