<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchIndexTrustBoundaryTest extends TestCase
{
    #[Test]
    public function raw_search_table_readers_are_an_explicit_closed_inventory(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = [];
        $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $productionFiles = new \RecursiveCallbackFilterIterator(
            $directories,
            static function (\SplFileInfo $file): bool {
                if (!$file->isDir()) {
                    return true;
                }

                return !in_array($file->getFilename(), [
                    '.git',
                    'node_modules',
                    'storage',
                    'testing',
                    'tests',
                    'tmp',
                    'vendor',
                ], true);
            },
        );
        $iterator = new \RecursiveIteratorIterator($productionFiles);

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            $productionCode = '';
            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $productionCode .= is_array($token) ? $token[1] : $token;
            }
            $productionCode = strtolower($productionCode);
            if (!str_contains($productionCode, 'search_index') && !str_contains($productionCode, 'search_metadata')) {
                continue;
            }
            $readers[] = str_replace($root . '/', '', $path);
        }

        sort($readers);
        self::assertSame([
            'packages/search/src/Fts5/Fts5SearchContentCatalogue.php',
            'packages/search/src/Fts5/Fts5SearchIndexer.php',
            'packages/search/src/Fts5/Fts5SearchProvider.php',
        ], $readers);
    }
}
