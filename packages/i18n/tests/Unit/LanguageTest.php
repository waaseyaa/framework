<?php

declare(strict_types=1);

namespace Aurora\I18n\Tests\Unit;

use Aurora\I18n\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Language::class)]
final class LanguageTest extends TestCase
{
    #[Test]
    public function it_creates_a_language_with_all_properties(): void
    {
        $lang = new Language(
            id: 'fr',
            label: 'French',
            direction: 'ltr',
            weight: 2,
            isDefault: false,
        );

        $this->assertSame('fr', $lang->id);
        $this->assertSame('French', $lang->label);
        $this->assertSame('ltr', $lang->direction);
        $this->assertSame(2, $lang->weight);
        $this->assertFalse($lang->isDefault);
    }

    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $lang = new Language(id: 'en', label: 'English');

        $this->assertSame('ltr', $lang->direction);
        $this->assertSame(0, $lang->weight);
        $this->assertFalse($lang->isDefault);
    }

    #[Test]
    public function it_supports_rtl_direction(): void
    {
        $lang = new Language(id: 'ar', label: 'Arabic', direction: 'rtl');

        $this->assertSame('rtl', $lang->direction);
    }

    #[Test]
    public function it_can_be_marked_as_default(): void
    {
        $lang = new Language(id: 'en', label: 'English', isDefault: true);

        $this->assertTrue($lang->isDefault);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $lang = new Language(id: 'en', label: 'English');

        $reflection = new \ReflectionClass($lang);
        $this->assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function it_supports_regional_variant_ids(): void
    {
        $lang = new Language(id: 'zh-hans', label: 'Chinese (Simplified)');

        $this->assertSame('zh-hans', $lang->id);
    }
}
