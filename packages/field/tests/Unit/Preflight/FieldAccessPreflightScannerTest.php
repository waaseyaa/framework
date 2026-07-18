<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\Preflight\FieldAccessLiveInventory;
use Waaseyaa\Field\Preflight\FieldAccessPreflightScanner;

final class FieldAccessPreflightScannerTest extends TestCase
{
    public function test_registered_definitions_structural_keys_and_live_keys_are_scanned(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'profile',
            label: 'Profile',
            class: PreflightProfile::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name'],
            _fieldDefinitions: [
                'name' => new FieldDefinition('name', 'string', targetEntityTypeId: 'profile', read: FieldReadLevel::Protected),
            ],
        ));
        $registry->registerBundleFields('profile', 'member', [
            new FieldDefinition('bio', 'text', targetEntityTypeId: 'profile', targetBundle: 'member', read: FieldReadLevel::Public),
        ]);

        $result = (new FieldAccessPreflightScanner())->scan(
            $manager,
            new FieldAccessLiveInventory(
                frameworkVersion: '0.1.0-alpha.268',
                schemaFingerprint: 'schema-1',
                liveKeys: ['profile|member|pid', 'profile|member|uuid', 'profile|member|type', 'profile|member|name', 'profile|member|bio'],
            ),
        );

        self::assertTrue($result->ready);
        self::assertSame('public:structural', $result->data->fields['profile|*|pid']);
        self::assertSame('protected:definition', $result->data->fields['profile|*|name']);
        self::assertSame('public:definition', $result->data->fields['profile|member|bio']);
        self::assertSame([], $result->data->unclassifiedEntries);
    }

    public function test_unknown_live_keys_conflicts_and_transition_inventories_block_readiness(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'profile',
            label: 'Profile',
            class: PreflightProfile::class,
            _fieldDefinitions: [
                'mail' => new FieldDefinition('mail', 'email', targetEntityTypeId: 'profile', read: FieldReadLevel::Internal),
            ],
        ));

        $result = (new FieldAccessPreflightScanner())->scan($manager, new FieldAccessLiveInventory(
            frameworkVersion: '0.1.0-alpha.268',
            schemaFingerprint: 'schema-2',
            liveKeys: ['profile|*|mail', 'profile|*|legacy_key'],
            artifactLevels: ['profile|*|mail' => FieldReadLevel::Protected],
            v1Drivers: ['legacy.profile'],
            serializedEntities: ['cache:profile:1'],
            legacyPayloads: ['queue:42'],
        ));

        self::assertFalse($result->ready);
        self::assertSame(['profile|*|legacy_key'], $result->data->unclassifiedEntries);
        self::assertSame(['profile|*|mail'], $result->data->conflicts);
        self::assertSame(['legacy.profile'], $result->data->v1Drivers);
    }

    public function test_candidate_schema_and_definition_changes_each_invalidate_the_checksum(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'profile',
            label: 'Profile',
            class: PreflightProfile::class,
            _fieldDefinitions: [
                'name' => new FieldDefinition('name', 'string', targetEntityTypeId: 'profile', read: FieldReadLevel::Public),
            ],
        ));
        $scanner = new FieldAccessPreflightScanner();
        $baseline = $scanner->scan($manager, new FieldAccessLiveInventory('candidate-a', 'schema-a'));
        $candidateChanged = $scanner->scan($manager, new FieldAccessLiveInventory('candidate-b', 'schema-a'));
        $schemaChanged = $scanner->scan($manager, new FieldAccessLiveInventory('candidate-a', 'schema-b'));

        $registry->mergeCoreFields('profile', [
            'bio' => new FieldDefinition('bio', 'text', targetEntityTypeId: 'profile', read: FieldReadLevel::Protected),
        ]);
        $definitionChanged = $scanner->scan($manager, new FieldAccessLiveInventory('candidate-a', 'schema-a'));

        self::assertNotSame($baseline->checksum, $candidateChanged->checksum);
        self::assertNotSame($baseline->checksum, $schemaChanged->checksum);
        self::assertNotSame($baseline->checksum, $definitionChanged->checksum);
    }
}

final class PreflightProfile extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'profile', ['id' => 'pid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name']);
    }
}
