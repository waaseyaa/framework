<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ReleaseTooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
    public function resolves_the_bundle_unique_key_delivery_set(): void
    {
        [$exit, $stdout] = $this->runScript('api,entity-storage,entity,field,foundation,migration');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/api', 'remote' => 'api'],
                ['local' => 'packages/entity-storage', 'remote' => 'entity-storage'],
                ['local' => 'packages/entity', 'remote' => 'entity'],
                ['local' => 'packages/field', 'remote' => 'field'],
                ['local' => 'packages/foundation', 'remote' => 'foundation'],
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
    public function resolves_the_protected_inline_view_delivery_set(): void
    {
        // #2564's inline view spans two packages: foundation registers
        // /media/{id}/view, media matches and handles the controller string.
        // Splitting either alone leaves the route half-wired downstream.
        [$exit, $stdout] = $this->runScript('foundation,media');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/foundation', 'remote' => 'foundation'],
                ['local' => 'packages/media', 'remote' => 'media'],
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

    #[Test]
    public function resolves_the_ai_development_metapackage_once(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript('ai-development, ai-development');

        self::assertSame(0, $exit, $stderr);
        self::assertSame('', $stderr);
        self::assertSame([
            'include' => [
                ['local' => 'packages/ai-development', 'remote' => 'ai-development'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function ai_development_does_not_authorize_paths_or_partial_selections(): void
    {
        foreach (['packages/ai-development', '../ai-development', 'ai-development,not-a-package'] as $selection) {
            [$exit, $stdout, $stderr] = $this->runScript($selection);

            self::assertSame(2, $exit);
            self::assertSame('', $stdout, 'Refused selections must not publish a partial matrix.');
            self::assertStringContainsString('not allowlisted', $stderr);
        }
    }

    /** @return array{int, string, string} */
    private function runScript(string $selection): array
    {
        // proc_open drained stdout to EOF before touching stderr, so a script
        // that filled the ~64KB stderr buffer wedged both sides (#2491).
        // proc_open got no cwd and no env argument, so the child inherited both
        // — null for each preserves that. stdin was opened and immediately
        // closed without a write, so $input null is equivalent. timeout null
        // preserves the previous absence of any time bound.
        $process = new Process(
            [PHP_BINARY, $this->script, $selection],
            null,
            null,
            null,
            null,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput(), $process->getErrorOutput()];
    }
}
