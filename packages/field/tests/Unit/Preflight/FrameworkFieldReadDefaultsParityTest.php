<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Preflight;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\FrameworkFieldReadDefaults;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\Preflight\FieldAccessLiveInventory;
use Waaseyaa\Field\Preflight\FieldAccessPreflightScanner;

final class FrameworkFieldReadDefaultsParityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, FieldReadLevel}> */
    public static function exactDefaults(): iterable
    {
        foreach (FrameworkFieldReadDefaults::exactDefaults() as $key => $level) {
            [$entityType, $bundle, $field] = explode('|', $key);
            yield $key => [$entityType, $bundle, $field, $level];
        }
    }

    #[Test]
    #[DataProvider('exactDefaults')]
    public function every_exact_default_is_identical_in_runtime_and_preflight(
        string $entityType,
        string $bundle,
        string $field,
        FieldReadLevel $level,
    ): void {
        $runtimeBundle = $bundle === '*' ? $entityType : $bundle;
        $values = ['id' => 1, 'bundle' => $runtimeBundle, $field => 'fixture'];
        $layout = EntityReadRuntime::layoutFor(
            FrameworkDefaultFixtureEntity::class,
            $values,
            $entityType,
            ['id' => 'id', 'bundle' => 'bundle'],
            registeredEntityType: true,
        );

        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: $entityType,
            label: $entityType,
            class: FrameworkDefaultFixtureEntity::class,
            keys: ['id' => 'id', 'bundle' => 'bundle'],
        ));
        $preflightKey = $entityType . '|' . $runtimeBundle . '|' . $field;
        $result = (new FieldAccessPreflightScanner())->scan(
            $manager,
            new FieldAccessLiveInventory('candidate', 'schema', liveKeys: [$preflightKey]),
        );

        self::assertSame($level, $layout->level($field), 'runtime ' . $preflightKey);
        self::assertSame(
            $level->value . ':framework_default',
            $result->data->fields[$entityType . '|*|' . $field] ?? $result->data->fields[$preflightKey] ?? null,
            'preflight ' . $preflightKey,
        );
        self::assertNotContains($preflightKey, $result->data->unclassifiedEntries);
    }

    #[Test]
    public function universal_bundle_and_language_defaults_are_runtime_preflight_public(): void
    {
        $values = ['id' => 1, 'bundle' => 'article', 'langcode' => 'en', 'default_langcode' => 'en'];
        $layout = EntityReadRuntime::layoutFor(
            FrameworkDefaultFixtureEntity::class,
            $values,
            'consumer_entity',
            ['id' => 'id'],
            registeredEntityType: true,
        );

        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'consumer_entity',
            label: 'Consumer entity',
            class: FrameworkDefaultFixtureEntity::class,
            keys: ['id' => 'id'],
        ));
        $result = (new FieldAccessPreflightScanner())->scan(
            $manager,
            new FieldAccessLiveInventory(
                'candidate',
                'schema',
                liveKeys: [
                    'consumer_entity|article|bundle',
                    'consumer_entity|article|langcode',
                    'consumer_entity|article|default_langcode',
                ],
            ),
        );

        foreach (['bundle', 'langcode', 'default_langcode'] as $field) {
            self::assertSame(FieldReadLevel::Public, $layout->level($field), 'runtime ' . $field);
            self::assertSame(
                'public:framework_default',
                $result->data->fields['consumer_entity|*|' . $field] ?? null,
                'preflight ' . $field,
            );
        }
        self::assertSame([], $result->data->unclassifiedEntries);
    }

    #[Test]
    public function explicit_metadata_cannot_disagree_with_a_framework_default(): void
    {
        $definition = new FieldDefinition(
            'password_hash',
            'string',
            targetEntityTypeId: 'user',
            read: FieldReadLevel::Public,
        );

        try {
            EntityReadRuntime::layoutFor(
                FrameworkDefaultFixtureEntity::class,
                ['id' => 1, 'password_hash' => 'never'],
                'user',
                ['id' => 'id'],
                registeredEntityType: true,
                entityTypeDefinitions: ['password_hash' => $definition],
            );
            self::fail('Runtime must reject a definition/default conflict.');
        } catch (\LogicException) {
        }

        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: FrameworkDefaultFixtureEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: ['password_hash' => $definition],
        ));
        $result = (new FieldAccessPreflightScanner())->scan(
            $manager,
            new FieldAccessLiveInventory('candidate', 'schema', liveKeys: ['user|user|password_hash']),
        );

        self::assertContains('user|*|password_hash', $result->data->conflicts);
        self::assertFalse($result->ready);
    }
}

final class FrameworkDefaultFixtureEntity extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'fixture', ['id' => 'id', 'bundle' => 'bundle']);
    }
}
