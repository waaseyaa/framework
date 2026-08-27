<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Tests\Unit\Xlsx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\StructuredImport\Tests\Support\SyntheticXlsxBuilder;
use Waaseyaa\StructuredImport\Xlsx\XlsxCellRange;
use Waaseyaa\StructuredImport\Xlsx\XlsxCellStructure;
use Waaseyaa\StructuredImport\Xlsx\XlsxCellType;
use Waaseyaa\StructuredImport\Xlsx\XlsxCoordinate;
use Waaseyaa\StructuredImport\Xlsx\XlsxInspectionError;
use Waaseyaa\StructuredImport\Xlsx\XlsxInspectionException;
use Waaseyaa\StructuredImport\Xlsx\XlsxInspectionLimits;
use Waaseyaa\StructuredImport\Xlsx\XlsxPopulatedRegion;
use Waaseyaa\StructuredImport\Xlsx\XlsxProtectedCell;
use Waaseyaa\StructuredImport\Xlsx\XlsxProtectedSelection;
use Waaseyaa\StructuredImport\Xlsx\XlsxSelection;
use Waaseyaa\StructuredImport\Xlsx\XlsxSelectionArea;
use Waaseyaa\StructuredImport\Xlsx\XlsxSheetInspection;
use Waaseyaa\StructuredImport\Xlsx\XlsxWorkbookInspection;
use Waaseyaa\StructuredImport\Xlsx\XlsxWorkbookInspector;

