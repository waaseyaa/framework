<?php

declare(strict_types=1);

namespace Waaseyaa\I18n\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\Translator;
use Waaseyaa\I18n\Twig\TranslationTwigExtension;

#[CoversClass(TranslationTwigExtension::class)]
final class TranslationTwigExtensionTest extends TestCase
{
    private string $langDir;
    private LanguageManager $manager;
    private TranslationTwigExtension $extension;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/waaseyaa_twig_test_' . uniqid();
        mkdir($this->langDir, 0o777, true);
        file_put_contents($this->langDir . '/en.php', "<?php\nreturn ['greeting' => 'Hello'];\n");
        file_put_contents($this->langDir . '/oj.php', "<?php\nreturn ['greeting' => 'Boozhoo'];\n");

        $this->manager = new LanguageManager([
            new Language('en', 'English', isDefault: true),
            new Language('oj', 'Anishinaabemowin'),
        ]);

        $translator = new Translator($this->langDir, $this->manager);
        $this->extension = new TranslationTwigExtension($translator, $this->manager);
    }

    protected function tearDown(): void
    {
        @unlink($this->langDir . '/en.php');
        @unlink($this->langDir . '/oj.php');
        @rmdir($this->langDir);
    }

    #[Test]
    public function registers_twig_functions(): void
    {
        $functions = $this->extension->getFunctions();
        $names = array_map(fn($f) => $f->getName(), $functions);
        $this->assertContains('trans', $names);
        $this->assertContains('current_language', $names);
        $this->assertContains('available_languages', $names);
        $this->assertContains('lang_url', $names);
        $this->assertContains('lang_switch_url', $names);
    }

    #[Test]
    public function trans_delegates_to_translator(): void
    {
        $this->assertSame('Hello', $this->extension->trans('greeting'));
    }

    #[Test]
    public function current_language_returns_array(): void
    {
        $lang = $this->extension->currentLanguage();
        $this->assertSame('en', $lang['id']);
        $this->assertSame('English', $lang['label']);
        $this->assertSame('ltr', $lang['direction']);
    }

    #[Test]
    public function available_languages_returns_all_with_current_flag(): void
    {
        $langs = $this->extension->availableLanguages();
        $this->assertCount(2, $langs);
        $this->assertTrue($langs[0]['is_current']); // en is current
        $this->assertFalse($langs[1]['is_current']);
    }

    #[Test]
    public function lang_url_omits_prefix_for_default_language(): void
    {
        $this->assertSame('/communities', $this->extension->langUrl('/communities'));
    }

    #[Test]
    public function lang_url_adds_prefix_for_non_default_language(): void
    {
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        $this->assertSame('/oj/communities', $this->extension->langUrl('/communities'));
    }

    #[Test]
    public function lang_switch_url_generates_correct_urls(): void
    {
        $this->assertSame('/communities', $this->extension->langSwitchUrl('en', '/communities'));
        $this->assertSame('/oj/communities', $this->extension->langSwitchUrl('oj', '/communities'));
    }
}
