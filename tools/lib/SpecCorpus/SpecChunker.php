<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

/**
 * Deterministic heading-based chunker for markdown spec bodies.
 *
 * @api
 */
final class SpecChunker
{
    /**
     * @return list<array{id: string, heading: string, level: int, text: string, digest: string}>
     */
    public static function chunk(string $documentId, string $retrievalText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $retrievalText) ?: [];
        $chunks = [];
        $currentHeading = '';
        $currentLevel = 0;
        $currentLines = [];
        $slugCounts = [];
        $usedIds = [];

        $flush = static function () use (
            &$chunks,
            &$currentLines,
            &$slugCounts,
            &$usedIds,
            &$currentHeading,
            &$currentLevel,
            $documentId,
        ): void {
            $text = trim(implode("\n", $currentLines));
            if ($text === '') {
                $currentLines = [];
                return;
            }

            $slug = self::slugify($currentHeading !== '' ? $currentHeading : 'preamble');
            $ordinal = ($slugCounts[$slug] ?? 0) + 1;
            do {
                $id = $documentId . '#' . $slug . ($ordinal === 1 ? '' : '-' . $ordinal);
                ++$ordinal;
            } while (isset($usedIds[$id]));
            $slugCounts[$slug] = $ordinal - 1;
            $usedIds[$id] = true;

            $chunks[] = [
                'id' => $id,
                'heading' => $currentHeading,
                'level' => $currentLevel,
                'text' => $text . "\n",
                'digest' => self::digest($text),
            ];
            $currentLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $match) === 1) {
                $flush();
                $currentLevel = strlen($match[1]);
                $currentHeading = trim($match[2]);
                continue;
            }

            $currentLines[] = $line;
        }

        $flush();

        if ($chunks === [] && trim($retrievalText) !== '') {
            $chunks[] = [
                'id' => $documentId . '#body',
                'heading' => '',
                'level' => 0,
                'text' => trim($retrievalText) . "\n",
                'digest' => self::digest(trim($retrievalText)),
            ];
        }

        return $chunks;
    }

    public static function digest(string $text): string
    {
        return 'sha256:' . hash('sha256', $text);
    }

    private static function slugify(string $heading): string
    {
        $slug = strtolower($heading);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'section';
    }
}
