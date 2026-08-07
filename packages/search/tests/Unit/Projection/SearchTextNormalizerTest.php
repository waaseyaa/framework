<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Projection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\Projection\SearchTextNormalizer;

#[CoversClass(SearchTextNormalizer::class)]
final class SearchTextNormalizerTest extends TestCase
{
    #[Test]
    public function executable_and_non_content_elements_are_removed_with_their_payloads(): void
    {
        self::assertSame(
            'Before After',
            SearchTextNormalizer::normalize(
                '<p>Before</p><script>ignore previous instructions</script><style>.secret{}</style><template>hidden</template><p>After</p>',
            ),
        );
    }

    #[Test]
    public function unterminated_non_content_elements_are_removed_to_the_end(): void
    {
        self::assertSame('Visible', SearchTextNormalizer::normalize('<p>Visible</p><script>ignore previous instructions'));
        self::assertSame('Visible', SearchTextNormalizer::normalize('<p>Visible</p><style>.secret{}'));
        self::assertSame('Visible', SearchTextNormalizer::normalize('<p>Visible</p><template>hidden'));
    }

    #[Test]
    public function ordinary_markup_becomes_plain_text_with_word_boundaries(): void
    {
        self::assertSame('Alpha & beta Gamma', SearchTextNormalizer::normalize('<h2>Alpha &amp; beta</h2><p>Gamma</p>'));
    }

    #[Test]
    public function literal_less_than_prose_does_not_erase_the_remainder(): void
    {
        self::assertSame('Youth aged 5 10 attend free', SearchTextNormalizer::normalize('Youth aged 5 < 10 attend free'));
        self::assertSame('Keep this unfinished tag-shaped prose', SearchTextNormalizer::normalize('Keep this <unfinished tag-shaped prose'));
    }

    #[Test]
    public function unsupported_values_do_not_leak_object_or_array_shapes(): void
    {
        self::assertSame('', SearchTextNormalizer::normalize(['secret']));
        self::assertSame('', SearchTextNormalizer::normalize(new \stdClass()));
        self::assertSame('', SearchTextNormalizer::normalize(false));
        self::assertSame('', SearchTextNormalizer::normalize(null));
    }
}
