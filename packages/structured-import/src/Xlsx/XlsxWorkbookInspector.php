<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Bounded, value-free OOXML workbook inspection plus explicit protected reads.
 *
 * @api
 */
final class XlsxWorkbookInspector
{
    private const string CONTENT_TYPES = '[Content_Types].xml';
    private const string WORKBOOK = 'xl/workbook.xml';
    private const string WORKBOOK_RELS = 'xl/_rels/workbook.xml.rels';
    private const string WORKBOOK_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    private const array ALLOWED_SOURCE_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private readonly XlsxInspectionLimits $limits;

    public function __construct(
        ?XlsxInspectionLimits $limits = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->limits = $limits ?? new XlsxInspectionLimits();
    }

    public function inspect(#[\SensitiveParameter] string $path, string $expectedSha256): XlsxWorkbookInspection
    {
        try {
            $this->assertChecksum($expectedSha256);
            $this->assertSource($path, $expectedSha256);
            $archive = $this->openArchive($path);
            try {
                $entries = $this->validateArchive($archive);
                $inspection = $this->parseWorkbook($archive, $entries, $expectedSha256);
            } finally {
                $archive->close();
            }
            $this->assertSource($path, $expectedSha256);

            return $inspection;
        } catch (XlsxInspectionException $exception) {
            $this->logger?->warning('XLSX inspection refused.', ['code' => $exception->reason->value]);
            throw $exception;
        }
    }

    public function readProtectedSelection(
        #[\SensitiveParameter]
        string $path,
        XlsxWorkbookInspection $inspection,
        XlsxSelection $selection,
    ): XlsxProtectedSelection {
        try {
            $this->assertChecksum($inspection->sourceSha256);
            $this->assertSource($path, $inspection->sourceSha256);
            $archive = $this->openArchive($path);
            try {
                $entries = $this->validateArchive($archive);
                $workbook = $this->parseWorkbookData($archive, $entries);
                $sheetKeys = array_fill_keys(array_column($workbook['sheets'], 'key'), true);
                foreach ([...$selection->selected, ...$selection->ignored] as $area) {
                    if (!isset($sheetKeys[$area->sheetKey])) {
                        throw new XlsxInspectionException(XlsxInspectionError::InvalidSelection);
                    }
                }
                $selected = [];
                foreach ($workbook['sheets'] as $sheet) {
                    $areas = array_values(array_filter(
                        $selection->selected,
                        static fn(XlsxSelectionArea $area): bool => $area->sheetKey === $sheet['key'],
                    ));
                    if ($areas === []) {
                        continue;
                    }
                    $ignored = array_values(array_filter(
                        $selection->ignored,
                        static fn(XlsxSelectionArea $area): bool => $area->sheetKey === $sheet['key'],
                    ));
                    foreach ($sheet['cells'] as $cell) {
                        if (!$this->matchesAny($areas, $cell['coordinate'], $cell['row'], $cell['column'])) {
                            continue;
                        }
                        if ($this->matchesAny($ignored, $cell['coordinate'], $cell['row'], $cell['column'])) {
                            continue;
                        }
                        $selected[] = new XlsxProtectedCell(
                            $sheet['key'],
                            $cell['coordinate'],
                            $cell['type'],
                            $cell['is_date'],
                            $cell['value'],
                        );
                    }
                }
            } finally {
                $archive->close();
            }
            $this->assertSource($path, $inspection->sourceSha256);

            return new XlsxProtectedSelection($inspection->sourceSha256, $selection->id, $selected);
        } catch (XlsxInspectionException $exception) {
            $this->logger?->warning('Protected XLSX selection refused.', ['code' => $exception->reason->value]);
            throw $exception;
        }
    }

    private function assertChecksum(string $checksum): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidChecksum);
        }
    }

    private function assertSource(#[\SensitiveParameter] string $path, string $checksum): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new XlsxInspectionException(XlsxInspectionError::SourceUnavailable);
        }
        $size = filesize($path);
        if (!is_int($size) || $size > $this->limits->maxFileBytes) {
            throw new XlsxInspectionException(XlsxInspectionError::SourceTooLarge);
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($checksum, $actual)) {
            throw new XlsxInspectionException(XlsxInspectionError::SourceChecksumMismatch);
        }
        $handle = @fopen($path, 'rb');
        $magic = is_resource($handle) ? fread($handle, 4) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (!is_string($magic) || !in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidMime);
        }
        $mime = new \finfo(FILEINFO_MIME_TYPE)->file($path);
        if (!is_string($mime) || !in_array(strtolower($mime), self::ALLOWED_SOURCE_MIME_TYPES, true)) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidMime);
        }
    }

    private function openArchive(#[\SensitiveParameter] string $path): \ZipArchive
    {
        $archive = new \ZipArchive();
        if ($archive->open($path, \ZipArchive::RDONLY) !== true) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidArchive);
        }

        return $archive;
    }

    /** @return array<string, true> */
    private function validateArchive(\ZipArchive $archive): array
    {
        if ($archive->numFiles > $this->limits->maxEntries) {
            throw new XlsxInspectionException(XlsxInspectionError::TooManyEntries);
        }
        $entries = [];
        $compressedTotal = 0;
        $uncompressedTotal = 0;
        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $stat = $archive->statIndex($index, \ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) {
                throw new XlsxInspectionException(XlsxInspectionError::InvalidArchive);
            }
            $name = $stat['name'];
            if (!$this->safePartName($name) || isset($entries[$name])) {
                throw new XlsxInspectionException(XlsxInspectionError::UnsafeArchiveEntry);
            }
            $entries[$name] = true;
            $compressed = $stat['comp_size'];
            $uncompressed = $stat['size'];
            if ($compressed > $this->limits->maxEntryCompressedBytes || $uncompressed > $this->limits->maxEntryUncompressedBytes) {
                throw new XlsxInspectionException(XlsxInspectionError::EntryTooLarge);
            }
            $compressedTotal += $compressed;
            $uncompressedTotal += $uncompressed;
            if ($compressedTotal > $this->limits->maxTotalCompressedBytes || $uncompressedTotal > $this->limits->maxTotalUncompressedBytes) {
                throw new XlsxInspectionException(XlsxInspectionError::ArchiveTooLarge);
            }
            if ($uncompressed > 0 && ($compressed === 0 || $uncompressed > $compressed * $this->limits->maxCompressionRatio)) {
                throw new XlsxInspectionException(XlsxInspectionError::CompressionRatioExceeded);
            }
            $encryption = $stat['encryption_method'];
            if ($encryption !== \ZipArchive::EM_NONE) {
                throw new XlsxInspectionException(XlsxInspectionError::EncryptedEntry);
            }
        }
        foreach ([self::CONTENT_TYPES, '_rels/.rels', self::WORKBOOK, self::WORKBOOK_RELS] as $required) {
            if (!isset($entries[$required])) {
                throw new XlsxInspectionException(XlsxInspectionError::MissingPackagePart);
            }
        }
        $xmlCounts = ['cells' => 0, 'merged_ranges' => 0, 'shared_strings' => 0];
        foreach (array_keys($entries) as $name) {
            if (str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels')) {
                $this->assertSafeXml($this->part($archive, $name), $xmlCounts);
            }
        }
        $this->validateContentTypes($this->part($archive, self::CONTENT_TYPES));
        $this->validateRelationships($archive, $entries);

        return $entries;
    }

    private function safePartName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }
        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function validateContentTypes(string $xml): void
    {
        $xpath = $this->xpath($xml);
        $workbook = $xpath->query('/*[local-name()="Types"]/*[local-name()="Override" and @PartName="/xl/workbook.xml"]');
        if ($workbook === false || $workbook->length !== 1) {
            throw new XlsxInspectionException(XlsxInspectionError::UnsupportedPackage);
        }
        $contentType = $workbook->item(0)?->attributes?->getNamedItem('ContentType')?->nodeValue;
        if ($contentType !== self::WORKBOOK_CONTENT_TYPE) {
            throw new XlsxInspectionException(XlsxInspectionError::UnsupportedPackage);
        }
        foreach ($this->nodes($xpath, '//*[@ContentType]') as $node) {
            $type = strtolower((string) $node->attributes?->getNamedItem('ContentType')?->nodeValue);
            if (str_contains($type, 'macroenabled') || str_contains($type, 'vba') || str_contains($type, 'oleobject')) {
                throw new XlsxInspectionException(XlsxInspectionError::UnsupportedPackage);
            }
        }
    }

    /** @param array<string, true> $entries */
    private function validateRelationships(\ZipArchive $archive, array $entries): void
    {
        foreach (array_keys($entries) as $name) {
            if (!str_ends_with(strtolower($name), '.rels')) {
                continue;
            }
            $xpath = $this->xpath($this->part($archive, $name));
            foreach ($this->nodes($xpath, '//*[local-name()="Relationship"]') as $relationship) {
                $targetMode = (string) $relationship->attributes?->getNamedItem('TargetMode')?->nodeValue;
                $type = strtolower((string) $relationship->attributes?->getNamedItem('Type')?->nodeValue);
                $target = (string) $relationship->attributes?->getNamedItem('Target')?->nodeValue;
                if (strcasecmp($targetMode, 'External') === 0 || str_contains($type, '/externallink')) {
                    throw new XlsxInspectionException(XlsxInspectionError::ExternalRelationship);
                }
                if (str_contains($type, 'vbaproject') || str_contains($type, 'oleobject') || str_contains($type, 'attachedtemplate') || str_ends_with($type, '/package')) {
                    throw new XlsxInspectionException(XlsxInspectionError::UnsafeRelationship);
                }
                if ($target === '' || str_starts_with($target, '//') || str_contains($target, '\\') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                    throw new XlsxInspectionException(XlsxInspectionError::UnsafeRelationship);
                }
                $base = $this->relationshipSourceDirectory($name);
                if ($this->normalizeTarget($base, $target) === null) {
                    throw new XlsxInspectionException(XlsxInspectionError::UnsafeRelationship);
                }
            }
        }
    }

    private function relationshipSourceDirectory(string $relationshipPart): string
    {
        if ($relationshipPart === '_rels/.rels') {
            return '';
        }
        $directory = dirname($relationshipPart);
        $parent = dirname($directory);

        return ($parent === '.' ? '' : $parent . '/') ;
    }

    private function normalizeTarget(string $base, string $target): ?string
    {
        $candidate = str_starts_with($target, '/') ? substr($target, 1) : $base . $target;
        $segments = [];
        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /** @param array<string, true> $entries */
    private function parseWorkbook(\ZipArchive $archive, array $entries, string $checksum): XlsxWorkbookInspection
    {
        $data = $this->parseWorkbookData($archive, $entries);
        $sheets = [];
        foreach ($data['sheets'] as $sheet) {
            $structures = array_map(
                static fn(array $cell): XlsxCellStructure => new XlsxCellStructure(
                    $cell['coordinate'],
                    $cell['row'],
                    $cell['column'],
                    $cell['type'],
                    $cell['is_date'],
                ),
                $sheet['cells'],
            );
            $sheets[] = new XlsxSheetInspection(
                $sheet['key'],
                $sheet['name'],
                $sheet['declared_dimension'],
                $sheet['observed_dimension'],
                $sheet['merged_ranges'],
                $sheet['regions'],
                $structures,
            );
        }

        return new XlsxWorkbookInspection($checksum, $data['date_system'], $sheets);
    }

    /**
     * @param array<string, true> $entries
     * @return array{date_system: string, sheets: list<array<string, mixed>>}
     */
    private function parseWorkbookData(\ZipArchive $archive, array $entries): array
    {
        $workbook = $this->xpath($this->part($archive, self::WORKBOOK));
        $date1904 = $this->nodes($workbook, '/*[local-name()="workbook"]/*[local-name()="workbookPr"]/@date1904')->item(0)?->nodeValue;
        $dateSystem = in_array(strtolower((string) $date1904), ['1', 'true'], true) ? '1904' : '1900';

        $relationships = [];
        $rels = $this->xpath($this->part($archive, self::WORKBOOK_RELS));
        foreach ($this->nodes($rels, '//*[local-name()="Relationship"]') as $relationship) {
            $id = (string) $relationship->attributes?->getNamedItem('Id')?->nodeValue;
            $type = strtolower((string) $relationship->attributes?->getNamedItem('Type')?->nodeValue);
            $target = (string) $relationship->attributes?->getNamedItem('Target')?->nodeValue;
            $resolved = $this->normalizeTarget('xl/', $target);
            if ($id !== '' && $resolved !== null) {
                $relationships[$id] = ['type' => $type, 'target' => $resolved];
            }
        }

        $sharedStrings = [];
        foreach ($relationships as $relationship) {
            if (str_contains($relationship['type'], '/sharedstrings')) {
                if (!isset($entries[$relationship['target']])) {
                    throw new XlsxInspectionException(XlsxInspectionError::MissingPackagePart);
                }
                $sharedStrings = $this->sharedStrings($archive, $relationship['target']);
            }
        }
        $dateStyles = [];
        foreach ($relationships as $relationship) {
            if (str_contains($relationship['type'], '/styles')) {
                if (!isset($entries[$relationship['target']])) {
                    throw new XlsxInspectionException(XlsxInspectionError::MissingPackagePart);
                }
                $dateStyles = $this->dateStyles($archive, $relationship['target']);
            }
        }

        $sheetNodes = $workbook->query('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]');
        if ($sheetNodes === false || $sheetNodes->length > $this->limits->maxSheets) {
            throw new XlsxInspectionException(XlsxInspectionError::TooManySheets);
        }
        $sheets = [];
        $cellTotal = 0;
        $mergeTotal = 0;
        foreach ($sheetNodes as $index => $sheetNode) {
            if (!$sheetNode instanceof \DOMElement) {
                throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
            }
            $relationshipId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $relationship = $relationships[$relationshipId] ?? null;
            if (!is_array($relationship) || !str_contains($relationship['type'], '/worksheet') || !isset($entries[$relationship['target']])) {
                throw new XlsxInspectionException(XlsxInspectionError::MissingPackagePart);
            }
            $sheet = $this->worksheet(
                $archive,
                $relationship['target'],
                'sheet-' . ($index + 1),
                (string) $sheetNode->attributes->getNamedItem('name')?->nodeValue,
                $sharedStrings,
                $dateStyles,
            );
            $cellTotal += count($sheet['cells']);
            $mergeTotal += count($sheet['merged_ranges']);
            if ($cellTotal > $this->limits->maxCells) {
                throw new XlsxInspectionException(XlsxInspectionError::CellLimitExceeded);
            }
            if ($mergeTotal > $this->limits->maxMergedRanges) {
                throw new XlsxInspectionException(XlsxInspectionError::MergeLimitExceeded);
            }
            $sheets[] = $sheet;
        }

        return ['date_system' => $dateSystem, 'sheets' => $sheets];
    }

    /** @return list<string> */
    private function sharedStrings(\ZipArchive $archive, string $part): array
    {
        $xpath = $this->xpath($this->part($archive, $part));
        $values = [];
        foreach ($this->nodes($xpath, '/*[local-name()="sst"]/*[local-name()="si"]') as $item) {
            $value = '';
            foreach ($this->nodes($xpath, './/*[local-name()="t"]', $item) as $text) {
                $value .= $text->textContent;
            }
            $this->assertText($value);
            $values[] = $value;
            if (count($values) > $this->limits->maxSharedStrings) {
                throw new XlsxInspectionException(XlsxInspectionError::SharedStringLimitExceeded);
            }
        }

        return $values;
    }

    /** @return array<int, true> */
    private function dateStyles(\ZipArchive $archive, string $part): array
    {
        $xpath = $this->xpath($this->part($archive, $part));
        $custom = [];
        foreach ($this->nodes($xpath, '//*[local-name()="numFmt"]') as $format) {
            $id = (int) $format->attributes?->getNamedItem('numFmtId')?->nodeValue;
            $code = strtolower((string) $format->attributes?->getNamedItem('formatCode')?->nodeValue);
            $code = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $code) ?? '';
            if (preg_match('/[ymdhis]/', $code) === 1) {
                $custom[$id] = true;
            }
        }
        $styles = [];
        foreach ($this->nodes($xpath, '/*[local-name()="styleSheet"]/*[local-name()="cellXfs"]/*[local-name()="xf"]') as $index => $style) {
            $formatId = (int) $style->attributes?->getNamedItem('numFmtId')?->nodeValue;
            if (($formatId >= 14 && $formatId <= 22) || ($formatId >= 45 && $formatId <= 47) || isset($custom[$formatId])) {
                $styles[$index] = true;
            }
        }

        return $styles;
    }

    /**
     * @param list<string> $sharedStrings
     * @param array<int, true> $dateStyles
     * @return array<string, mixed>
     */
    private function worksheet(
        \ZipArchive $archive,
        string $part,
        string $key,
        string $name,
        array $sharedStrings,
        array $dateStyles,
    ): array {
        $xpath = $this->xpath($this->part($archive, $part));
        $dimensionValue = $this->nodes($xpath, '/*[local-name()="worksheet"]/*[local-name()="dimension"]/@ref')->item(0)?->nodeValue;
        $declaredDimension = null;
        if (is_string($dimensionValue) && $dimensionValue !== '') {
            $declaredDimension = $this->sourceRange($dimensionValue)->a1();
        }
        $cells = [];
        $seen = [];
        foreach ($this->nodes($xpath, '/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]') as $cellNode) {
            if ($this->nodes($xpath, './*[local-name()="f"]', $cellNode)->length > 0) {
                throw new XlsxInspectionException(XlsxInspectionError::FormulaNotAllowed);
            }
            $parsed = XlsxCoordinate::parse((string) $cellNode->attributes?->getNamedItem('r')?->nodeValue, $this->limits);
            if (isset($seen[$parsed['coordinate']])) {
                throw new XlsxInspectionException(XlsxInspectionError::InvalidCellReference);
            }
            $seen[$parsed['coordinate']] = true;
            $typeValue = (string) $cellNode->attributes?->getNamedItem('t')?->nodeValue;
            $style = (int) $cellNode->attributes?->getNamedItem('s')?->nodeValue;
            [$type, $value] = $this->cellValue($xpath, $cellNode, $typeValue, $sharedStrings);
            if ($type === XlsxCellType::Blank) {
                continue;
            }
            $cells[] = [
                ...$parsed,
                'type' => $type,
                'is_date' => isset($dateStyles[$style]) && $type === XlsxCellType::Number,
                'value' => $value,
            ];
        }
        usort($cells, static fn(array $a, array $b): int => [$a['row'], $a['column']] <=> [$b['row'], $b['column']]);

        $mergedRanges = [];
        foreach ($this->nodes($xpath, '/*[local-name()="worksheet"]/*[local-name()="mergeCells"]/*[local-name()="mergeCell"]/@ref') as $range) {
            $mergedRanges[] = $this->sourceRange((string) $range->nodeValue)->a1();
        }
        sort($mergedRanges, SORT_STRING);

        return [
            'key' => $key,
            'name' => $name,
            'declared_dimension' => $declaredDimension,
            'observed_dimension' => $this->observedDimension($cells),
            'merged_ranges' => $mergedRanges,
            'regions' => $this->regions($cells),
            'cells' => $cells,
        ];
    }

    /** @return array{XlsxCellType, scalar|null} */
    private function cellValue(\DOMXPath $xpath, \DOMNode $cell, string $type, array $sharedStrings): array
    {
        $value = $this->nodes($xpath, './*[local-name()="v"]', $cell)->item(0)?->textContent;
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($this->nodes($xpath, './*[local-name()="is"]//*[local-name()="t"]', $cell) as $node) {
                $text .= $node->textContent;
            }
            $this->assertText($text);

            return [XlsxCellType::InlineString, $text];
        }
        if ($type === 's') {
            if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1 || !array_key_exists((int) $value, $sharedStrings)) {
                throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
            }

            return [XlsxCellType::SharedString, $sharedStrings[(int) $value]];
        }
        if ($type === 'b') {
            if ($value !== '0' && $value !== '1') {
                throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
            }

            return [XlsxCellType::Boolean, $value === '1'];
        }
        if ($type === 'e') {
            return [XlsxCellType::Error, null];
        }
        if ($type === 'str') {
            $text = is_string($value) ? $value : '';
            $this->assertText($text);

            return [XlsxCellType::String, $text];
        }
        if (!is_string($value) || trim($value) === '') {
            return [XlsxCellType::Blank, null];
        }
        if (!is_numeric($value)) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1) {
            $number = (int) $value;
            if ((string) $number !== $value && !($value === '-0' && $number === 0)) {
                throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
            }
        } else {
            $number = (float) $value;
        }
        if (is_float($number) && !is_finite($number)) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
        }

        return [XlsxCellType::Number, $number];
    }

    private function assertText(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidCellValue);
        }
        if (mb_strlen($text, 'UTF-8') > $this->limits->maxTextLength) {
            throw new XlsxInspectionException(XlsxInspectionError::TextLimitExceeded);
        }
    }

    /** @param list<array<string, mixed>> $cells */
    private function observedDimension(array $cells): ?string
    {
        if ($cells === []) {
            return null;
        }
        $rows = array_column($cells, 'row');
        $columns = array_column($cells, 'column');
        $start = XlsxCoordinate::format(min($rows), min($columns));
        $end = XlsxCoordinate::format(max($rows), max($columns));

        return $start === $end ? $start : $start . ':' . $end;
    }

    /** @param list<array<string, mixed>> $cells @return list<XlsxPopulatedRegion> */
    private function regions(array $cells): array
    {
        $byPosition = [];
        foreach ($cells as $cell) {
            $byPosition[$cell['row'] . ':' . $cell['column']] = $cell;
        }
        $seen = [];
        $regions = [];
        foreach ($cells as $cell) {
            $origin = $cell['row'] . ':' . $cell['column'];
            if (isset($seen[$origin])) {
                continue;
            }
            $queue = [$cell];
            $seen[$origin] = true;
            $members = [];
            while ($queue !== []) {
                $current = array_shift($queue);
                $members[] = $current;
                foreach ([[0, 1], [1, 0], [0, -1], [-1, 0]] as [$rowDelta, $columnDelta]) {
                    $row = $current['row'] + $rowDelta;
                    $column = $current['column'] + $columnDelta;
                    $position = $row . ':' . $column;
                    if ($row > 0 && $column > 0 && isset($byPosition[$position]) && !isset($seen[$position])) {
                        $seen[$position] = true;
                        $queue[] = $byPosition[$position];
                    }
                }
            }
            $rows = array_column($members, 'row');
            $columns = array_column($members, 'column');
            $regions[] = new XlsxPopulatedRegion(
                'region-' . (count($regions) + 1),
                XlsxCoordinate::format(min($rows), min($columns)),
                XlsxCoordinate::format(max($rows), max($columns)),
                count($members),
            );
        }

        return $regions;
    }

    /** @param list<XlsxSelectionArea> $areas */
    private function matchesAny(array $areas, string $coordinate, int $row, int $column): bool
    {
        foreach ($areas as $area) {
            if ($area->contains($coordinate, $row, $column)) {
                return true;
            }
        }

        return false;
    }

    private function part(\ZipArchive $archive, string $name): string
    {
        $contents = $archive->getFromName($name, 0, \ZipArchive::FL_UNCHANGED);
        if (!is_string($contents)) {
            throw new XlsxInspectionException(XlsxInspectionError::MissingPackagePart);
        }

        return $contents;
    }

    private function xpath(string $xml): \DOMXPath
    {
        return new \DOMXPath($this->xmlDocument($xml));
    }

    private function nodes(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): \DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
        }

        return $nodes;
    }

    private function xmlDocument(string $xml): \DOMDocument
    {
        $this->assertSafeXml($xml);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) {
                throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array{cells: int, merged_ranges: int, shared_strings: int}|null $counts */
    private function assertSafeXml(string $xml, ?array &$counts = null): void
    {
        if (strlen($xml) > $this->limits->maxXmlBytes) {
            throw new XlsxInspectionException(XlsxInspectionError::XmlTooLarge);
        }
        if ($xml === '') {
            throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new XlsxInspectionException(XlsxInspectionError::UnsafeXml);
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            try {
                $reader = \XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
            } catch (\ValueError) {
                throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
            }
            if ($reader === false) {
                throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
            }
            while ($reader->read()) {
                if ($reader->depth > $this->limits->maxXmlDepth) {
                    throw new XlsxInspectionException(XlsxInspectionError::XmlTooDeep);
                }
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY, \XMLReader::ENTITY_REF], true)) {
                    throw new XlsxInspectionException(XlsxInspectionError::UnsafeXml);
                }
                if ($counts !== null && $reader->nodeType === \XMLReader::ELEMENT) {
                    if ($reader->localName === 'f') {
                        throw new XlsxInspectionException(XlsxInspectionError::FormulaNotAllowed);
                    }
                    if ($reader->localName === 'c' && ++$counts['cells'] > $this->limits->maxCells) {
                        throw new XlsxInspectionException(XlsxInspectionError::CellLimitExceeded);
                    }
                    if ($reader->localName === 'mergeCell' && ++$counts['merged_ranges'] > $this->limits->maxMergedRanges) {
                        throw new XlsxInspectionException(XlsxInspectionError::MergeLimitExceeded);
                    }
                    if ($reader->localName === 'si' && ++$counts['shared_strings'] > $this->limits->maxSharedStrings) {
                        throw new XlsxInspectionException(XlsxInspectionError::SharedStringLimitExceeded);
                    }
                }
            }
            if (libxml_get_errors() !== []) {
                throw new XlsxInspectionException(XlsxInspectionError::MalformedXml);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sourceRange(string $range): XlsxCellRange
    {
        try {
            return XlsxCellRange::fromA1($range, $this->limits);
        } catch (\InvalidArgumentException) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidCellReference);
        }
    }
}
