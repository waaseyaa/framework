<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Gfm;

/**
 * In-house GFM 2-column table parser.
 *
 * Supports the subset documented in research.md Q7:
 *   - Optional leading/trailing pipes per row.
 *   - Alignment markers in the separator row (ignored).
 *   - Escaped pipes (\|) treated as literal pipe characters.
 *   - First valid 2-column table is parsed; subsequent 2-column tables are skipped.
 *   - Tables with ≠ 2 columns are skipped with a non-fatal error.
 *   - No CommonMark or markdown library dependency (C-007).
 *
 * Output rows are data rows only — the header row is consumed for column count
 * validation but is not emitted. Only rows below the separator row appear in output.
 */
final class GfmTableParser
{
    /**
     * Parse a GFM document and return data rows from the first valid 2-column table.
     *
     * The header row (column labels) is consumed for column-count validation only and
     * is not included in the returned rows. Only rows below the separator row are emitted.
     *
     * @param string $payload UTF-8 document content.
     */
    public function parse(string $payload): GfmParseResult
    {
        if (trim($payload) === '') {
            return new GfmParseResult([], ['No table found']);
        }

        // Split on any line ending: \r\n, \r, or \n.
        $split = preg_split('/\R/', $payload);
        $lines = $split !== false ? $split : [];

        $rows = [];
        $errors = [];
        $foundTable = false;
        $i = 0;
        $total = count($lines);

        while ($i < $total) {
            // Look for a header candidate: line that contains at least one unescaped pipe.
            if (!$this->looksLikeTableRow($lines[$i])) {
                $i++;
                continue;
            }

            $headerLineNo = $i + 1; // 1-based for error messages
            $headerCells = $this->splitRow($lines[$i]);

            // The very next line must be a separator row.
            $sepIndex = $i + 1;
            if ($sepIndex >= $total || !$this->isSeparatorRow($lines[$sepIndex])) {
                // Not a table — treat as regular content.
                $i++;
                continue;
            }

            $sepCells = $this->splitSeparatorRow($lines[$sepIndex]);
            $colCount = count($headerCells);

            // Validate column count against separator.
            if ($colCount !== count($sepCells)) {
                $errors[] = sprintf(
                    'Skipped table at line %d: header has %d column(s), separator has %d',
                    $headerLineNo,
                    $colCount,
                    count($sepCells),
                );
                $i = $sepIndex + 1;
                continue;
            }

            if ($colCount !== 2) {
                $errors[] = sprintf(
                    'Skipped table at line %d: expected 2 columns, got %d',
                    $headerLineNo,
                    $colCount,
                );
                $i = $sepIndex + 1;
                continue;
            }

            if ($foundTable) {
                // Already parsed one 2-column table; skip this one.
                $errors[] = sprintf('Skipped second 2-column table at line %d', $headerLineNo);
                $i = $sepIndex + 1;
                // Skip past this table's data rows.
                while ($i < $total && $this->looksLikeTableRow($lines[$i])) {
                    $i++;
                }
                continue;
            }

            // Header row is consumed for column-count validation only; not emitted.
            $foundTable = true;

            // Parse data rows starting after the separator.
            $dataIndex = $sepIndex + 1;

            while ($dataIndex < $total) {
                $line = $lines[$dataIndex];

                // A blank line ends the table.
                if (trim($line) === '') {
                    break;
                }

                if (!$this->looksLikeTableRow($line)) {
                    break;
                }

                $dataLineNo = $dataIndex + 1;
                $cells = $this->splitRow($line);
                $cellCount = count($cells);

                if ($cellCount !== 2) {
                    $errors[] = sprintf('Row %d has %d column(s), expected 2', $dataLineNo, $cellCount);
                    $dataIndex++;
                    continue;
                }

                $rows[] = ['prompt' => $cells[0], 'value' => $cells[1]];
                $dataIndex++;
            }

            $i = $dataIndex;
        }

        if (!$foundTable) {
            $errors[] = 'No table found';
        }

        return new GfmParseResult($rows, $errors);
    }

    /**
     * Return true if the line looks like a table row (contains at least one unescaped pipe).
     */
    private function looksLikeTableRow(string $line): bool
    {
        return preg_match('/(?<!\\\\)\|/', $line) === 1;
    }

    /**
     * Split a data/header row into trimmed cell strings.
     *
     * Handles optional leading/trailing pipes and \| escapes.
     *
     * @return list<string>
     */
    private function splitRow(string $line): array
    {
        // Strip optional leading and trailing pipes.
        $line = preg_replace('/^\s*\|/', '', $line) ?? $line;
        $line = preg_replace('/\|\s*$/', '', $line) ?? $line;

        $parts = $this->splitOnUnescapedPipes($line);
        $cells = [];

        foreach ($parts as $part) {
            // Restore escaped pipes and trim whitespace.
            $cell = str_replace('\\|', '|', $part);
            $cells[] = trim($cell, " \t\n\r\v");
        }

        return $cells;
    }

    /**
     * Return true when the line is a GFM table separator (each cell matches :?-+:?).
     */
    private function isSeparatorRow(string $line): bool
    {
        $line = preg_replace('/^\s*\|/', '', $line) ?? $line;
        $line = preg_replace('/\|\s*$/', '', $line) ?? $line;

        $cells = $this->splitOnUnescapedPipes($line);

        foreach ($cells as $cell) {
            if (!preg_match('/^\s*:?-+:?\s*$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split a separator row into its constituent cells (for column-count validation).
     *
     * @return list<string>
     */
    private function splitSeparatorRow(string $line): array
    {
        $line = preg_replace('/^\s*\|/', '', $line) ?? $line;
        $line = preg_replace('/\|\s*$/', '', $line) ?? $line;

        return $this->splitOnUnescapedPipes($line);
    }

    /** @return list<string> */
    private function splitOnUnescapedPipes(string $line): array
    {
        $parts = preg_split('/(?<!\\\\)\|/', $line);

        return $parts !== false ? $parts : [$line];
    }
}
