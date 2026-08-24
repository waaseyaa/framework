<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Migration\Discovery\FilesystemManifestLoader;
use Waaseyaa\Migration\Log\Channels;
use Waaseyaa\Migration\MigrationDefinition;

#[CoversClass(FilesystemManifestLoader::class)]
final class FilesystemManifestLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            (new Filesystem())->remove($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    #[Test]
    public function relative_path_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an absolute filesystem path');

        new FilesystemManifestLoader(['relative/path']);
    }

    #[Test]
    public function empty_path_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a non-empty string');

        new FilesystemManifestLoader(['']);
    }

    #[Test]
    public function non_existent_path_raises_when_load_is_iterated(): void
    {
        $missing = \sys_get_temp_dir() . '/waaseyaa_missing_' . \uniqid('', true);
        $loader = new FilesystemManifestLoader([$missing]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        // load() is a generator — exception surfaces on iteration.
        \iterator_to_array($loader->load());
    }

    #[Test]
    public function path_that_is_a_file_is_rejected(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/single.php';
        \file_put_contents($file, '<?php');
        $loader = new FilesystemManifestLoader([$file]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');

        \iterator_to_array($loader->load());
    }

    #[Test]
    public function php_file_not_returning_migration_definition_raises_runtime(): void
    {
        $dir = $this->makeTempDir();
        \file_put_contents($dir . '/bad.php', "<?php return 42;\n");
        $loader = new FilesystemManifestLoader([$dir]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(MigrationDefinition::class);

        \iterator_to_array($loader->load());
    }

    #[Test]
    public function load_yields_definitions_in_lexicographic_path_order(): void
    {
        $dir = $this->makeTempDir();
        // Write files in reverse-alphabetical order; the loader must sort.
        \file_put_contents($dir . '/zulu.php', $this->definitionPhp('zulu'));
        \file_put_contents($dir . '/alpha.php', $this->definitionPhp('alpha'));
        \file_put_contents($dir . '/mike.php', $this->definitionPhp('mike'));

        $loader = new FilesystemManifestLoader([$dir]);
        $ids = \array_map(
            static fn (MigrationDefinition $d) => $d->id,
            \iterator_to_array($loader->load(), false),
        );

        self::assertSame(['alpha', 'mike', 'zulu'], $ids);
    }

    #[Test]
    public function load_walks_nested_directories(): void
    {
        $dir = $this->makeTempDir();
        \mkdir($dir . '/sub', 0o700, true);
        \file_put_contents($dir . '/top.php', $this->definitionPhp('top'));
        \file_put_contents($dir . '/sub/nested.php', $this->definitionPhp('nested'));

        $loader = new FilesystemManifestLoader([$dir]);
        $ids = \array_map(
            static fn (MigrationDefinition $d) => $d->id,
            \iterator_to_array($loader->load(), false),
        );

        // Both files load; lexicographic order on full path.
        self::assertCount(2, $ids);
        self::assertContains('top', $ids);
        self::assertContains('nested', $ids);
    }

    #[Test]
    public function empty_directory_logs_info_notice_and_yields_nothing(): void
    {
        $dir = $this->makeTempDir();
        $logger = new RecordingLogger();
        $loader = new FilesystemManifestLoader([$dir], $logger);

        self::assertSame([], \iterator_to_array($loader->load(), false));
        self::assertNotEmpty($logger->records);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
        self::assertSame(Channels::MIGRATION_DISCOVERY, $logger->records[0]['context']['channel']);
        self::assertSame($dir, $logger->records[0]['context']['manifest_path']);
    }

    #[Test]
    public function non_php_files_are_ignored(): void
    {
        $dir = $this->makeTempDir();
        \file_put_contents($dir . '/notes.md', 'ignore me');
        \file_put_contents($dir . '/wp.php', $this->definitionPhp('wp'));

        $loader = new FilesystemManifestLoader([$dir]);
        $ids = \array_map(
            static fn (MigrationDefinition $d) => $d->id,
            \iterator_to_array($loader->load(), false),
        );

        self::assertSame(['wp'], $ids);
    }

    #[Test]
    public function multiple_paths_are_walked_in_order(): void
    {
        $first = $this->makeTempDir();
        $second = $this->makeTempDir();
        \file_put_contents($first . '/a.php', $this->definitionPhp('first_a'));
        \file_put_contents($second . '/a.php', $this->definitionPhp('second_a'));

        $loader = new FilesystemManifestLoader([$first, $second]);
        $ids = \array_map(
            static fn (MigrationDefinition $d) => $d->id,
            \iterator_to_array($loader->load(), false),
        );

        self::assertSame(['first_a', 'second_a'], $ids);
    }

    private function definitionPhp(string $id): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Waaseyaa\\Migration\\MigrationDefinition;
        use Waaseyaa\\Migration\\Plugin\\DestinationPluginInterface;
        use Waaseyaa\\Migration\\Plugin\\DestinationRecord;
        use Waaseyaa\\Migration\\Plugin\\SourcePluginInterface;
        use Waaseyaa\\Migration\\Plugin\\SourceRecord;
        use Waaseyaa\\Migration\\Plugin\\WriteResult;
        use Waaseyaa\\Migration\\SourceId;

        \$source = new class() implements SourcePluginInterface {
            public function id(): string { return 'wp_post'; }
            public function stability(): string { return 'stable'; }
            public function records(): iterable { return []; }
            public function count(): ?int { return 0; }
            public function sourceIdFor(SourceRecord \$record): SourceId
            {
                return new SourceId(sourceType: 'wp_post', keys: ['id' => 1]);
            }
        };
        \$destination = new class() implements DestinationPluginInterface {
            public function id(): string { return 'node_destination'; }
            public function stability(): string { return 'stable'; }
            public function write(DestinationRecord \$record): WriteResult
            {
                return new WriteResult(
                    destinationEntityType: 'node',
                    destinationUuid: '00000000-0000-7000-8000-000000000000',
                    sourceRecordHash: 'h',
                    runId: '00000000-0000-7000-8000-000000000001',
                    writtenAt: '2026-05-12T00:00:00Z',
                );
            }
            public function rollback(WriteResult \$writeResult): void {}
            public function lookup(SourceId \$sourceId): ?WriteResult { return null; }
        };

        return new MigrationDefinition(
            id: '{$id}',
            source: \$source,
            process: ['title' => 'post_title'],
            destination: \$destination,
        );
        PHP;
    }

    private function makeTempDir(): string
    {
        $base = \sys_get_temp_dir() . '/waaseyaa_migration_loader_' . \uniqid('', true);
        if (!\mkdir($base, 0o700, true) && !\is_dir($base)) {
            throw new \RuntimeException('Could not create temp dir for test.');
        }
        $this->tempDirs[] = $base;
        return $base;
    }

}

/**
 * Minimal logger that records every call for assertion in tests.
 *
 * @internal
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: LogLevel, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
