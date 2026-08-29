<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\Install\InstalledManifest;

#[CoversClass(InstalledManifest::class)]
final class InstalledManifestTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_bimaaji_manifest_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tempDir);
    }

    #[Test]
    public function anAbsentManifestLoadsEmpty(): void
    {
        $manifest = InstalledManifest::load($this->tempDir);

        self::assertSame([], $manifest->clientIds());
        self::assertSame([], $manifest->targetsFor('claude'));
    }

    #[Test]
    public function roundTripsThroughJson(): void
    {
        $written = InstalledManifest::empty()
            ->withClient('claude', ['.claude/skills/waaseyaa-b/SKILL.md' => 'bbb', '.claude/skills/waaseyaa-a/SKILL.md' => 'aaa'])
            ->withClient('cursor', ['.cursorrules' => 'ccc']);

        $this->write($written->toJson());
        $loaded = InstalledManifest::load($this->tempDir);

        self::assertSame(['claude', 'cursor'], $loaded->clientIds());
        self::assertSame(['.cursorrules' => 'ccc'], $loaded->targetsFor('cursor'));
        self::assertSame(
            ['.claude/skills/waaseyaa-a/SKILL.md' => 'aaa', '.claude/skills/waaseyaa-b/SKILL.md' => 'bbb'],
            $loaded->targetsFor('claude'),
            'Targets are sorted so the serialized file is stable.',
        );
        self::assertSame($written->toJson(), $loaded->toJson());
    }

    #[Test]
    public function replacingOneClientKeepsTheOthers(): void
    {
        $manifest = InstalledManifest::empty()
            ->withClient('claude', ['.claude/CLAUDE-WAASEYAA.md' => 'aaa'])
            ->withClient('cursor', ['.cursorrules' => 'ccc'])
            ->withClient('cursor', ['.cursorrules' => 'ddd']);

        self::assertSame(['.claude/CLAUDE-WAASEYAA.md' => 'aaa'], $manifest->targetsFor('claude'));
        self::assertSame(['.cursorrules' => 'ddd'], $manifest->targetsFor('cursor'));
    }

    #[Test]
    public function anEmptyTargetSetDropsTheClientEntirely(): void
    {
        $manifest = InstalledManifest::empty()
            ->withClient('cursor', ['.cursorrules' => 'ccc'])
            ->withClient('cursor', []);

        self::assertSame([], $manifest->clientIds());
    }

    #[Test]
    public function malformedJsonLoadsEmptyRatherThanGuessingAtOwnership(): void
    {
        // Failing closed here means "prune nothing", which is strictly safer
        // than acting on a record we could not parse.
        $this->write("{ not json at all");

        self::assertSame([], InstalledManifest::load($this->tempDir)->clientIds());
    }

    #[Test]
    public function aForeignSchemaVersionLoadsEmpty(): void
    {
        $this->write(json_encode([
            'schema_version' => InstalledManifest::SCHEMA_VERSION + 1,
            'clients' => ['cursor' => ['targets' => [['path' => '.cursorrules', 'sha1' => 'ccc']]]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], InstalledManifest::load($this->tempDir)->clientIds());
    }

    #[Test]
    public function malformedRowsAreDroppedWithoutDiscardingTheGoodOnes(): void
    {
        $this->write(json_encode([
            'schema_version' => InstalledManifest::SCHEMA_VERSION,
            'clients' => [
                'cursor' => ['targets' => [
                    ['path' => '.cursorrules', 'sha1' => 'ccc'],
                    ['path' => '', 'sha1' => 'ddd'],
                    ['sha1' => 'no path'],
                    ['path' => '.other', 'sha1' => ''],
                    'not-an-array',
                ]],
                'broken' => ['targets' => 'not-an-array'],
            ],
        ], JSON_THROW_ON_ERROR));

        $manifest = InstalledManifest::load($this->tempDir);

        self::assertSame(['cursor'], $manifest->clientIds());
        self::assertSame(['.cursorrules' => 'ccc'], $manifest->targetsFor('cursor'));
    }

    private function write(string $contents): void
    {
        $path = $this->tempDir . '/' . InstalledManifest::RELATIVE_PATH;
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $contents);
    }
}
