<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\DbPath;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Mission request-surface-hardening (#1650) WP02 — SC-004 end to end.
 *
 * With `WAASEYAA_DB=./storage/test.sqlite` and the process CWD parked in the
 * docroot (exactly how `php -S` runs the dev server), an HTTP-shaped boot and
 * a CLI-shaped boot must open the SAME file under the project root — and no
 * stray `*.sqlite` may materialize anywhere under `public/`. Before the fix,
 * SQLite joined the relative value onto the CWD and silently created a second
 * database inside the docroot.
 */
#[CoversNothing]
final class DbPathResolutionTest extends TestCase
{
    private string $projectRoot;

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_dbpath_' . uniqid();
        mkdir($this->projectRoot . '/public', 0o755, recursive: true);
        mkdir($this->projectRoot . '/storage', 0o755, recursive: true);
        $this->originalCwd = getcwd();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }
        putenv('WAASEYAA_DB');

        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function httpAndCliBootsOpenTheSameFileFromDocrootCwd(): void
    {
        putenv('WAASEYAA_DB=./storage/test.sqlite');
        $config = ['environment' => 'testing'];

        try {
            // Dev-server reality: the process CWD is the docroot.
            chdir($this->projectRoot . '/public');

            // HTTP-shaped boot: projectRoot as the front controller derives
            // it (dirname of public/) — write a row.
            $httpDatabase = new DatabaseBootstrapper()->boot($this->projectRoot, $config);
            $this->assertInstanceOf(DBALDatabase::class, $httpDatabase);
            $httpConnection = $httpDatabase->getConnection();
            $httpConnection->executeStatement('CREATE TABLE dbpath_probe (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)');
            $httpConnection->executeStatement("INSERT INTO dbpath_probe (id, marker) VALUES (1, 'written-by-http')");
            $httpConnection->close();

            // CLI-shaped boot: same projectRoot as bin/waaseyaa would compute
            // from a project-root CWD — read the row back.
            $cliDatabase = new DatabaseBootstrapper()->boot($this->projectRoot, $config);
            $this->assertInstanceOf(DBALDatabase::class, $cliDatabase);
            $cliConnection = $cliDatabase->getConnection();
            $marker = $cliConnection->fetchOne('SELECT marker FROM dbpath_probe WHERE id = 1');
            $cliConnection->close();

            $this->assertSame('written-by-http', $marker, 'CLI-shaped boot must read what the HTTP-shaped boot wrote');
            $this->assertFileExists($this->projectRoot . '/storage/test.sqlite');
            $this->assertSame([], $this->sqliteFilesUnder($this->projectRoot . '/public'), 'SC-004: no stray database may appear under the docroot');
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    #[Test]
    public function envValuePointedIntoDocrootBootsWithExactlyOneWarning(): void
    {
        putenv('WAASEYAA_DB=./public/oops.sqlite');
        $spy = $this->spyLogger();

        try {
            chdir($this->projectRoot);

            $database = new DatabaseBootstrapper()->boot($this->projectRoot, ['environment' => 'testing'], $spy);
            $this->assertInstanceOf(DBALDatabase::class, $database);
            $database->getConnection()->close();

            // FR-008 end to end: boot proceeds, exactly one warning emitted.
            $this->assertCount(1, $spy->warnings);
            $this->assertStringContainsString('oops.sqlite', $spy->warnings[0]);
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    /**
     * @return list<string>
     */
    private function sqliteFilesUnder(string $dir): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file->isDir() && str_ends_with($file->getFilename(), '.sqlite')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * @return LoggerInterface&object{warnings: list<string>}
     */
    private function spyLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $warnings = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
    }
}
