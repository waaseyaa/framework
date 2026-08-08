<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;

#[CoversClass(ConfigCacheDbAuditServiceProvider::class)]
final class ConfigCacheDbAuditServiceProviderTest extends TestCase
{
    #[Test]
    public function db_init_factory_preserves_the_public_command_contract(): void
    {
        $command = ConfigCacheDbAuditServiceProvider::dbInitCommand('/project');

        self::assertSame('db:init', $command->getName());
        self::assertSame(
            'Initialize the database on first deploy: apply pending migrations and materialize every registered entity type\'s schema.',
            $command->getDescription(),
        );

        $options = $command->handlerOptions();
        self::assertSame(['dry-run', 'sync-schema', 'no-sync-schema'], array_column($options, 'name'));
        self::assertSame(
            [HandlerOptionMode::None, HandlerOptionMode::None, HandlerOptionMode::None],
            array_column($options, 'mode'),
        );
    }
}
