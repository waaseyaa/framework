<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * #2552 + #2553 contract: lossless HTML is gated, off by default, and reachable
 * only from a caller that has already run the per-field edit gate. Widening
 * RichTextSanitizer, or turning the flag on from GraphQL / markdown / admin /
 * collection paths, must fail here.
 *
 * #2553 extended the opt-in from the editor READ to the mutation ECHO, so the
 * count of authorized callers went from one to two. What did NOT change is the
 * invariant this file exists for: every lossless serialization is preceded, in
 * its own method, by `losslessHtmlFieldEditDenied()`. The earlier revision of
 * this test asserted "#2553 stays out" — that was #2552's scope fence, and
 * lifting it is precisely what #2553 was chartered to do.
 */
#[CoversNothing]
final class EditorProjectionContractTest extends TestCase
{
    #[Test]
    public function only_gated_json_api_callers_pass_lossless_html(): void
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
            'Only JsonApiController may opt into lossless HTML with the named argument.',
        );

        $controller = (string) file_get_contents($this->root() . '/packages/api/src/JsonApiController.php');

        // Exactly two authorized callers: the editor read (#2552) and the
        // mutation echo (#2553). A third is a new disclosure surface and must
        // be reviewed here, not discovered on a consumer's wire.
        self::assertSame(2, substr_count($controller, 'losslessHtml: true'));
        self::assertSame(2, substr_count($controller, 'losslessHtml:'));

        foreach (['show', 'mutationEcho'] as $method) {
            $body = $this->methodBody($controller, $method);
            self::assertStringContainsString(
                'losslessHtml: true',
                $body,
                $method . '() is one of the two authorized lossless callers.',
            );
            self::assertStringContainsString(
                '$this->losslessHtmlFieldEditDenied(',
                $body,
                $method . '() must run the per-field edit gate itself, not inherit one from a caller.',
            );
            self::assertLessThan(
                strpos($body, 'losslessHtml: true'),
                strpos($body, '$this->losslessHtmlFieldEditDenied('),
                'The field-edit gate must execute before the lossless serialization in ' . $method . '().',
            );
        }

        // A collection establishes no single entity's update access, so it can
        // never be an anchor for the lossless projection.
        self::assertStringNotContainsString(
            'losslessHtml',
            $this->methodBody($controller, 'index'),
            'index() must not opt into lossless HTML: a collection has no update-access anchor.',
        );
    }

    /**
     * #2553: FieldAutoSaveController serves its own unsanitized echo without
     * the named argument (it hand-rolls the sanitizer call), so the named-arg
     * scan above cannot see it. Pin its gate here instead.
     */
    #[Test]
    public function the_field_autosave_echo_is_sanitized_unless_the_caller_opted_in(): void
    {
        $source = (string) file_get_contents(
            $this->root() . '/packages/api/src/Controller/FieldAutoSaveController.php',
        );

        self::assertStringContainsString(
            '$isHtmlField && !$editingRepresentation',
            $source,
            'The unsanitized single-field echo must be conditioned on the explicit opt-in.',
        );
        self::assertStringContainsString(
            '$this->richTextSanitizer->sanitizeValue(',
            $source,
            'The default echo must still go through the sanitizer.',
        );
        self::assertStringContainsString(
            "\$request->query->get('representation') === 'editing'",
            $source,
            'The opt-in must be read from the request, never defaulted on.',
        );
    }

    /**
     * The source text of one method, from its signature to the next
     * declaration. Enough to assert ordering WITHIN a method, which is the
     * property that matters: a gate elsewhere in the file proves nothing.
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, $method . '() must exist.');

        $next = strpos($source, "\n    private function ", $start + 1);
        $nextPublic = strpos($source, "\n    public function ", $start + 1);
        if ($nextPublic !== false && ($next === false || $nextPublic < $next)) {
            $next = $nextPublic;
        }

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
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
