<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\Community\HasCommunityInterface;
use Waaseyaa\Entity\Community\HasCommunityTrait;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Mission #1257 §C1: HasCommunityInterface marker is deprecated in favour of
 * a declarative `tenancy: ['scope' => 'community']` slot on EntityType.
 *
 * The deprecation cycle requires a one-time warning per entity-type id at
 * registration when the entity class still carries the marker.
 */
#[CoversClass(EntityTypeManager::class)]
final class EntityTypeManagerTenancyDeprecationTest extends TestCase
{
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
    }

    public function testWarnsOnceWhenMarkerPresentAndTenancySlotNull(): void
    {
        $logger = new TenancyDeprecationSpyLogger();
        $manager = new EntityTypeManager($this->eventDispatcher, logger: $logger);

        $type = new EntityType(
            id: 'community_post',
            label: 'Community Post',
            class: TenancyDeprecationCommunityEntity::class,
        );

        $manager->registerEntityType($type);

        $matches = $logger->warningsContaining('HasCommunityInterface');
        $this->assertCount(1, $matches);
        $this->assertStringContainsString('community_post', $matches[0]['message']);
        $this->assertStringContainsString("tenancy: ['scope' => 'community']", $matches[0]['message']);
        $this->assertSame('community_post', $matches[0]['context']['entity_type'] ?? null);
    }

    public function testDoesNotWarnWhenTenancySlotDeclared(): void
    {
        $logger = new TenancyDeprecationSpyLogger();
        $manager = new EntityTypeManager($this->eventDispatcher, logger: $logger);

        $type = new EntityType(
            id: 'community_post',
            label: 'Community Post',
            class: TenancyDeprecationCommunityEntity::class,
            tenancy: ['scope' => 'community'],
        );

        $manager->registerEntityType($type);

        $this->assertSame([], $logger->warningsContaining('HasCommunityInterface'));
    }

    public function testDoesNotWarnWhenClassDoesNotImplementMarker(): void
    {
        $logger = new TenancyDeprecationSpyLogger();
        $manager = new EntityTypeManager($this->eventDispatcher, logger: $logger);

        $type = new EntityType(
            id: 'plain',
            label: 'Plain',
            class: TenancyDeprecationPlainEntity::class,
        );

        $manager->registerEntityType($type);

        $this->assertSame([], $logger->warningsContaining('HasCommunityInterface'));
    }

    public function testWarningIsMemoizedPerEntityTypeId(): void
    {
        // Re-registering the same type id throws a collision, so this test
        // exercises the cross-instance memoization by registering two
        // distinct ids that share the same marker class.
        $logger = new TenancyDeprecationSpyLogger();
        $manager = new EntityTypeManager($this->eventDispatcher, logger: $logger);

        $manager->registerEntityType(new EntityType(
            id: 'thread',
            label: 'Thread',
            class: TenancyDeprecationCommunityEntity::class,
        ));
        $manager->registerEntityType(new EntityType(
            id: 'reply',
            label: 'Reply',
            class: TenancyDeprecationCommunityEntity::class,
        ));

        // One warning per entity-type id, not per registration call count.
        $this->assertCount(2, $logger->warningsContaining('HasCommunityInterface'));
    }

    public function testNoLoggerProvidedIsNotAFailure(): void
    {
        $manager = new EntityTypeManager($this->eventDispatcher);

        $manager->registerEntityType(new EntityType(
            id: 'community_post',
            label: 'Community Post',
            class: TenancyDeprecationCommunityEntity::class,
        ));

        $this->assertTrue($manager->hasDefinition('community_post'));
    }
}

/**
 * Test fixture: a content entity that still carries the legacy marker.
 */
final class TenancyDeprecationCommunityEntity extends ContentEntityBase implements HasCommunityInterface
{
    use HasCommunityTrait;
}

/**
 * Test fixture: a content entity with no tenancy marker.
 */
final class TenancyDeprecationPlainEntity extends ContentEntityBase
{
}

/**
 * Test fixture: an in-memory logger that captures all calls per level.
 *
 * Mirrors the SpyLogger pattern used by SqlEntityStorageBundleLoadDriftTest;
 * inlined here to keep the entity package free of test cross-package pulls.
 */
final class TenancyDeprecationSpyLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function warningsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => $r['level'] === 'warning' && str_contains($r['message'], $needle),
        ));
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->record('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->record('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->record('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->record('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->record('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->record('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->record('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->record('debug', $message, $context);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->record($level->value, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $level, string|\Stringable $message, array $context): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
