<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\Document\MarkdownDirectorySource;
use Waaseyaa\Search\Document\SearchDocument;

/**
 * Regression for D-15: MarkdownDirectorySource must not follow a symlinked
 * *.md out of the configured root (realpath containment).
 */
#[CoversClass(MarkdownDirectorySource::class)]
final class MarkdownDirectorySourceSymlinkTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/waaseyaa_search_symlink_' . uniqid('', true);
        mkdir($this->base . '/root', 0o777, true);
        mkdir($this->base . '/outside', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    #[Test]
    public function it_does_not_follow_a_symlinked_markdown_out_of_the_root(): void
    {
        // A legitimate in-root document, plus a secret file living outside the root.
        file_put_contents($this->base . '/root/inside.md', "# Inside\n\nlegitimate corpus body\n");
        file_put_contents($this->base . '/outside/secret.md', "# Secret\n\nSECRET_OUT_OF_ROOT_CONTENT\n");

        // Plant a symlink inside the root pointing at the out-of-root secret.
        $link = $this->base . '/root/leak.md';
        if (@symlink($this->base . '/outside/secret.md', $link) === false) {
            self::markTestSkipped('Platform cannot create symlinks; containment guard not exercisable here.');
        }

        $source = new MarkdownDirectorySource($this->base . '/root', 'spec');
        $documents = iterator_to_array($source->documents(), false);

        // The legitimate in-root document is still indexed.
        $bodies = array_map(static fn(SearchDocument $d): string => $d->toSearchDocument()['body'], $documents);
        $combined = implode("\n", $bodies);
        self::assertStringContainsString('legitimate corpus body', $combined);

        // The symlinked, out-of-root secret is never yielded.
        self::assertStringNotContainsString('SECRET_OUT_OF_ROOT_CONTENT', $combined);
        $ids = array_map(static fn(SearchDocument $d): string => $d->getSearchDocumentId(), $documents);
        self::assertNotContains('spec:leak', $ids);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            // is_link first: never recurse through a symlink, just unlink it.
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            $this->removeTree($path);
        }

        @rmdir($dir);
    }
}
