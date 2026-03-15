<?php

declare(strict_types=1);

namespace Waaseyaa\I18n\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\Translator;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private string $langDir;
    private LanguageManager $manager;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/waaseyaa_i18n_test_' . uniqid();
        mkdir($this->langDir, 0o777, true);

        // Write test translation files
        file_put_contents($this->langDir . '/en.php', "<?php\nreturn [\n    'greeting' => 'Hello',\n    'welcome' => 'Welcome, {name}!',\n    'nav.home' => 'Home',\n    'nav.about' => 'About',\n];\n");
        file_put_contents($this->langDir . '/oj.php', "<?php\nreturn [\n    'greeting' => 'Boozhoo',\n    'nav.home' => 'Endaad',\n];\n");

        $this->manager = new LanguageManager([
            new Language('en', 'English', isDefault: true),
            new Language('oj', 'Anishinaabemowin'),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->langDir . '/en.php');
        @unlink($this->langDir . '/oj.php');
        @rmdir($this->langDir);
    }

    #[Test]
    public function translates_key_in_default_language(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('Hello', $translator->trans('greeting'));
    }

    #[Test]
    public function translates_key_in_specified_language(): void
    {
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('Boozhoo', $translator->trans('greeting'));
    }

    #[Test]
    public function falls_back_to_default_language(): void
    {
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        $translator = new Translator($this->langDir, $this->manager);
        // 'nav.about' only exists in en.php
        $this->assertSame('About', $translator->trans('nav.about'));
    }

    #[Test]
    public function returns_key_when_not_found_in_any_language(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('nonexistent.key', $translator->trans('nonexistent.key'));
    }

    #[Test]
    public function replaces_parameters(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('Welcome, Miigwech!', $translator->trans('welcome', ['name' => 'Miigwech']));
    }

    #[Test]
    public function has_returns_true_for_existing_key(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertTrue($translator->has('greeting'));
    }

    #[Test]
    public function has_returns_false_for_missing_key(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertFalse($translator->has('nonexistent'));
    }

    #[Test]
    public function handles_missing_lang_file_gracefully(): void
    {
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        @unlink($this->langDir . '/oj.php');
        $translator = new Translator($this->langDir, $this->manager);
        // Falls back to en
        $this->assertSame('Hello', $translator->trans('greeting'));
    }

    #[Test]
    public function get_locale_returns_current_language_id(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('en', $translator->getLocale());
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        $this->assertSame('oj', $translator->getLocale());
    }

    #[Test]
    public function caches_loaded_translations(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        $translator->trans('greeting');
        // Modify file after first load — should still return cached value
        file_put_contents($this->langDir . '/en.php', "<?php\nreturn ['greeting' => 'Modified'];\n");
        $this->assertSame('Hello', $translator->trans('greeting'));
    }
}
