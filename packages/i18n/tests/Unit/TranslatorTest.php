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
    public function falls_back_when_translation_is_empty_string(): void
    {
        // oj.php has the key but value is '' — should fall back to en
        file_put_contents($this->langDir . '/oj.php', "<?php\nreturn [\n    'greeting' => '',\n    'nav.home' => 'Endaad',\n];\n");
        $this->manager->setCurrentLanguage($this->manager->getLanguage('oj'));
        $translator = new Translator($this->langDir, $this->manager);

        $this->assertSame('Hello', $translator->trans('greeting'));
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

    #[Test]
    public function path_traversal_locale_does_not_execute_a_file_outside_the_translations_dir(): void
    {
        // Plant a sentinel PHP file in the PARENT of the translations dir. If an
        // unvalidated "../" locale reaches `require`, this file executes and sets
        // a global — proving the path-traversal → code-execution vector. The guard
        // must prevent it from ever being required.
        $outside = dirname($this->langDir) . '/waaseyaa_i18n_evil_' . uniqid() . '.php';
        $sentinel = basename($outside, '.php');
        file_put_contents($outside, "<?php\n\$GLOBALS['waaseyaa_i18n_pwned'] = true;\nreturn ['pwned' => 'PWNED'];\n");
        unset($GLOBALS['waaseyaa_i18n_pwned']);

        try {
            $translator = new Translator($this->langDir, $this->manager);
            // locale '../<sentinel>' would resolve to $langDir/../<sentinel>.php == $outside.
            $result = $translator->trans('pwned', [], '../' . $sentinel);

            $this->assertArrayNotHasKey(
                'waaseyaa_i18n_pwned',
                $GLOBALS,
                'A traversal locale must never execute a file outside the translations dir.',
            );
            // Sentinel translations must not be loaded; key is returned unchanged.
            $this->assertSame('pwned', $result);
        } finally {
            @unlink($outside);
            unset($GLOBALS['waaseyaa_i18n_pwned']);
        }
    }

    #[Test]
    public function rejects_locale_addressing_a_subdirectory(): void
    {
        // A '/' in a locale must not let it address a nested file. Plant a real
        // file at $langDir/sub/dir.php so an unguarded locale 'sub/dir' would load
        // 'SUBDIR'; the guard must reject the separator and fall back to 'en'.
        mkdir($this->langDir . '/sub', 0o777, true);
        file_put_contents($this->langDir . '/sub/dir.php', "<?php\nreturn ['greeting' => 'SUBDIR'];\n");

        try {
            $translator = new Translator($this->langDir, $this->manager);
            $this->assertSame('Hello', $translator->trans('greeting', [], 'sub/dir'));
        } finally {
            @unlink($this->langDir . '/sub/dir.php');
            @rmdir($this->langDir . '/sub');
        }
    }

    #[Test]
    public function url_encoded_traversal_locale_does_not_fatal_and_falls_back(): void
    {
        $translator = new Translator($this->langDir, $this->manager);
        // Must not fatal and must not address anything outside the dir — safe fallback.
        $this->assertSame('Hello', $translator->trans('greeting', [], '..%2f..%2fetc%2fpasswd'));
    }

    #[Test]
    public function null_byte_locale_does_not_fatal(): void
    {
        // Without the guard this reaches is_file()/require with a null byte, which
        // raises a ValueError (a fatal, not a graceful miss). The guard must reject
        // it before the path is built and fall back to the default language.
        $translator = new Translator($this->langDir, $this->manager);
        $this->assertSame('Hello', $translator->trans('greeting', [], "en\0../../etc/passwd"));
    }

    #[Test]
    public function loads_legitimate_bcp47_locales(): void
    {
        // Valid BCP-47-shaped locales (with and without region/private-use subtags)
        // must still load their files — the guard rejects traversal, not legitimacy.
        file_put_contents($this->langDir . '/fr-CA.php', "<?php\nreturn ['greeting' => 'Bonjour'];\n");
        file_put_contents($this->langDir . '/oj-x-sagamok.php', "<?php\nreturn ['greeting' => 'Aaniin'];\n");

        try {
            $translator = new Translator($this->langDir, $this->manager);
            $this->assertSame('Hello', $translator->trans('greeting', [], 'en'));
            $this->assertSame('Bonjour', $translator->trans('greeting', [], 'fr-CA'));
            $this->assertSame('Aaniin', $translator->trans('greeting', [], 'oj-x-sagamok'));
        } finally {
            @unlink($this->langDir . '/fr-CA.php');
            @unlink($this->langDir . '/oj-x-sagamok.php');
        }
    }

    #[Test]
    public function does_not_follow_a_symlink_out_of_the_translations_dir(): void
    {
        // Defense-in-depth: even a BCP-47-valid locale whose file is a symlink
        // pointing outside the translations dir must not be required.
        $outside = dirname($this->langDir) . '/waaseyaa_i18n_target_' . uniqid() . '.php';
        file_put_contents($outside, "<?php\n\$GLOBALS['waaseyaa_i18n_symlinked'] = true;\nreturn ['greeting' => 'LEAKED'];\n");
        unset($GLOBALS['waaseyaa_i18n_symlinked']);

        $link = $this->langDir . '/sneaky.php';
        if (!@symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('symlink() not supported in this environment.');
        }

        try {
            $translator = new Translator($this->langDir, $this->manager);
            $result = $translator->trans('greeting', [], 'sneaky');

            $this->assertArrayNotHasKey('waaseyaa_i18n_symlinked', $GLOBALS);
            // Rejected symlink → safe fallback to the default language.
            $this->assertSame('Hello', $result);
        } finally {
            @unlink($link);
            @unlink($outside);
            unset($GLOBALS['waaseyaa_i18n_symlinked']);
        }
    }
}
