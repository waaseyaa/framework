<?php

declare(strict_types=1);

namespace Aurora\I18n\Tests\Unit;

use Aurora\I18n\Language;
use Aurora\I18n\LanguageManager;
use Aurora\I18n\LanguageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LanguageManager::class)]
final class LanguageManagerTest extends TestCase
{
    private Language $english;
    private Language $french;
    private Language $arabic;

    protected function setUp(): void
    {
        $this->english = new Language(id: 'en', label: 'English', isDefault: true);
        $this->french = new Language(id: 'fr', label: 'French', weight: 1);
        $this->arabic = new Language(id: 'ar', label: 'Arabic', direction: 'rtl', weight: 2);
    }

    #[Test]
    public function it_implements_language_manager_interface(): void
    {
        $manager = new LanguageManager([$this->english]);

        $this->assertInstanceOf(LanguageManagerInterface::class, $manager);
    }

    #[Test]
    public function it_returns_the_default_language(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);

        $this->assertSame('en', $manager->getDefaultLanguage()->id);
        $this->assertTrue($manager->getDefaultLanguage()->isDefault);
    }

    #[Test]
    public function it_returns_a_language_by_id(): void
    {
        $manager = new LanguageManager([$this->english, $this->french, $this->arabic]);

        $fr = $manager->getLanguage('fr');
        $this->assertNotNull($fr);
        $this->assertSame('French', $fr->label);
    }

    #[Test]
    public function it_returns_null_for_unknown_language(): void
    {
        $manager = new LanguageManager([$this->english]);

        $this->assertNull($manager->getLanguage('de'));
    }

    #[Test]
    public function it_returns_all_languages_keyed_by_id(): void
    {
        $manager = new LanguageManager([$this->english, $this->french, $this->arabic]);

        $languages = $manager->getLanguages();

        $this->assertCount(3, $languages);
        $this->assertArrayHasKey('en', $languages);
        $this->assertArrayHasKey('fr', $languages);
        $this->assertArrayHasKey('ar', $languages);
    }

    #[Test]
    public function it_returns_current_language_as_default_initially(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);

        $this->assertSame('en', $manager->getCurrentLanguage()->id);
    }

    #[Test]
    public function it_can_set_current_language(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);
        $manager->setCurrentLanguage($this->french);

        $this->assertSame('fr', $manager->getCurrentLanguage()->id);
    }

    #[Test]
    public function it_rejects_setting_unknown_language_as_current(): void
    {
        $manager = new LanguageManager([$this->english]);
        $german = new Language(id: 'de', label: 'German');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Language "de" is not registered');

        $manager->setCurrentLanguage($german);
    }

    #[Test]
    public function is_multilingual_returns_false_with_one_language(): void
    {
        $manager = new LanguageManager([$this->english]);

        $this->assertFalse($manager->isMultilingual());
    }

    #[Test]
    public function is_multilingual_returns_true_with_multiple_languages(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);

        $this->assertTrue($manager->isMultilingual());
    }

    #[Test]
    public function it_rejects_empty_language_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one language must be provided');

        new LanguageManager([]);
    }

    #[Test]
    public function it_rejects_languages_without_a_default(): void
    {
        $noDefault = new Language(id: 'en', label: 'English');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one language must be marked as default');

        new LanguageManager([$noDefault]);
    }

    #[Test]
    public function it_rejects_multiple_default_languages(): void
    {
        $default1 = new Language(id: 'en', label: 'English', isDefault: true);
        $default2 = new Language(id: 'fr', label: 'French', isDefault: true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple default languages found');

        new LanguageManager([$default1, $default2]);
    }

    #[Test]
    public function it_auto_derives_fallback_from_regional_variant(): void
    {
        $frCA = new Language(id: 'fr-CA', label: 'French (Canada)', weight: 2);
        $fr = new Language(id: 'fr', label: 'French', weight: 1);
        $manager = new LanguageManager([$this->english, $fr, $frCA]);

        $chain = $manager->getFallbackChain('fr-CA');

        $this->assertSame(['fr-CA', 'fr', 'en'], $chain);
    }

    #[Test]
    public function fallback_chain_for_default_language_returns_only_itself(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);

        $chain = $manager->getFallbackChain('en');

        $this->assertSame(['en'], $chain);
    }

    #[Test]
    public function fallback_chain_for_non_regional_non_default_appends_default(): void
    {
        $manager = new LanguageManager([$this->english, $this->french]);

        $chain = $manager->getFallbackChain('fr');

        $this->assertSame(['fr', 'en'], $chain);
    }

    #[Test]
    public function it_uses_custom_fallback_map(): void
    {
        $es = new Language(id: 'es', label: 'Spanish', weight: 1);
        $pt = new Language(id: 'pt', label: 'Portuguese', weight: 2);
        $manager = new LanguageManager(
            languages: [$this->english, $es, $pt],
            fallbackMap: ['es' => ['pt', 'en']],
        );

        $chain = $manager->getFallbackChain('es');

        $this->assertSame(['es', 'pt', 'en'], $chain);
    }

    #[Test]
    public function custom_fallback_map_does_not_duplicate_default(): void
    {
        $pt = new Language(id: 'pt', label: 'Portuguese', weight: 1);
        $manager = new LanguageManager(
            languages: [$this->english, $pt],
            fallbackMap: ['pt' => ['en']],
        );

        $chain = $manager->getFallbackChain('pt');

        // 'en' should appear only once, not duplicated.
        $this->assertSame(['pt', 'en'], $chain);
    }

    #[Test]
    public function custom_fallback_map_does_not_duplicate_langcode(): void
    {
        $es = new Language(id: 'es', label: 'Spanish', weight: 1);
        $pt = new Language(id: 'pt', label: 'Portuguese', weight: 2);
        $manager = new LanguageManager(
            languages: [$this->english, $es, $pt],
            fallbackMap: ['es' => ['es', 'pt']],
        );

        $chain = $manager->getFallbackChain('es');

        // 'es' should appear only once at the start.
        $this->assertSame(['es', 'pt', 'en'], $chain);
    }
}
