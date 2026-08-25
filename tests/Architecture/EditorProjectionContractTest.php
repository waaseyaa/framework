<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * #2552 contract: lossless HTML is gated, off by default, and requested by
 * exactly one authorized editor caller. Widening RichTextSanitizer or turning
 * the flag on from GraphQL / markdown / admin / mutation paths must fail here.
 */
#[CoversNothing]
final class EditorProjectionContractTest extends TestCase
{
    #[Test]
    public function only_json_api_show_passes_lossless_html(): void
    {
        $callers = [];
        foreach ($this->phpSources() as $relative => $source) {
            if (preg_match('/losslessHtml\s*:/', $source) === 1) {
                $callers[] = $relative;
            }
        }
        sort($callers);

        self::assertSame(
            ['packages/api/src/JsonApiController.php'],
            $callers,
            'Exactly one production named argument may opt into lossless HTML, and it must be JsonApiController::show().',
        );

        $controller = (string) file_get_contents($this->root() . '/packages/api/src/JsonApiController.php');
        self::assertSame(1, substr_count($controller, 'losslessHtml:'));
        self::assertStringContainsString('losslessHtml: $editingRepresentation', $controller);
        self::assertStringNotContainsString('losslessHtml: true', $controller);
        foreach (['index', 'store', 'update'] as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/function ' . $method . '\([^)]*\)[^{]*\{(?:(?!function ).)*losslessHtml\s*:/s',
                $controller,
                $method . '() must not opt into lossless HTML (#2553 stays out).',
            );
        }
    }

    #[Test]
    public function shared_sanitizer_keeps_the_fail_closed_allowlist(): void
    {
        $source = (string) file_get_contents($this->root() . '/packages/api/src/Sanitizer/RichTextSanitizer.php');

        self::assertMatchesRegularExpression(
            '/new HtmlSanitizerConfig\(\)\s*->allowSafeElements\(\)\s*->forceHttpsUrls\(\);/s',
            $source,
            'RichTextSanitizer must keep the origin/main allowSafeElements + forceHttpsUrls baseline.',
        );
        self::assertStringNotContainsString("allowAttribute('class'", $source);
        self::assertStringNotContainsString('allowRelativeLinks(', $source);
        self::assertStringNotContainsString('allowRelativeMedias(', $source);
    }

    /** @return iterable<string, string> */
    private function phpSources(): iterable
    {
        $root = $this->root();
        $finder = new Finder()
            ->files()
            ->in($root . '/packages')
            ->name('*.php')
            ->exclude(['tests', 'testing', 'vendor', 'node_modules']);

        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $relative = substr($absolute, strlen($root) + 1);
            yield $relative => (string) file_get_contents($absolute);
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
