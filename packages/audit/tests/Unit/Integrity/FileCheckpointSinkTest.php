<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\Audit\Integrity\FileCheckpointSink;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Unit coverage for {@see FileCheckpointSink}.
 */
#[CoversClass(FileCheckpointSink::class)]
final class FileCheckpointSinkTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_checkpoint_sink_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeCheckpoint(string $uuid = 'test-uuid-0001'): AuditCheckpoint
    {
        return new AuditCheckpoint([
            'uuid'                 => $uuid,
            'segment_start_id'     => 1,
            'segment_end_id'       => 3,
            'row_count'            => 3,
            'segment_hash'         => str_repeat('a', 64),
            'prev_checkpoint_hash' => AuditEventCanonicalizer::GENESIS_HASH,
            'checkpoint_hash'      => str_repeat('b', 64),
            'signature'            => '',
            'hash_version'         => 'v1',
            'is_genesis'           => false,
            'created_at'           => '2026-01-01 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function testAppendCreatesFileAndWritesOneLine(): void
    {
        $filePath = $this->tmpDir . '/checkpoints.jsonl';
        $sink     = new FileCheckpointSink($filePath, false);

        $sink->export($this->makeCheckpoint());

        self::assertFileExists($filePath);
        $lines = array_filter(
            explode("\n", file_get_contents($filePath) ?: ''),
            static fn(string $l): bool => $l !== '',
        );
        self::assertCount(1, $lines, 'exactly one line must be written');
        $decoded = json_decode(array_values($lines)[0], true);
        self::assertIsArray($decoded);
        self::assertSame('test-uuid-0001', $decoded['uuid']);
    }

    #[Test]
    public function testTwoExportsWriteTwoLines(): void
    {
        $filePath = $this->tmpDir . '/checkpoints.jsonl';
        $sink     = new FileCheckpointSink($filePath, false);

        $sink->export($this->makeCheckpoint('uuid-first'));
        $sink->export($this->makeCheckpoint('uuid-second'));

        $lines = array_values(array_filter(
            explode("\n", file_get_contents($filePath) ?: ''),
            static fn(string $l): bool => $l !== '',
        ));
        self::assertCount(2, $lines);
        $firstDecoded  = json_decode($lines[0], true);
        $secondDecoded = json_decode($lines[1], true);
        self::assertIsArray($firstDecoded);
        self::assertIsArray($secondDecoded);
        self::assertSame('uuid-first', $firstDecoded['uuid']);
        self::assertSame('uuid-second', $secondDecoded['uuid']);
    }

    #[Test]
    public function testEchoStdoutFalseDoesNotOutputToStdout(): void
    {
        $filePath = $this->tmpDir . '/checkpoints.jsonl';
        $sink     = new FileCheckpointSink($filePath, false);

        ob_start();
        $sink->export($this->makeCheckpoint());
        $output = ob_get_clean();

        self::assertSame('', $output, 'echoStdout=false must produce no STDOUT output');
    }

    #[Test]
    public function testIOErrorIsLogged(): void
    {
        // Use a path under /proc that cannot be created.
        $filePath = '/proc/nonexistent_waaseyaa_test_dir/checkpoints.jsonl';

        /** @var array<int, array<string, mixed>> $loggedMessages */
        $loggedMessages = [];

        $logger = new class ($loggedMessages) implements LoggerInterface {
            use LoggerTrait;

            /** @param array<int, array<string, mixed>> $loggedMessages */
            public function __construct(private array &$loggedMessages) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->loggedMessages[] = [
                    'level'   => $level->value,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $sink = new FileCheckpointSink($filePath, false, $logger);

        // Must not throw.
        $sink->export($this->makeCheckpoint());

        self::assertNotEmpty($loggedMessages, 'an I/O failure must be logged');
        self::assertSame('warning', $loggedMessages[0]['level']);
        self::assertSame('audit.checkpoint_sink_failed', $loggedMessages[0]['message']);
    }
}
