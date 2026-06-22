<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Seo\MetaTagBuilder;
use Waaseyaa\Seo\Twig\SeoTwigExtension;

/**
 * @covers \Waaseyaa\Seo\Twig\SeoTwigExtension
 */
#[CoversClass(SeoTwigExtension::class)]
final class SeoTwigExtensionTest extends TestCase
{
    #[Test]
    public function seo_meta_head_renders(): void
    {
        $twig = new Environment(new ArrayLoader([
            'm' => "{{ seo_meta_head('Hi', 'Desc', 'https://x.test/') }}",
        ]));
        $twig->addExtension(new SeoTwigExtension(new MetaTagBuilder()));

        $out = $twig->render('m');
        $this->assertStringContainsString('<title>Hi</title>', $out);
        $this->assertStringContainsString('name="description"', $out);
        $this->assertStringContainsString('rel="canonical"', $out);
    }

    #[Test]
    public function seo_json_ld_script_renders(): void
    {
        $twig = new Environment(new ArrayLoader([
            'j' => '{{ seo_json_ld_script({ "@context": "https://schema.org", "@type": "Thing", "name": "N" }) }}',
        ]));
        $twig->addExtension(new SeoTwigExtension(new MetaTagBuilder()));

        $out = $twig->render('j');
        $this->assertStringContainsString('application/ld+json', $out);
        $this->assertStringContainsString('"name":"N"', $out);
    }

    #[Test]
    public function seo_json_ld_script_escapes_closing_tag_breakout(): void
    {
        $twig = new Environment(new ArrayLoader([
            'j' => '{{ seo_json_ld_script({ "@type": "Thing", "name": name }) }}',
        ]));
        $twig->addExtension(new SeoTwigExtension(new MetaTagBuilder()));

        $out = $twig->render('j', ['name' => '</script><img src=x onerror=alert(1)>']);

        // Only the genuine closing tag may appear; the label must not break out.
        $this->assertSame(1, substr_count($out, '</script>'));
        $this->assertStringNotContainsString('<img', $out);
    }
}