#[CoversClass(XlsxCellRange::class)]
#[CoversClass(XlsxCellStructure::class)]
#[CoversClass(XlsxCoordinate::class)]
#[CoversClass(XlsxInspectionException::class)]
#[CoversClass(XlsxInspectionLimits::class)]
#[CoversClass(XlsxPopulatedRegion::class)]
#[CoversClass(XlsxProtectedCell::class)]
#[CoversClass(XlsxProtectedSelection::class)]
#[CoversClass(XlsxSelection::class)]
#[CoversClass(XlsxSelectionArea::class)]
#[CoversClass(XlsxSheetInspection::class)]
#[CoversClass(XlsxWorkbookInspection::class)]
#[CoversClass(XlsxWorkbookInspector::class)]
final class XlsxWorkbookInspectorTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/waaseyaa-xlsx-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
        $this->path = $this->directory.'/private-source-name.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[Test]
    public function inspection_is_bounded_structural_and_value_free(): void
    {
        SyntheticXlsxBuilder::valid($this->path);
        $checksum = hash_file('sha256', $this->path);
        self::assertIsString($checksum);

        $inspection = new XlsxWorkbookInspector()->inspect($this->path, $checksum);

        self::assertSame($checksum, $inspection->sourceSha256);
        self::assertSame('1900', $inspection->dateSystem);
        self::assertCount(2, $inspection->sheets);
        self::assertSame('sheet-1', $inspection->sheets[0]->key);
        self::assertSame('Directory', $inspection->sheets[0]->name);
        self::assertSame('A1:F7', $inspection->sheets[0]->declaredDimension);
        self::assertSame('A1:F7', $inspection->sheets[0]->observedDimension);
        self::assertSame(['C7:D7'], $inspection->sheets[0]->mergedRanges);
        self::assertGreaterThanOrEqual(3, count($inspection->sheets[0]->populatedRegions));

        $cells = [];
        foreach ($inspection->sheets[0]->cells as $cell) {
            $cells[$cell->coordinate] = $cell;
        }
        self::assertSame(XlsxCellType::SharedString, $cells['A1']->type);
        self::assertSame(XlsxCellType::InlineString, $cells['B1']->type);
        self::assertSame(XlsxCellType::Number, $cells['D5']->type);
        self::assertTrue($cells['D5']->isDate);

        $serialized = json_encode($inspection, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(SyntheticXlsxBuilder::SHARED_NAME, $serialized);
        self::assertStringNotContainsString(SyntheticXlsxBuilder::INLINE_ROLE, $serialized);
        self::assertStringNotContainsString('45292', $serialized);
        self::assertStringNotContainsString($this->path, $serialized);
    }

    #[Test]
    public function values_require_an_explicit_redacted_protected_selection(): void
    {
        SyntheticXlsxBuilder::valid($this->path);
        $checksum = (string) hash_file('sha256', $this->path);
        $inspector = new XlsxWorkbookInspector();
        $inspection = $inspector->inspect($this->path, $checksum);
        $selection = new XlsxSelection(
            id: 'directory-primary',
            selected: [new XlsxSelectionArea(
                sheetKey: 'sheet-1',
                ranges: [new XlsxCellRange('A1', 'B2')],
                coordinates: ['D5'],
            )],
            ignored: [new XlsxSelectionArea(
                sheetKey: 'sheet-1',
                ranges: [new XlsxCellRange('F1', 'F2')],
            )],
        );

        $protected = $inspector->readProtectedSelection($this->path, $inspection, $selection);
        $cells = [];
        foreach ($protected->cells() as $cell) {
            $cells[$cell->coordinate] = $cell;
        }

        self::assertSame(SyntheticXlsxBuilder::SHARED_NAME, $cells['A1']->value());
        self::assertSame(SyntheticXlsxBuilder::INLINE_ROLE, $cells['B1']->value());
        self::assertSame(7, $cells['A2']->value());
        self::assertTrue($cells['B2']->value());
        self::assertSame(45292, $cells['D5']->value());
        self::assertTrue($cells['D5']->isDate);
        self::assertArrayNotHasKey('F1', $cells);

        $json = json_encode($protected, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('[REDACTED]', $json);
        self::assertStringNotContainsString(SyntheticXlsxBuilder::SHARED_NAME, $json);
        self::assertStringNotContainsString(SyntheticXlsxBuilder::INLINE_ROLE, $json);
        self::assertStringNotContainsString('45292', $json);

        $this->expectException(\LogicException::class);
        serialize($cells['A1']);
    }

    #[Test]
    public function formula_is_refused_without_exposing_formula_value_or_path(): void
    {
        SyntheticXlsxBuilder::withFormula($this->path);
        $logger = new RecordingLogger();

        try {
            new XlsxWorkbookInspector(logger: $logger)->inspect(
                $this->path,
                (string) hash_file('sha256', $this->path),
            );
            self::fail('Formula workbook should be refused.');
        } catch (XlsxInspectionException $exception) {
            self::assertSame(XlsxInspectionError::FormulaNotAllowed, $exception->reason);
            $diagnostics = $exception->getMessage().' '.json_encode($logger->records, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(SyntheticXlsxBuilder::FORMULA, $diagnostics);
            self::assertStringNotContainsString('42', $diagnostics);
            self::assertStringNotContainsString($this->path, $diagnostics);
            self::assertStringNotContainsString(basename($this->path), $diagnostics);
        }
    }

    #[Test]
    public function external_relationship_is_refused(): void
    {
        SyntheticXlsxBuilder::withExternalRelationship($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::ExternalRelationship));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function network_path_relationship_is_refused_even_without_target_mode(): void
    {
        SyntheticXlsxBuilder::withNetworkPathRelationship($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::UnsafeRelationship));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function embedded_package_relationship_is_refused(): void
    {
        SyntheticXlsxBuilder::withEmbeddedPackageRelationship($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::UnsafeRelationship));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function zip_magic_without_a_zip_or_xlsx_mime_is_refused_before_opening(): void
    {
        file_put_contents($this->path, "PK\x03\x04<html>not an OOXML package</html>");

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::InvalidMime));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function malformed_xml_is_refused_with_a_generic_diagnostic(): void
    {
        SyntheticXlsxBuilder::withMalformedWorksheet($this->path);

        try {
            new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
            self::fail('Malformed worksheet should be refused.');
        } catch (XlsxInspectionException $exception) {
            self::assertSame(XlsxInspectionError::MalformedXml, $exception->reason);
            self::assertStringNotContainsString($this->path, $exception->getMessage());
            self::assertStringNotContainsString('<worksheet>', $exception->getMessage());
        }
    }

    #[Test]
    public function empty_xml_and_reversed_source_ranges_are_typed_refusals(): void
    {
        SyntheticXlsxBuilder::withEmptyXmlPart($this->path);
        try {
            new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
            self::fail('Empty XML should be refused.');
        } catch (XlsxInspectionException $exception) {
            self::assertSame(XlsxInspectionError::MalformedXml, $exception->reason);
        }

        SyntheticXlsxBuilder::withReversedDimension($this->path);
        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::InvalidCellReference));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function styled_blank_cells_are_not_populated_or_returned(): void
    {
        SyntheticXlsxBuilder::withBlankBridge($this->path);
        $inspector = new XlsxWorkbookInspector();
        $inspection = $inspector->inspect($this->path, (string) hash_file('sha256', $this->path));

        self::assertCount(2, $inspection->sheets[0]->cells);
        self::assertCount(2, $inspection->sheets[0]->populatedRegions);
        $protected = $inspector->readProtectedSelection(
            $this->path,
            $inspection,
            new XlsxSelection('all', [new XlsxSelectionArea('sheet-1', ranges: [new XlsxCellRange('A1', 'C1')])]),
        );
        self::assertSame(['A1', 'C1'], array_map(static fn($cell): string => $cell->coordinate, $protected->cells()));
    }

    #[Test]
    public function integers_outside_the_runtime_range_are_refused_instead_of_clamped(): void
    {
        SyntheticXlsxBuilder::withOversizedInteger($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::InvalidCellValue));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function aggregate_cell_limit_is_enforced_during_streaming_package_validation(): void
    {
        SyntheticXlsxBuilder::exceedingDefaultCellLimit($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::CellLimitExceeded));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function formulas_are_refused_even_in_unreferenced_xml_parts(): void
    {
        SyntheticXlsxBuilder::withUnreferencedFormula($this->path);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::FormulaNotAllowed));
        new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
    }

    #[Test]
    public function doctype_and_entity_declarations_are_refused(): void
    {
        SyntheticXlsxBuilder::withDoctype($this->path);

        try {
            new XlsxWorkbookInspector()->inspect($this->path, (string) hash_file('sha256', $this->path));
            self::fail('DOCTYPE worksheet should be refused.');
        } catch (XlsxInspectionException $exception) {
            self::assertSame(XlsxInspectionError::UnsafeXml, $exception->reason);
            self::assertStringNotContainsString('PRIVATE_ENTITY_SENTINEL', $exception->getMessage());
        }
    }

    #[Test]
    public function excessive_uncompressed_entry_and_ratio_are_refused(): void
    {
        SyntheticXlsxBuilder::withHighlyCompressedEntry($this->path);
        $limits = new XlsxInspectionLimits(
            maxEntryUncompressedBytes: 100_000,
            maxTotalUncompressedBytes: 500_000,
            maxCompressionRatio: 20,
        );

        try {
            new XlsxWorkbookInspector($limits)->inspect($this->path, (string) hash_file('sha256', $this->path));
            self::fail('Excessive compressed fixture should be refused.');
        } catch (XlsxInspectionException $exception) {
            self::assertContains($exception->reason, [
                XlsxInspectionError::EntryTooLarge,
                XlsxInspectionError::CompressionRatioExceeded,
            ]);
        }
    }

    #[Test]
    public function source_change_between_inspection_and_selection_is_refused(): void
    {
        SyntheticXlsxBuilder::valid($this->path);
        $inspector = new XlsxWorkbookInspector();
        $inspection = $inspector->inspect($this->path, (string) hash_file('sha256', $this->path));
        file_put_contents($this->path, 'mutation', FILE_APPEND);

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::SourceChecksumMismatch));
        $inspector->readProtectedSelection(
            $this->path,
            $inspection,
            new XlsxSelection('one', [new XlsxSelectionArea('sheet-1', coordinates: ['A1'])]),
        );
    }

    #[Test]
    public function selection_for_a_sheet_outside_the_inspected_workbook_is_refused(): void
    {
        SyntheticXlsxBuilder::valid($this->path);
        $inspector = new XlsxWorkbookInspector();
        $inspection = $inspector->inspect($this->path, (string) hash_file('sha256', $this->path));

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::InvalidSelection));
        $inspector->readProtectedSelection(
            $this->path,
            $inspection,
            new XlsxSelection('unknown-sheet', [new XlsxSelectionArea('sheet-99', coordinates: ['A1'])]),
        );
    }

    #[Test]
    public function selection_limits_and_selected_ignored_disjointness_are_enforced(): void
    {
        $limits = new XlsxInspectionLimits(maxRows: 10, maxColumns: 10);

        try {
            new XlsxCellRange('A1', 'K1', $limits);
            self::fail('Configured column limit should be enforced.');
        } catch (XlsxInspectionException $exception) {
            self::assertSame(XlsxInspectionError::ColumnLimitExceeded, $exception->reason);
        }

        $this->expectExceptionObject(new XlsxInspectionException(XlsxInspectionError::InvalidSelection));
        new XlsxSelection(
            'overlap',
            [new XlsxSelectionArea('sheet-1', ranges: [new XlsxCellRange('A1', 'B2')])],
            [new XlsxSelectionArea('sheet-1', coordinates: ['B2'])],
        );
    }
}

final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level->value, 'message' => (string) $message, 'context' => $context];
    }
}
