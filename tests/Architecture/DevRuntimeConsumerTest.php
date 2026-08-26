<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waaseyaa\Tooling\DevRuntimeConsumer;

#[CoversNothing]
final class DevRuntimeConsumerTest extends TestCase
{
    private string $root;
    private string $consumer;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/tools/lib/DevRuntimeConsumer.php';
        $this->consumer = sys_get_temp_dir() . '/waaseyaa-dev-runtime-consumer-' . bin2hex(random_bytes(8));
        mkdir($this->consumer . '/bin', 0o700, true);
        mkdir($this->consumer . '/tools/lib', 0o700, true);
        file_put_contents($this->consumer . '/bin/dev-runtime', 'launcher');
        file_put_contents($this->consumer . '/tools/lib/DevRuntimeConsumer.php', 'library');
    }

    protected function tearDown(): void
    {
        @unlink($this->consumer . '/tools/dev-runtime-source.json');
        @unlink($this->consumer . '/tools/lib/DevRuntimeConsumer.php');
        @unlink($this->consumer . '/bin/dev-runtime');
        @rmdir($this->consumer . '/tools/lib');
        @rmdir($this->consumer . '/tools');
        @rmdir($this->consumer . '/bin');
        @rmdir($this->consumer);
    }

    #[Test]
    public function source_record_pins_canonical_files_without_managed_tool_literals(): void
    {
        $record = $this->record();
        file_put_contents(
            $this->consumer . '/tools/dev-runtime-source.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $loaded = DevRuntimeConsumer::loadSourceRecord(
            $this->consumer . '/tools/dev-runtime-source.json',
            $this->consumer,
        );

        self::assertSame('waaseyaa/framework', $loaded['repository']);
        self::assertSame(array_keys($record['authority_files']), array_keys($loaded['authority_files']));
        self::assertStringNotContainsString('node', json_encode($record['consumer_files'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('composer', json_encode($record['consumer_files'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('frankenphp', json_encode($record['consumer_files'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function malformed_or_locally_altered_source_authority_fails_closed(): void
    {
        $record = $this->record();
        $record['commit'] = 'main';
        file_put_contents(
            $this->consumer . '/tools/dev-runtime-source.json',
            json_encode($record, JSON_THROW_ON_ERROR),
        );
        try {
            DevRuntimeConsumer::loadSourceRecord($this->consumer . '/tools/dev-runtime-source.json', $this->consumer);
            self::fail('A moving Framework reference was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('40-character lowercase commit', $exception->getMessage());
        }

        $record = $this->record();
        $record['consumer_files']['bin/dev-runtime'] = hash('sha256', 'other launcher');
        file_put_contents(
            $this->consumer . '/tools/dev-runtime-source.json',
            json_encode($record, JSON_THROW_ON_ERROR),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('consumer file checksum mismatch: bin/dev-runtime');
        DevRuntimeConsumer::loadSourceRecord($this->consumer . '/tools/dev-runtime-source.json', $this->consumer);
    }

    #[Test]
    public function source_cache_is_content_addressed_and_corruption_is_detected(): void
    {
        $record = $this->record();
        $first = DevRuntimeConsumer::cachePath('/cache', $record);
        self::assertStringStartsWith('/cache/waaseyaa/dev-runtime-source/', $first);
        self::assertSame($first, DevRuntimeConsumer::cachePath('/cache', $record));
        $record['commit'] = str_repeat('b', 40);
        self::assertNotSame($first, DevRuntimeConsumer::cachePath('/cache', $record));

        $cache = $this->consumer . '/cache';
        mkdir($cache . '/bin', 0o700, true);
        file_put_contents($cache . '/bin/dev-runtime', 'tampered');
        self::assertContains(
            'canonical source checksum mismatch: bin/dev-runtime',
            DevRuntimeConsumer::sourceErrors($cache, $this->record()['authority_files']),
        );
        unlink($cache . '/bin/dev-runtime');
        rmdir($cache . '/bin');
        rmdir($cache);
    }

    #[Test]
    public function delegation_keeps_options_before_the_child_separator_and_binds_the_consumer_root(): void
    {
        self::assertSame(
            [PHP_BINARY, '/cache/bin/dev-runtime', 'doctor', '--repository-root=' . $this->consumer, '--json'],
            DevRuntimeConsumer::delegationCommand('/cache', $this->consumer, ['doctor', '--json']),
        );
        self::assertSame(
            [PHP_BINARY, '/cache/bin/dev-runtime', 'exec', '--repository-root=' . $this->consumer, '--', 'composer', 'test'],
            DevRuntimeConsumer::delegationCommand('/cache', $this->consumer, ['exec', '--', 'composer', 'test']),
        );
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'schema_version' => 1,
            'change_record' => 'FW-DEV-RUNTIME-01',
            'repository' => 'waaseyaa/framework',
            'commit' => str_repeat('a', 40),
            'consumer_files' => [
                'bin/dev-runtime' => hash('sha256', 'launcher'),
                'tools/lib/DevRuntimeConsumer.php' => hash('sha256', 'library'),
            ],
            'authority_files' => [
                'bin/dev-runtime' => hash('sha256', 'runtime'),
                'bin/git' => hash('sha256', 'git'),
                'tools/lib/DevRuntime.php' => hash('sha256', 'library authority'),
                'tools/dev-runtime-manifest.json' => hash('sha256', 'manifest'),
                'tools/frankenphp-runtime-pin.json' => hash('sha256', 'pin'),
            ],
        ];
    }
}
