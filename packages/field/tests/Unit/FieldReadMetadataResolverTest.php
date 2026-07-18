<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldReadMetadataResolver;
use Waaseyaa\Field\FieldReadMetadataSource;

final class FieldReadMetadataResolverTest extends TestCase
{
    public function test_compiles_an_access_rule_at_the_field_composition_boundary(): void
    {
        $rule = (new FieldReadMetadataResolver())->compile(
            new FieldDefinition(name: 'title', type: 'string', read: FieldReadLevel::Protected),
        );

        self::assertInstanceOf(CompiledFieldReadRule::class, $rule);
        self::assertSame('title', $rule->field);
        self::assertSame(FieldReadLevel::Protected, $rule->level);
    }

    #[Test]
    public function explicit_companion_metadata_wins_and_is_exposed_without_changing_the_legacy_interface(): void
    {
        $definition = new FieldDefinition(name: 'mail', type: 'email', read: FieldReadLevel::Internal);
        $metadata = (new FieldReadMetadataResolver())->resolve($definition);

        self::assertSame(FieldReadLevel::Internal, $metadata->level);
        self::assertSame(FieldReadMetadataSource::Definition, $metadata->source);
    }

    #[Test]
    public function legacy_internal_setting_is_adapted(): void
    {
        $definition = new FieldDefinition(name: 'token', type: 'string', settings: ['internal' => true]);
        $metadata = (new FieldReadMetadataResolver())->resolve($definition);

        self::assertSame(FieldReadLevel::Internal, $metadata->level);
        self::assertSame(FieldReadMetadataSource::LegacyInternal, $metadata->source);
    }

    #[Test]
    public function unclassified_metadata_remains_compatibility_public_in_wp1(): void
    {
        $metadata = (new FieldReadMetadataResolver())->resolve(new FieldDefinition(name: 'title', type: 'string'));

        self::assertNull($metadata->level);
        self::assertSame(FieldReadMetadataSource::Unclassified, $metadata->source);
        self::assertSame(FieldReadLevel::Public, $metadata->compatibilityLevel());
    }

    #[Test]
    public function conflicting_sources_are_rejected(): void
    {
        $this->expectException(\LogicException::class);

        (new FieldReadMetadataResolver())->resolve(
            new FieldDefinition(name: 'mail', type: 'email', read: FieldReadLevel::Protected),
            FieldReadLevel::Internal,
        );
    }
}
