<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Field\Discovery\FieldTypeDiscovery;
use Waaseyaa\Field\Exception\DuplicateFieldTypeException;
use Waaseyaa\Field\Exception\InvalidFieldTypePluginException;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Item\StringItem;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;

/**
 * The downstream registration channel (#2786 B1): a package manifest's
 * `field_types` inventory is admitted next to the built-in plugins, duplicate
 * ids are refused naming both classes, and genuinely unknown ids stay
 * fail-closed.
 */
#[CoversClass(FieldTypeManager::class)]
#[CoversClass(FieldTypeDiscovery::class)]
#[CoversClass(DuplicateFieldTypeException::class)]
#[CoversClass(InvalidFieldTypePluginException::class)]
final class FieldTypeManagerExtensionTest extends TestCase
{
    #[Test]
    public function manifestDeclaredPluginIsAdmittedAlongsideTheBuiltIns(): void
    {
        $class = ExtensionFieldTypeFixture::declare('fixture_money', 'Money');

        $manager = FieldTypeManager::fromManifest(['fixture_money' => $class]);

        self::assertTrue($manager->hasDefinition('fixture_money'));
        self::assertSame($class, $manager->getDefinition('fixture_money')->class);
        self::assertSame('Money', $manager->getDefinition('fixture_money')->label);
        self::assertTrue($manager->hasDefinition('string'), 'built-in plugins stay admitted');

        $definition = new FieldDefinition(name: 'price', type: 'fixture_money');
        self::assertSame(ExtensionFieldTypeFixture::VALUE_JSON_SCHEMA, $manager->entityValueJsonSchemaFor($definition));
        self::assertSame(ExtensionFieldTypeFixture::COLUMN_SCHEMA, $manager->entityStorageColumnSchemaFor($definition));
        self::assertContains('fixture_money', $manager->blueprintFieldTypeIds());
    }

    #[Test]
    public function manifestEntriesForTheBuiltInPluginsAreIdempotent(): void
    {
        $manager = FieldTypeManager::fromManifest(['string' => StringItem::class]);

        self::assertSame(
            array_keys(new FieldTypeManager()->getDefinitions()),
            array_keys($manager->getDefinitions()),
        );
        self::assertSame(StringItem::class, $manager->getDefinition('string')->class);
    }

    #[Test]
    public function aSecondPluginClaimingAnAdmittedIdIsRefusedNamingBothClasses(): void
    {
        $first = ExtensionFieldTypeFixture::declare('fixture_duplicate');
        $second = ExtensionFieldTypeFixture::declare('fixture_duplicate');

        try {
            new FieldTypeManager(extensionClasses: [$first, $second])->getDefinitions();
            self::fail('Two plugins claiming one id must be refused.');
        } catch (DuplicateFieldTypeException $exception) {
            self::assertStringContainsString('fixture_duplicate', $exception->getMessage());
            self::assertStringContainsString($first, $exception->getMessage());
            self::assertStringContainsString($second, $exception->getMessage());
        }
    }

    #[Test]
    public function aPluginShadowingABuiltInIdIsRefused(): void
    {
        $impostor = ExtensionFieldTypeFixture::declare('string');

        try {
            FieldTypeManager::fromManifest(['string' => $impostor]);
            self::fail('A downstream plugin must not shadow a built-in id.');
        } catch (DuplicateFieldTypeException $exception) {
            self::assertStringContainsString(StringItem::class, $exception->getMessage());
            self::assertStringContainsString($impostor, $exception->getMessage());
        }
    }

    #[Test]
    public function fromManifestFailsAtConstructionRatherThanFirstUse(): void
    {
        $this->expectException(InvalidFieldTypePluginException::class);

        FieldTypeManager::fromManifest(['fixture_missing' => ExtensionFieldTypeFixture::NAMESPACE . '\\DoesNotExist']);
    }

    #[Test]
    public function aManifestClassWithoutTheAttributeIsRefused(): void
    {
        $class = ExtensionFieldTypeFixture::declareWithoutAttribute();

        try {
            FieldTypeManager::fromManifest(['fixture_no_attribute' => $class]);
            self::fail('A class without #[FieldType] has no id to be admitted under.');
        } catch (InvalidFieldTypePluginException $exception) {
            self::assertStringContainsString($class, $exception->getMessage());
        }
    }

    #[Test]
    public function aManifestClassThatIsNotAFieldTypeIsRefused(): void
    {
        $class = ExtensionFieldTypeFixture::declareNonFieldType('fixture_plain');

        try {
            FieldTypeManager::fromManifest(['fixture_plain' => $class]);
            self::fail('A class without the static schema seam cannot project schemas.');
        } catch (InvalidFieldTypePluginException $exception) {
            self::assertStringContainsString($class, $exception->getMessage());
        }
    }

    #[Test]
    public function aManifestIdThatDisagreesWithThePluginAttributeIsRefused(): void
    {
        $class = ExtensionFieldTypeFixture::declare('attribute_money');

        try {
            FieldTypeManager::fromManifest(['cached_money' => $class]);
            self::fail('The cached manifest id must remain bound to the attribute id it compiled.');
        } catch (InvalidFieldTypePluginException $exception) {
            self::assertStringContainsString('cached_money', $exception->getMessage());
            self::assertStringContainsString('attribute_money', $exception->getMessage());
            self::assertStringContainsString($class, $exception->getMessage());
        }
    }

    #[Test]
    public function theCacheIdentityIncludesTheExactManifestIdClassPair(): void
    {
        $class = ExtensionFieldTypeFixture::declare('cached_attribute_money');
        $cache = new MemoryBackend();
        FieldTypeManager::fromManifest(['cached_attribute_money' => $class], $cache);

        $this->expectException(InvalidFieldTypePluginException::class);
        FieldTypeManager::fromManifest(['stale_manifest_money' => $class], $cache);
    }

    #[Test]
    public function unknownIdsStayFailClosedWhenExtensionsAreAdmitted(): void
    {
        $manager = FieldTypeManager::fromManifest([
            'fixture_known' => ExtensionFieldTypeFixture::declare('fixture_known'),
        ]);

        $this->expectException(UnknownFieldTypeException::class);
        $manager->entityValueJsonSchemaFor(new FieldDefinition(name: 'mystery', type: 'fixture_unknown'));
    }

    #[Test]
    public function theStaticDefaultNeverLearnsManifestPlugins(): void
    {
        $manager = FieldTypeManager::fromManifest([
            'fixture_isolated' => ExtensionFieldTypeFixture::declare('fixture_isolated'),
        ]);

        self::assertTrue($manager->hasDefinition('fixture_isolated'));
        self::assertFalse(FieldTypeManager::default()->hasDefinition('fixture_isolated'));
        self::assertFalse(new FieldTypeManager()->hasDefinition('fixture_isolated'));
    }
}
