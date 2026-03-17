<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class MigrationRoundTripTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_migration_rt_' . uniqid();
        mkdir($this->tempDir . '/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function fullRoundTrip(): void
    {
        // 1. Write a migration file
        file_put_contents($this->tempDir . '/migrations/20260317_143000_create_articles.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('articles', function ($table) {
                    $table->id();
                    $table->string('title', 255);
                    $table->text('body')->nullable();
                    $table->timestamps();
                });
            }
            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('articles');
            }
        };
        PHP);

        // 2. Set up infrastructure
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);
        $loader = new MigrationLoader($this->tempDir, new PackageManifest());

        // 3. Load and run migrations
        $migrations = $loader->loadAll();
        $this->assertCount(1, $migrations['app']);

        $result = $migrator->run($migrations);
        $this->assertSame(1, $result->count);

        // 4. Verify table was created
        $schema = new SchemaBuilder($connection);
        $this->assertTrue($schema->hasTable('articles'));
        $this->assertTrue($schema->hasColumn('articles', 'title'));

        // 5. Verify status shows completed
        $status = $migrator->status($migrations);
        $this->assertSame([], $status['pending']);
        $this->assertCount(1, $status['completed']);

        // 6. Run again — nothing to migrate
        $result2 = $migrator->run($migrations);
        $this->assertSame(0, $result2->count);

        // 7. Rollback
        $rollbackResult = $migrator->rollback($migrations);
        $this->assertSame(1, $rollbackResult->count);

        // 8. Verify table was dropped
        $this->assertFalse($schema->hasTable('articles'));

        // 9. Verify status shows pending
        $status2 = $migrator->status($migrations);
        $this->assertCount(1, $status2['pending']);
        $this->assertSame([], $status2['completed']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
