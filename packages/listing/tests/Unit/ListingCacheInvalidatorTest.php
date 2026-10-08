<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Listing\ListingCacheInvalidator;

/**
 * Unit tests for {@see ListingCacheInvalidator}.
 *
 * Covers WP07 / FR-038..FR-041:
 *
 * - FR-038: invalidator handles AfterSaveEvent and AfterDeleteEvent.
 * - FR-039: tag set composition (`entity:<type>`, `entity:<type>:<id>`,
 *   `entity:<type>:<id>:<langcode>` for translatable entities).
 * - FR-040: best-effort — cache errors are caught + logged + execution continues.
 * - FR-041: invalidation is synchronous (no queue indirection in v0.x).
 */
#[CoversClass(ListingCacheInvalidator::class)]
final class ListingCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function canonicalDeleteNotificationEvictsEntityTags(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addListener(\Waaseyaa\Entity\Event\EntityEvents::POST_DELETE->value, $invalidator->onPostDelete(...));

        $dispatcher->dispatch(new \Waaseyaa\Entity\Event\EntityEvent($this->makeEntity(entityTypeId: 'node', id: 42)), \Waaseyaa\Entity\Event\EntityEvents::POST_DELETE->value);

        self::assertSame(['entity:node', 'entity:node:42'], $cache->invalidatedTags);
    }

    #[Test]
    public function emitsBaseTagsOnSaveForNonTranslatableEntity(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $event = new AfterSaveEvent(
            $this->makeEntity(entityTypeId: 'node', id: 42),
            SaveContext::default(),
            false,
        );

        $invalidator->onAfterSave($event);

        self::assertSame(
            ['entity:node', 'entity:node:42'],
            $cache->invalidatedTags,
            'Non-translatable save must emit base + id-scoped tag only.',
        );
    }

    #[Test]
    public function emitsLangcodeTagPerAffectedLangcodeOnSave(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $event = new AfterSaveEvent(
            $this->makeTranslatableEntity(entityTypeId: 'node', id: 7, activeLangcode: 'en'),
            SaveContext::default(),
            false,
            affectedLangcodes: ['en', 'mi-tle'],
        );

        $invalidator->onAfterSave($event);

        self::assertSame(
            [
                'entity:node',
                'entity:node:7',
                'entity:node:7:en',
                'entity:node:7:mi-tle',
            ],
            $cache->invalidatedTags,
            'Translatable save with two affected langcodes must emit one langcode-scoped tag per langcode.',
        );
    }

    #[Test]
    public function fallsBackToActiveLangcodeWhenAffectedLangcodesIsNull(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $event = new AfterSaveEvent(
            $this->makeTranslatableEntity(entityTypeId: 'taxonomy_term', id: 99, activeLangcode: 'fr'),
            SaveContext::default(),
            false,
            // affectedLangcodes deliberately left null — pre-WP07 dispatcher
            // path or hand-emitted event.
        );

        $invalidator->onAfterSave($event);

        self::assertSame(
            [
                'entity:taxonomy_term',
                'entity:taxonomy_term:99',
                'entity:taxonomy_term:99:fr',
            ],
            $cache->invalidatedTags,
        );
    }

    #[Test]
    public function emitsLangcodeTagsOnDeleteSameAsSave(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $event = new AfterDeleteEvent(
            $this->makeTranslatableEntity(entityTypeId: 'node', id: 12, activeLangcode: 'en'),
            affectedLangcodes: ['en', 'fr'],
        );

        $invalidator->onAfterDelete($event);

        self::assertSame(
            [
                'entity:node',
                'entity:node:12',
                'entity:node:12:en',
                'entity:node:12:fr',
            ],
            $cache->invalidatedTags,
        );
    }

    #[Test]
    public function deleteFallsBackToActiveLangcodeWhenAffectedLangcodesIsNull(): void
    {
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $event = new AfterDeleteEvent(
            $this->makeTranslatableEntity(entityTypeId: 'node', id: 5, activeLangcode: 'mi-tle'),
        );

        $invalidator->onAfterDelete($event);

        self::assertSame(
            [
                'entity:node',
                'entity:node:5',
                'entity:node:5:mi-tle',
            ],
            $cache->invalidatedTags,
        );
    }

    #[Test]
    public function continuesAfterCacheBackendErrorAndLogsWarning(): void
    {
        $cache = new ExplodingTaggedCache();
        $logger = new RecordingLogger();
        $invalidator = new ListingCacheInvalidator($cache, $logger);

        $event = new AfterSaveEvent(
            $this->makeEntity(entityTypeId: 'node', id: 1),
            SaveContext::default(),
            false,
        );

        // Must not throw.
        $invalidator->onAfterSave($event);

        self::assertCount(
            2,
            $logger->records,
            'Each failed tag must produce one warning record (base + id tag = 2).',
        );
        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::WARNING->value, $record['level']);
            self::assertSame('listing-cache invalidation failed', $record['message']);
            self::assertArrayHasKey('tag', $record['context']);
            self::assertArrayHasKey('exception', $record['context']);
            self::assertArrayHasKey('message', $record['context']);
        }
    }

    #[Test]
    public function noOpWhenCacheIsNull(): void
    {
        $invalidator = new ListingCacheInvalidator(cache: null);

        $event = new AfterSaveEvent(
            $this->makeEntity(entityTypeId: 'node', id: 1),
            SaveContext::default(),
            false,
        );

        // Must not throw, no cache to inspect.
        $invalidator->onAfterSave($event);

        $deleteEvent = new AfterDeleteEvent($this->makeEntity(entityTypeId: 'node', id: 1));
        $invalidator->onAfterDelete($deleteEvent);

        self::assertTrue(true, 'Null cache reduces to a safe no-op.');
    }

    #[Test]
    public function skipsInvalidationWhenEntityHasNoId(): void
    {
        // Defensive guard: an entity without an id (e.g. transient stub) should
        // not produce malformed tag strings — short-circuit instead.
        $cache = new RecordingTaggedCache();
        $invalidator = new ListingCacheInvalidator($cache);

        $entity = $this->makeEntity(entityTypeId: 'node', id: null);

        $event = new AfterSaveEvent($entity, SaveContext::default(), false);

        $invalidator->onAfterSave($event);

        self::assertSame([], $cache->invalidatedTags);
    }

    private function makeEntity(string $entityTypeId, int|string|null $id): EntityInterface
    {
        return new class ($entityTypeId, $id) implements EntityInterface {
            public function __construct(private readonly string $typeId, private readonly int|string|null $idValue) {}

            public function id(): int|string|null
            {
                return $this->idValue;
            }

            public function uuid(): string
            {
                return '00000000-0000-0000-0000-000000000001';
            }

            public function label(): string
            {
                return 'stub';
            }

            public function getEntityTypeId(): string
            {
                return $this->typeId;
            }

            public function bundle(): string
            {
                return $this->typeId;
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return [];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }

    private function makeTranslatableEntity(
        string $entityTypeId,
        int|string $id,
        string $activeLangcode,
    ): EntityInterface&TranslatableInterface {
        return new class ($entityTypeId, $id, $activeLangcode) implements EntityInterface, TranslatableInterface {
            public function __construct(
                private readonly string $typeId,
                private readonly int|string $idValue,
                private readonly string $activeLc,
            ) {}

            // --- EntityInterface ---

            public function id(): int|string|null
            {
                return $this->idValue;
            }

            public function uuid(): string
            {
                return '00000000-0000-0000-0000-000000000001';
            }

            public function label(): string
            {
                return 'stub';
            }

            public function getEntityTypeId(): string
            {
                return $this->typeId;
            }

            public function bundle(): string
            {
                return $this->typeId;
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return [];
            }

            public function language(): string
            {
                return $this->activeLc;
            }

            // --- TranslatableInterface ---

            public function defaultLangcode(): string
            {
                return $this->activeLc;
            }

            public function activeLangcode(): string
            {
                return $this->activeLc;
            }

            public function hasTranslation(string $langcode): bool
            {
                return $langcode === $this->activeLc;
            }

            public function getTranslation(string $langcode): static
            {
                return $this;
            }

            public function addTranslation(string $langcode): static
            {
                return $this;
            }

            public function removeTranslation(string $langcode): void {}

            public function translations(): iterable
            {
                yield $this->activeLc => $this;
            }

            public function getTranslationLanguages(): array
            {
                return [$this->activeLc];
            }

            public function fieldLangcode(string $fieldName): ?string
            {
                return null;
            }
        };
    }
}

/**
 * Records every {@see TaggedCacheInterface::invalidateByTag()} call in order
 * so tests can assert exact tag emission shape and ordering.
 */
final class RecordingTaggedCache implements TaggedCacheInterface
{
    /** @var list<string> */
    public array $invalidatedTags = [];

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void {}

    public function invalidateByTag(string $tag): int
    {
        $this->invalidatedTags[] = $tag;

        return 0;
    }

    public function getTagsFor(string $key): array
    {
        return [];
    }

    // --- CacheBackendInterface ---

    public function get(string $cid): CacheItem|false
    {
        return false;
    }

    public function getMultiple(array &$cids): array
    {
        return [];
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void {}

    public function delete(string $cid): void {}

    public function deleteMultiple(array $cids): void {}

    public function deleteAll(): void {}

    public function invalidate(string $cid): void {}

    public function invalidateMultiple(array $cids): void {}

    public function invalidateAll(): void {}

    public function removeBin(): void {}
}

/**
 * Throws on every invalidation attempt so the FR-040 best-effort path is
 * exercised end-to-end.
 */
final class ExplodingTaggedCache implements TaggedCacheInterface
{
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void {}

    public function invalidateByTag(string $tag): int
    {
        throw new \RuntimeException('cache backend simulated failure for tag ' . $tag);
    }

    public function getTagsFor(string $key): array
    {
        return [];
    }

    // --- CacheBackendInterface ---

    public function get(string $cid): CacheItem|false
    {
        return false;
    }

    public function getMultiple(array &$cids): array
    {
        return [];
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void {}

    public function delete(string $cid): void {}

    public function deleteMultiple(array $cids): void {}

    public function deleteAll(): void {}

    public function invalidate(string $cid): void {}

    public function invalidateMultiple(array $cids): void {}

    public function invalidateAll(): void {}

    public function removeBin(): void {}
}

/**
 * Lightweight {@see LoggerInterface} that captures every record in order so
 * tests can assert on level, message, and context. We avoid the framework
 * NullLogger here because we need to observe the warnings emitted by
 * {@see ListingCacheInvalidator}'s FR-040 best-effort path.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level->value,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
