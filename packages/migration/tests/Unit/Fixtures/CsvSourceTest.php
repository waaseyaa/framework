<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Tests\Fixtures\CsvSource;

/**
 * Smoke coverage for the reference {@see CsvSource} fixture (T053).
 *
 * The conformance gates already exercise CsvSource against the small
 * fixture — these unit tests cover the targeted edges (missing file,
 * empty header, multi-key SourceId) that the conformance suite does not
 * touch directly.
 *
 * @spec FR-052 — reference CSV source
 */
#[CoversNothing]
final class CsvSourceTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/waaseyaa_csv_source_' . \bin2hex(\random_bytes(8));
        \mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && \is_dir($this->tmpDir)) {
            foreach (\glob($this->tmpDir . '/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->tmpDir);
        }
        $this->tmpDir = '';
        parent::tearDown();
    }

    #[Test]
    public function happy_path_iterates_small_fixture(): void
    {
        $smallPath = \dirname(__DIR__, 2) . '/Fixtures/data/conformance-small.csv';
        $source = new CsvSource(filePath: $smallPath, keyFields: ['id']);

        $records = [];
        foreach ($source->records() as $record) {
            $records[] = $record;
        }

        self::assertCount(120, $records, 'Small fixture must yield 120 data rows.');
        self::assertInstanceOf(SourceRecord::class, $records[0]);
        self::assertSame('1', $records[0]->fields['id']);
        self::assertSame('Title #1', $records[0]->fields['title']);
    }

    #[Test]
    public function source_id_for_includes_all_key_fields(): void
    {
        $smallPath = \dirname(__DIR__, 2) . '/Fixtures/data/conformance-small.csv';
        $source = new CsvSource(filePath: $smallPath, keyFields: ['id', 'title']);

        $first = null;
        foreach ($source->records() as $record) {
            $first = $record;
            break;
        }
        self::assertNotNull($first);

        $id = $source->sourceIdFor($first);
        self::assertSame('csv', $id->sourceType);
        self::assertSame(['id' => '1', 'title' => 'Title #1'], $id->keys);
    }

    #[Test]
    public function missing_file_raises_source_read_exception(): void
    {
        $source = new CsvSource(filePath: $this->tmpDir . '/does-not-exist.csv', keyFields: ['id']);

        $this->expectException(SourceReadException::class);

        foreach ($source->records() as $_record) {
            unset($_record);
        }
    }

    #[Test]
    public function empty_csv_raises_source_read_exception(): void
    {
        $empty = $this->tmpDir . '/empty.csv';
        \file_put_contents($empty, '');
        $source = new CsvSource(filePath: $empty, keyFields: ['id']);

        $this->expectException(SourceReadException::class);

        foreach ($source->records() as $_record) {
            unset($_record);
        }
    }

    #[Test]
    public function header_only_csv_yields_zero_records(): void
    {
        $headerOnly = $this->tmpDir . '/header-only.csv';
        \file_put_contents($headerOnly, "id,title\n");
        $source = new CsvSource(filePath: $headerOnly, keyFields: ['id']);

        $records = [];
        foreach ($source->records() as $record) {
            $records[] = $record;
        }

        self::assertSame([], $records, 'Header-only CSV must yield zero records.');
    }

    #[Test]
    public function source_id_for_rejects_missing_key_field(): void
    {
        $headerOnly = $this->tmpDir . '/no-key.csv';
        \file_put_contents($headerOnly, "title\nfoo\n");
        $source = new CsvSource(filePath: $headerOnly, keyFields: ['id']);

        $first = null;
        foreach ($source->records() as $record) {
            $first = $record;
            break;
        }
        self::assertNotNull($first);

        $this->expectException(SourceReadException::class);
        $source->sourceIdFor($first);
    }

    #[Test]
    public function count_returns_null_by_documented_contract(): void
    {
        $smallPath = \dirname(__DIR__, 2) . '/Fixtures/data/conformance-small.csv';
        $source = new CsvSource(filePath: $smallPath, keyFields: ['id']);

        self::assertNull($source->count());
    }

    #[Test]
    public function constructor_rejects_empty_key_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CsvSource(filePath: '/tmp/whatever.csv', keyFields: []);
    }

    #[Test]
    public function constructor_rejects_non_single_character_delimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CsvSource(filePath: '/tmp/whatever.csv', keyFields: ['id'], delimiter: '||');
    }

    #[Test]
    public function constructor_rejects_unknown_stability_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CsvSource(filePath: '/tmp/whatever.csv', keyFields: ['id'], stability: 'preview');
    }

    #[Test]
    public function id_and_stability_are_stable_strings(): void
    {
        $source = new CsvSource(filePath: '/tmp/whatever.csv', keyFields: ['id'], pluginId: 'my_csv_source');
        self::assertSame('my_csv_source', $source->id());
        self::assertSame('stable', $source->stability());
    }
}
