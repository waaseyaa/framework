<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Engagement\EngagementAccess;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * @covers \Waaseyaa\Engagement\EngagementServiceProvider
 */
final class EngagementServiceProviderTest extends TestCase
{
    #[Test]
    public function registersReactionCommentAndFollowEntityTypes(): void
    {
        $provider = new EngagementServiceProvider();
        $provider->register();

        $types = $provider->getEntityTypes();
        self::assertCount(3, $types);
        $ids = array_map(static fn ($t) => $t->id(), $types);
        self::assertContains('reaction', $ids);
        self::assertContains('comment', $ids);
        self::assertContains('follow', $ids);
    }

    #[Test]
    public function configured_column_backend_preserves_domain_guard_and_api_cutover(): void
    {
        $provider = new EngagementServiceProvider();
        $provider->setKernelContext('/tmp', ['engagement' => ['storage_backend' => 'sql-column']], []);
        $provider->register();
        foreach ($provider->getEntityTypes() as $type) {
            if (in_array($type->id(), ['comment', 'reaction'], true)) {
                self::assertSame('sql-column', $type->getPrimaryStorageBackend());
                self::assertSame(\Waaseyaa\Engagement\EngagementMutationInvariant::class, $type->getRequiredMutationInvariant());
                self::assertFalse($type->isApiExposed());
                self::assertNotEmpty($type->getFieldDefinitions());
            }
        }
    }

    #[Test]
    public function unknown_backend_refuses_before_registration(): void
    {
        $provider = new EngagementServiceProvider();
        $provider->setKernelContext('/tmp', ['engagement' => ['storage_backend' => 'unknown']], []);
        $this->expectException(\InvalidArgumentException::class);
        $provider->register();
    }

    #[Test]
    public function resolving_engagement_access_refuses_when_required_domain_policy_is_missing(): void
    {
        $provider = new EngagementServiceProvider();
        $database = DBALDatabase::createSqlite();
        $entities = $this->createStub(EntityTypeManagerInterface::class);
        $access = new EntityAccessHandler([]);
        $provider->setKernelServices(new class($database, $entities, $access) implements KernelServicesInterface {
            public function __construct(
                private DBALDatabase $database,
                private EntityTypeManagerInterface $entities,
                private EntityAccessHandler $access,
            ) {}
            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    EntityTypeManagerInterface::class => $this->entities,
                    EntityAccessHandler::class => $this->access,
                    default => null,
                };
            }
        });
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(EngagementPolicyInterface::class);
        $provider->resolve(EngagementAccess::class);
    }
}
