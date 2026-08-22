<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Discovery;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Seo\Discovery\DiscoveryFailurePolicy;
use Waaseyaa\Seo\Discovery\NonPublicEntityTypes;

#[CoversClass(NonPublicEntityTypes::class)]
#[CoversClass(DiscoveryFailurePolicy::class)]
final class DiscoveryPolicyDefaultsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function floorTypes(): iterable
    {
        foreach (NonPublicEntityTypes::defaults() as $entityTypeId) {
            yield $entityTypeId => [$entityTypeId];
        }
    }

    #[Test]
    #[DataProvider('floorTypes')]
    public function every_floor_entry_is_excluded(string $entityTypeId): void
    {
        self::assertTrue(NonPublicEntityTypes::excludes($entityTypeId));
    }

    #[Test]
    public function the_floor_is_exactly_the_types_that_were_previously_hardcoded_in_the_controller(): void
    {
        // Pinned, not merely enumerated: an application deletes its own copy of
        // this list on the strength of this list being the same one.
        self::assertSame([
            'user',
            'path_alias',
            'relationship',
            'file',
            'menu_link_content',
            'menu',
            'config',
            'crop',
            'workflow',
            'genealogy_family',
            'genealogy_event',
        ], NonPublicEntityTypes::defaults());
    }

    #[Test]
    public function ordinary_content_types_are_not_excluded_by_the_floor(): void
    {
        foreach (['node', 'taxonomy_term', 'media', 'genealogy_person'] as $entityTypeId) {
            self::assertFalse(NonPublicEntityTypes::excludes($entityTypeId), $entityTypeId);
        }
    }

    #[Test]
    public function missing_configuration_keeps_the_backward_compatible_default(): void
    {
        self::assertSame(DiscoveryFailurePolicy::EmptyDocument, DiscoveryFailurePolicy::fromConfig([]));
        self::assertSame(DiscoveryFailurePolicy::EmptyDocument, DiscoveryFailurePolicy::fromConfig(['seo' => []]));
        self::assertSame(
            DiscoveryFailurePolicy::EmptyDocument,
            DiscoveryFailurePolicy::fromConfig(['seo' => ['failure_policy' => null]]),
        );
    }

    #[Test]
    public function propagate_is_selectable_from_configuration(): void
    {
        self::assertSame(
            DiscoveryFailurePolicy::Propagate,
            DiscoveryFailurePolicy::fromConfig(['seo' => ['failure_policy' => 'propagate']]),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedPolicies(): iterable
    {
        yield 'typo' => ['propogate'];
        yield 'wrong case' => ['Propagate'];
        yield 'unknown word' => ['loud'];
        yield 'non-string' => [true];
        yield 'array' => [['propagate']];
        yield 'integer' => [1];
    }

    #[Test]
    #[DataProvider('malformedPolicies')]
    public function a_malformed_configured_policy_is_a_boot_error_not_a_silent_default(mixed $configured): void
    {
        // A typo must not quietly select the more permissive case.
        $this->expectException(InvalidArgumentException::class);
        DiscoveryFailurePolicy::fromConfig(['seo' => ['failure_policy' => $configured]]);
    }
}
