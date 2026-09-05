<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tooling\SurfaceDeclarations;

require_once __DIR__ . '/../../tools/lib/SurfaceDeclarations.php';

/**
 * Migration fidelity is a property of the frozen migration input and the real
 * migrator. It must not compare that historical input with the live declaration
 * plane, whose ordinary authorization rules permit later additions and
 * internal-to-public promotions.
 */
#[CoversNothing]
final class SurfaceMigrationFidelityTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/fixtures/surface/pre-migration-public-surface-map.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_surface_migration_' . bin2hex(random_bytes(8));
        new Filesystem()->mkdir([
            $this->root . '/bin',
            $this->root . '/docs',
            $this->root . '/packages/snapshot/src',
        ]);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function real_migrator_round_trip_preserves_the_frozen_719_entry_map_exactly(): void
    {
        /** @var array<string, string> $snapshot */
        $snapshot = require self::SNAPSHOT;
        self::assertCount(719, $snapshot, 'The immutable pre-migration snapshot must remain complete.');

        $namespaces = [];
        foreach (array_keys($snapshot) as $fqcn) {
            $parts = explode('\\', $fqcn, 2);
            $namespaces[$parts[0] . '\\'] = true;
        }
        self::assertSame(
            ['Waaseyaa\\' => true],
            $namespaces,
            'The minimal fixture ownership prefix is derived entirely from the frozen snapshot.',
        );

        $filesystem = new Filesystem();
        $filesystem->copy(dirname(__DIR__, 2) . '/bin/migrate-surface-map', $this->root . '/bin/migrate-surface-map');
        $filesystem->copy(self::SNAPSHOT, $this->root . '/docs/public-surface-map.php');
        $filesystem->dumpFile(
            $this->root . '/docs/public-surface-map.md',
            "# Frozen migration disposition fixture\n",
        );
        $filesystem->dumpFile(
            $this->root . '/packages/snapshot/composer.json',
            json_encode([
                'name' => 'waaseyaa/frozen-surface-snapshot',
                'type' => 'library',
                'autoload' => ['psr-4' => ['Waaseyaa\\' => 'src/']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $first = $this->runMigrator();
        self::assertSame(0, $first['exit'], $first['stderr'] . $first['stdout']);
        self::assertStringContainsString('1 package file(s), 719 entrie(s) total', $first['stdout']);

        $declarationPath = $this->root . '/packages/snapshot/public-surface.php';
        self::assertFileExists($declarationPath);
        $firstDeclaration = (string) file_get_contents($declarationPath);
        $firstComposition = SurfaceDeclarations::load($this->root)->compose();

        self::assertCount(719, $firstComposition);
        self::assertSame($snapshot, $firstComposition, 'Migration must preserve every disposition with no missing or extra FQCN.');
        self::assertSame(
            'public',
            $firstComposition['Waaseyaa\\EntityStorage\\Backend\\FieldStorageBackendV2Interface'] ?? null,
            'FQCNs containing digits must survive parsing and generation unchanged.',
        );

        $second = $this->runMigrator();
        self::assertSame(0, $second['exit'], $second['stderr'] . $second['stdout']);
        self::assertSame($firstDeclaration, (string) file_get_contents($declarationPath));
        self::assertSame($firstComposition, SurfaceDeclarations::load($this->root)->compose());
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runMigrator(): array
    {
        $process = new Process([PHP_BINARY, $this->root . '/bin/migrate-surface-map'], $this->root);
        $process->setTimeout(30);
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 255,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
