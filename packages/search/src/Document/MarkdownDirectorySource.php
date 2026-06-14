<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Document;

/**
 * A file/document corpus source for the search indexer: scans a directory of
 * markdown files and yields one SearchDocument per file, deriving the title
 * from the first H1 (falling back to the file name) and the body from the raw
 * file contents. The document id is "{prefix}:{basename}".
 *
 * This is the non-entity counterpart to the entity event subscriber: where
 * entities are indexed automatically on save, a file corpus is indexed by
 * iterating documents() and handing each to SearchIndexerInterface::index().
 * Re-run after the corpus on disk changes (removeAll() then re-index) to keep
 * the index in step with the files.
 *
 * @api Public corpus source for consuming apps that index files, not entities.
 */
final class MarkdownDirectorySource
{
    public function __construct(
        private readonly string $directory,
        private readonly string $idPrefix = 'doc',
    ) {}

    /**
     * @return iterable<SearchDocument>
     */
    public function documents(): iterable
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $pattern = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.md';
        $files = glob($pattern);
        if ($files === false) {
            return;
        }
        sort($files);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $name = basename($file, '.md');

            yield new SearchDocument(
                id: $this->idPrefix . ':' . $name,
                title: $this->heading($contents) ?? $name,
                body: $contents,
                metadata: ['source_name' => $name],
            );
        }
    }

    private function heading(string $markdown): ?string
    {
        $lines = preg_split('/\R/', $markdown);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', trim($line), $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }
}
