<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Tests\Support;

/** Builds small synthetic OOXML packages without using application data. */
final class SyntheticXlsxBuilder
{
    public const string SHARED_NAME = 'Synthetic Person Alpha';
    public const string INLINE_ROLE = 'Synthetic Role Omega';
    public const string SIDE_LABEL = 'Synthetic Side Region';
    public const string FORMULA = 'SENSITIVE_FORMULA_SENTINEL+1';

    public static function valid(string $path): void
    {
        self::write($path, self::parts());
    }

    public static function withFormula(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = str_replace(
            '</sheetData>',
            '<row r="8"><c r="A8"><f>'.self::FORMULA.'</f><v>42</v></c></row></sheetData>',
            $parts['xl/worksheets/sheet1.xml'],
        );
        self::write($path, $parts);
    }

    public static function withExternalRelationship(string $path): void
    {
        $parts = self::parts();
        $parts['xl/_rels/workbook.xml.rels'] = str_replace(
            '</Relationships>',
            '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="https://example.invalid/private.xlsx" TargetMode="External"/></Relationships>',
            $parts['xl/_rels/workbook.xml.rels'],
        );
        self::write($path, $parts);
    }

    public static function withNetworkPathRelationship(string $path): void
    {
        $parts = self::parts();
        $parts['xl/_rels/workbook.xml.rels'] = str_replace(
            '</Relationships>',
            '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="//example.invalid/private.png"/></Relationships>',
            $parts['xl/_rels/workbook.xml.rels'],
        );
        self::write($path, $parts);
    }

    public static function withEmbeddedPackageRelationship(string $path): void
    {
        $parts = self::parts();
        $parts['xl/_rels/workbook.xml.rels'] = str_replace(
            '</Relationships>',
            '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/package" Target="embeddings/private.bin"/></Relationships>',
            $parts['xl/_rels/workbook.xml.rels'],
        );
        $parts['xl/embeddings/private.bin'] = 'synthetic embedded package';
        self::write($path, $parts);
    }

    public static function withEmptyXmlPart(string $path): void
    {
        $parts = self::parts();
        $parts['xl/unused.xml'] = '';
        self::write($path, $parts);
    }

    public static function withReversedDimension(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = str_replace('ref="A1:F7"', 'ref="B2:A1"', $parts['xl/worksheets/sheet1.xml']);
        self::write($path, $parts);
    }

    public static function withBlankBridge(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <dimension ref="A1:C1"/>
              <sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Left</t></is></c><c r="B1" s="1"/><c r="C1" t="inlineStr"><is><t>Right</t></is></c></row></sheetData>
            </worksheet>
            XML;
        self::write($path, $parts);
    }

    public static function withOversizedInteger(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = str_replace(
            '</sheetData>',
            '<row r="8"><c r="A8"><v>99999999999999999999</v></c></row></sheetData>',
            $parts['xl/worksheets/sheet1.xml'],
        );
        self::write($path, $parts);
    }

    public static function withUnreferencedFormula(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/unreferenced.xml'] = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><f>'.self::FORMULA.'</f><v>42</v></c></row></sheetData></worksheet>';
        self::write($path, $parts);
    }

    public static function exceedingDefaultCellLimit(string $path): void
    {
        $parts = self::parts();
        $cells = '';
        for ($row = 1; $row <= 50_001; ++$row) {
            $cells .= '<row r="'.$row.'"><c r="A'.$row.'"><v>'.$row.'</v></c></row>';
        }
        $parts['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$cells.'</sheetData></worksheet>';
        self::write($path, $parts);
    }

    public static function withMalformedWorksheet(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = '<worksheet><sheetData><row>';
        self::write($path, $parts);
    }

    public static function withDoctype(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY private "PRIVATE_ENTITY_SENTINEL">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&private;</t></is></c></row></sheetData></worksheet>';
        self::write($path, $parts);
    }

    public static function withHighlyCompressedEntry(string $path): void
    {
        $parts = self::parts();
        $parts['xl/worksheets/unused.xml'] = str_repeat('A', 200_000);
        self::write($path, $parts);
    }

    /** @param array<string, string> $parts */
    private static function write(string $path, array $parts): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create synthetic XLSX fixture.');
        }
        foreach ($parts as $name => $contents) {
            if (!$zip->addFromString($name, $contents)) {
                $zip->close();
                throw new \RuntimeException('Could not add a synthetic XLSX fixture part.');
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Could not close synthetic XLSX fixture.');
        }
    }

    /** @return array<string, string> */
    private static function parts(): array
    {
        return [
            '[Content_Types].xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                  <Default Extension="xml" ContentType="application/xml"/>
                  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
                  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
                </Types>
                XML,
            '_rels/.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
                </Relationships>
                XML,
            'xl/workbook.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                  <workbookPr date1904="0"/>
                  <sheets>
                    <sheet name="Directory" sheetId="1" r:id="rId1"/>
                    <sheet name="Notes" sheetId="2" r:id="rId2"/>
                  </sheets>
                </workbook>
                XML,
            'xl/_rels/workbook.xml.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
                  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
                  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
                  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
                </Relationships>
                XML,
            'xl/sharedStrings.xml' => sprintf(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3">
                  <si><t>%s</t></si>
                  <si><r><t>%s</t></r><r><t> Region</t></r></si>
                  <si><t>Notes heading</t></si>
                </sst>
                XML, self::SHARED_NAME, self::SIDE_LABEL),
            'xl/styles.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                  <fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders>
                  <cellStyleXfs count="1"><xf numFmtId="0"/></cellStyleXfs>
                  <cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>
                </styleSheet>
                XML,
            'xl/worksheets/sheet1.xml' => sprintf(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                  <dimension ref="A1:F7"/>
                  <sheetData>
                    <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>%s</t></is></c><c r="F1" t="s"><v>1</v></c></row>
                    <row r="2"><c r="A2" t="n"><v>7</v></c><c r="B2" t="b"><v>1</v></c><c r="F2" t="inlineStr"><is><t>Separate block</t></is></c></row>
                    <row r="5"><c r="D5" s="1" t="n"><v>45292</v></c></row>
                    <row r="7"><c r="C7" t="inlineStr"><is><t>Merged heading</t></is></c></row>
                  </sheetData>
                  <mergeCells count="1"><mergeCell ref="C7:D7"/></mergeCells>
                </worksheet>
                XML, self::INLINE_ROLE),
            'xl/worksheets/sheet2.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                  <dimension ref="A1:A1"/>
                  <sheetData><row r="1"><c r="A1" t="s"><v>2</v></c></row></sheetData>
                </worksheet>
                XML,
        ];
    }
}
