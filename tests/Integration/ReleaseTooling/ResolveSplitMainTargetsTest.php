<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ReleaseTooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ResolveSplitMainTargetsTest extends TestCase
{
    private string $script = '';

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 3) . '/bin/resolve-split-main-targets';
    }

    #[Test]
    public function resolves_allowlisted_targets_as_a_deterministic_matrix(): void
    {
        [$exit, $stdout] = $this->runScript('foundation, access,foundation');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/foundation', 'remote' => 'foundation'],
                ['local' => 'packages/access', 'remote' => 'access'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function rejects_an_unknown_or_path_shaped_target(): void
    {
        [$unknownExit, , $unknownError] = $this->runScript('foundation,not-a-package');
        [$pathExit, , $pathError] = $this->runScript('../framework');

        self::assertNotSame(0, $unknownExit);
        self::assertStringContainsString('not allowlisted', $unknownError);
        self::assertNotSame(0, $pathExit);
        self::assertStringContainsString('not allowlisted', $pathError);
    }

    #[Test]
    public function resolves_the_complete_page_builder_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('page-builder,publishing,admin-surface');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/page-builder', 'remote' => 'page-builder'],
                ['local' => 'packages/publishing', 'remote' => 'publishing'],
                ['local' => 'packages/admin-surface', 'remote' => 'admin-surface'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_foundation_database_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('foundation,database-legacy');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/foundation', 'remote' => 'foundation'],
                ['local' => 'packages/database-legacy', 'remote' => 'database-legacy'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_configuration_authority_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('config,entity-storage');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/config', 'remote' => 'config'],
                ['local' => 'packages/entity-storage', 'remote' => 'entity-storage'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_schema_coordinator_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('site-contract,cli,foundation,database-legacy');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/site-contract', 'remote' => 'site-contract'],
                ['local' => 'packages/cli', 'remote' => 'cli'],
                ['local' => 'packages/foundation', 'remote' => 'foundation'],
                ['local' => 'packages/database-legacy', 'remote' => 'database-legacy'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_entity_materialization_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('entity,migration');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/entity', 'remote' => 'entity'],
                ['local' => 'packages/migration', 'remote' => 'migration'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_shared_workflow_history_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('api,audit,admin-surface');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/api', 'remote' => 'api'],
                ['local' => 'packages/audit', 'remote' => 'audit'],
                ['local' => 'packages/admin-surface', 'remote' => 'admin-surface'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolves_the_schema_materialization_workflow_cohort(): void
    {
        [$exit, $stdout] = $this->runScript('entity,migration,workflows');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/entity', 'remote' => 'entity'],
                ['local' => 'packages/migration', 'remote' => 'migration'],
                ['local' => 'packages/workflows', 'remote' => 'workflows'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function rejects_an_empty_selection(): void
    {
        [$exit, , $stderr] = $this->runScript(' , ');

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('at least one', $stderr);
    }

    /** @return array{int, string, string} */
    private function runScript(string $selection): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->script, $selection],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}
