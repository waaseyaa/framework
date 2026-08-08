<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Deployer\RuntimeState\SqliteArtifactInstaller;
use Waaseyaa\Deployer\RuntimeState\SqliteArtifactPreparer;

#[CoversClass(SqliteArtifactInstaller::class)]
final class SqliteArtifactInstallerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa-install-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function forced_post_activation_failure_restores_the_exact_serving_database(): void
    {
        $current = $this->database('current.sqlite', 'old');
        $artifact = $this->database('artifact.sqlite', 'new');
        $backup = $this->directory . '/backup.sqlite';
        $before = hash_file('sha256', $current);
        $installer = new SqliteArtifactInstaller(
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue()),
            afterActivation: static function (): never {
                throw new \RuntimeException('forced failure');
            },
        );

        try {
            $installer->install($current, $artifact, $backup, ['content']);
            self::fail('The forced failure was not raised.');
        } catch (\RuntimeException $error) {
            self::assertSame('forced failure', $error->getMessage());
        }

        self::assertSame($before, hash_file('sha256', $current));
        self::assertSame($before, hash_file('sha256', $backup));
        self::assertSame('old', $this->open($current)->query('SELECT value FROM content')->fetchColumn());
        self::assertSame([], glob($this->directory . '/.current.sqlite.*') ?: []);
    }

    #[Test]
    public function restore_uses_an_atomic_verified_copy_of_the_backup(): void
    {
        $current = $this->database('current.sqlite', 'changed');
        $backup = $this->database('backup.sqlite', 'original');
        $backupHash = hash_file('sha256', $backup);
        $installer = new SqliteArtifactInstaller(
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue()),
        );

        $installer->restore($backup, $current);

        self::assertSame($backupHash, hash_file('sha256', $current));
        self::assertSame($backupHash, hash_file('sha256', $backup));
        self::assertSame('original', $this->open($current)->query('SELECT value FROM content')->fetchColumn());
        self::assertSame([], glob($this->directory . '/.current.sqlite.*') ?: []);
    }

    private function database(string $name, string $value): string
    {
        $path = $this->directory . '/' . $name;
        $pdo = $this->open($path);
        $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO content VALUES (1, ?)');
        $insert->execute([$value]);

        return $path;
    }

    private function open(string $path): \PDO
    {
        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
