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
        $command = escapeshellarg($root . '/bin/git')
            . ' -C '
            . escapeshellarg($root)
            . ' ls-files -z';
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, 'Unable to enumerate tracked files through the repository git guard.');

        foreach (array_filter(explode("\0", implode("\n", $output))) as $relativePath) {
            $relativePath = str_replace('\\', '/', $relativePath);
            if (!preg_match('#^packages/[^/]+/src/.+\.php$#', $relativePath)) {
                continue;
            }
            $path = $root . '/' . $relativePath;
            $contents = file_get_contents($path);
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
            $readers[] = $relativePath;
        }

        sort($readers);
        self::assertSame([
            'packages/deployer/src/RuntimeState/FrameworkRuntimeTableCatalogue.php',
            'packages/search/src/Fts5/Fts5SearchContentCatalogue.php',
            'packages/search/src/Fts5/Fts5SearchIndexer.php',
            'packages/search/src/Fts5/Fts5SearchProvider.php',
        ], $readers);
    }
}
