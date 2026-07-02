<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\SlugGenerator;

#[CoversClass(SlugGenerator::class)]
final class SlugGeneratorTest extends TestCase
{
    #[Test]
    public function generates_slug_from_simple_string(): void
    {
        $this->assertSame('hello-world', SlugGenerator::generate('Hello World'));
    }

    #[Test]
    public function strips_special_characters(): void
    {
        $this->assertSame('test-123', SlugGenerator::generate('Test @#$ 123'));
    }

    #[Test]
    public function trims_leading_and_trailing_hyphens(): void
    {
        $this->assertSame('hello', SlugGenerator::generate('---hello---'));
    }

    #[Test]
    public function collapses_multiple_hyphens(): void
    {
        $this->assertSame('a-b', SlugGenerator::generate('a   b'));
    }

    #[Test]
    public function handles_empty_string(): void
    {
        $this->assertSame('', SlugGenerator::generate(''));
    }

    #[Test]
    public function preserves_long_vowel_diacritics(): void
    {
        $this->assertSame('anishinaabemowin-ākí', SlugGenerator::generate('Anishinaabemowin Ākí'));
    }

    #[Test]
    public function preserves_glottal_stop_modifier_letter(): void
    {
        // U+02BC MODIFIER LETTER APOSTROPHE is a letter (Lm) in Anishinaabemowin orthography.
        $this->assertSame("ma\u{02BC}iingan", SlugGenerator::generate("Ma\u{02BC}iingan"));
    }

    #[Test]
    public function preserves_canadian_syllabics(): void
    {
        $this->assertSame('ᐊᓂᔑᓈᐯᒧᐎᓐ', SlugGenerator::generate('ᐊᓂᔑᓈᐯᒧᐎᓐ'));
    }

    #[Test]
    public function slugs_mixed_ascii_and_unicode_title(): void
    {
        $this->assertSame('gichi-gami-ᒥᔑᑲᒥ-guide', SlugGenerator::generate('Gichi-Gami (ᒥᔑᑲᒥ) Guide'));
    }

    #[Test]
    public function normalizes_decomposed_input_to_nfc(): void
    {
        // "ākí" typed with combining marks (NFD) must produce the same slug as
        // its precomposed (NFC) form.
        $nfd = "A\u{0304}ki\u{0301}";
        $this->assertSame('ākí', SlugGenerator::generate($nfd));
        $this->assertSame(SlugGenerator::generate('Ākí'), SlugGenerator::generate($nfd));
    }

    #[Test]
    public function unicode_slugs_are_stable_and_round_trippable(): void
    {
        $slug = SlugGenerator::generate('ᐊᓂᔑᓈᐯᒧᐎᓐ Ākí');
        $this->assertNotSame('', $slug);
        $this->assertSame($slug, SlugGenerator::generate($slug), 'slug generation must be idempotent');
        $this->assertSame($slug, rawurldecode(rawurlencode($slug)), 'slug must survive URL percent-encoding round-trip');
    }

    #[Test]
    public function invalid_utf8_falls_back_to_ascii_slugging_without_error(): void
    {
        $this->assertSame('abc-123', SlugGenerator::generate("abc \xC3\x28 123"));
    }
}
