<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

final class EntityTypeApiExposurePolicyTest extends TestCase
{
    #[Test]
    public function absent_allowlist_inherits_declared_capability(): void
    {
        $manager = $this->manager();
        $policy = EntityTypeApiExposurePolicy::fromConfig($manager, []);

        self::assertTrue($policy->isExposed('article'));
        self::assertFalse($policy->isExposed('internal'));
    }

    #[Test]
    public function present_allowlist_is_closed_world_and_may_only_narrow(): void
    {
        $manager = $this->manager();
        $policy = EntityTypeApiExposurePolicy::fromConfig($manager, [
            'api' => ['entity_type_allowlist' => ['article']],
        ]);

        self::assertTrue($policy->isExposed('article'));
        self::assertFalse($policy->isExposed('tag'));
        self::assertFalse($policy->isExposed('internal'));
        self::assertSame(['article' => true, 'internal' => false, 'tag' => false], $policy->effectiveMap());
    }

    #[Test]
    public function empty_allowlist_suppresses_every_generic_entity_type(): void
    {
        $policy = EntityTypeApiExposurePolicy::fromConfig($this->manager(), [
            'api' => ['entity_type_allowlist' => []],
        ]);

        self::assertSame(['article' => false, 'internal' => false, 'tag' => false], $policy->effectiveMap());
    }

    #[Test]
    public function stale_unknown_duplicate_malformed_and_declared_false_entries_fail_fast(): void
    {
        $invalid = [
            ['api' => ['entity_type_allowlist' => ['missing']]],
            ['api' => ['entity_type_allowlist' => ['article', 'article']]],
            ['api' => ['entity_type_allowlist' => ['']]],
            ['api' => ['entity_type_allowlist' => ['article' => true]]],
            ['api' => ['entity_type_allowlist' => ['internal']]],
        ];

        foreach ($invalid as $config) {
            try {
                EntityTypeApiExposurePolicy::fromConfig($this->manager(), $config);
                self::fail('Expected invalid exposure configuration to fail.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('tag', $e->getMessage());
                self::assertStringNotContainsString(json_encode($config, JSON_THROW_ON_ERROR), $e->getMessage());
            }
        }
    }

    private function manager(): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        foreach (['article' => true, 'tag' => true, 'internal' => false] as $id => $api) {
            $manager->registerEntityType(new EntityType(
                id: $id,
                label: ucfirst($id),
                class: \stdClass::class,
                keys: ['id' => 'id'],
                api: $api,
            ));
        }

        return $manager;
    }
}
