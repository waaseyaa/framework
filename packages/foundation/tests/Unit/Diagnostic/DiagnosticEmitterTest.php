<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\DiagnosticEmitter;
use Waaseyaa\Foundation\Diagnostic\DiagnosticEntry;

#[CoversClass(DiagnosticCode::class)]
#[CoversClass(DiagnosticEmitter::class)]
#[CoversClass(DiagnosticEntry::class)]
final class DiagnosticEmitterTest extends TestCase
{
    // -----------------------------------------------------------------------
    // DiagnosticCode enum
    // -----------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function allCodeNames(): array
    {
        return [
            'DEFAULT_TYPE_MISSING'        => ['DEFAULT_TYPE_MISSING'],
            'DEFAULT_TYPE_DISABLED'       => ['DEFAULT_TYPE_DISABLED'],
            'UNAUTHORIZED_V1_TAG'         => ['UNAUTHORIZED_V1_TAG'],
            'TAG_QUARANTINE_DETECTED'     => ['TAG_QUARANTINE_DETECTED'],
            'MANIFEST_VERSIONING_MISSING' => ['MANIFEST_VERSIONING_MISSING'],
            'NAMESPACE_RESERVED'          => ['NAMESPACE_RESERVED'],
            'DATABASE_UNREACHABLE'        => ['DATABASE_UNREACHABLE'],
            'DATABASE_SCHEMA_DRIFT'       => ['DATABASE_SCHEMA_DRIFT'],
            'CACHE_DIRECTORY_UNWRITABLE'  => ['CACHE_DIRECTORY_UNWRITABLE'],
            'STORAGE_DIRECTORY_MISSING'   => ['STORAGE_DIRECTORY_MISSING'],
            'INGESTION_LOG_OVERSIZED'     => ['INGESTION_LOG_OVERSIZED'],
            'INGESTION_RECENT_FAILURES'   => ['INGESTION_RECENT_FAILURES'],
        ];
    }

    #[Test]
    #[DataProvider('allCodeNames')]
    public function allCodesExist(string $name): void
    {
        $code = DiagnosticCode::from($name);
        $this->assertSame($name, $code->value);
    }

    #[Test]
    #[DataProvider('allCodeNames')]
    public function allCodesHaveRemediationSteps(string $name): void
    {
        $code = DiagnosticCode::from($name);
        $this->assertNotEmpty($code->remediation());
    }

    #[Test]
    #[DataProvider('allCodeNames')]
    public function allCodesHaveDefaultMessage(string $name): void
    {
        $code = DiagnosticCode::from($name);
        $this->assertNotEmpty($code->defaultMessage());
    }

    #[Test]
    #[DataProvider('allCodeNames')]
    public function allCodesHaveSeverity(string $name): void
    {
        $code = DiagnosticCode::from($name);
        $this->assertContains($code->severity(), ['error', 'warning']);
    }

    // -----------------------------------------------------------------------
    // DiagnosticEntry value object
    // -----------------------------------------------------------------------

    #[Test]
    public function entryExposesAllFields(): void
    {
        $entry = new DiagnosticEntry(
            code: DiagnosticCode::DEFAULT_TYPE_MISSING,
            message: 'No types at boot.',
            context: ['registered' => 0],
        );

        $this->assertSame(DiagnosticCode::DEFAULT_TYPE_MISSING, $entry->code);
        $this->assertSame('No types at boot.', $entry->message);
        $this->assertSame(['registered' => 0], $entry->context);
        $this->assertSame(DiagnosticCode::DEFAULT_TYPE_MISSING->remediation(), $entry->remediation);
    }

    #[Test]
    public function entrySerializesToArray(): void
    {
        $entry = new DiagnosticEntry(
            code: DiagnosticCode::DEFAULT_TYPE_DISABLED,
            message: 'All types disabled.',
            context: ['disabled' => ['note']],
        );

        $arr = $entry->toArray();

        $this->assertSame('DEFAULT_TYPE_DISABLED', $arr['code']);
        $this->assertSame('All types disabled.', $arr['message']);
        $this->assertSame(['disabled' => ['note']], $arr['context']);
        $this->assertArrayHasKey('remediation', $arr);
        $this->assertNotEmpty($arr['remediation']);
    }

    // -----------------------------------------------------------------------
    // DiagnosticEmitter
    // -----------------------------------------------------------------------

    #[Test]
    public function emitReturnsCorrectEntry(): void
    {
        $emitter = new DiagnosticEmitter();

        $entry = $emitter->emit(
            DiagnosticCode::DEFAULT_TYPE_MISSING,
            'Zero types registered at boot.',
            ['entity_type_count' => 0],
        );

        $this->assertSame(DiagnosticCode::DEFAULT_TYPE_MISSING, $entry->code);
        $this->assertSame('Zero types registered at boot.', $entry->message);
        $this->assertSame(['entity_type_count' => 0], $entry->context);
    }

    #[Test]
    public function emitWritesToLogger(): void
    {
        $lines = [];
        $logger = new \Waaseyaa\Foundation\Log\ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $emitter = new DiagnosticEmitter($logger);

        $emitter->emit(DiagnosticCode::NAMESPACE_RESERVED, 'core.foo blocked.', []);

        $contents = implode("\n", $lines);
        $this->assertStringContainsString('NAMESPACE_RESERVED', $contents);
    }

    #[Test]
    public function emitLogLineIsValidJson(): void
    {
        $lines = [];
        $logger = new \Waaseyaa\Foundation\Log\ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $emitter = new DiagnosticEmitter($logger);

        $emitter->emit(DiagnosticCode::DEFAULT_TYPE_DISABLED, 'All disabled.', ['count' => 2]);

        $raw = $lines[0] ?? '';

        // ErrorLogHandler formats as "[level] message". Extract the JSON part.
        $jsonStart = strpos($raw, '{');
        $this->assertNotFalse($jsonStart, 'Log line should contain a JSON object');

        $json = substr($raw, $jsonStart);
        $decoded = json_decode(trim($json), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('code', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('remediation', $decoded);
    }
}
