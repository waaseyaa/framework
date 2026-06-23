<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\BundleTemplateCompiler;
use Waaseyaa\Field\Tests\Fixtures\Templates\DuplicateAliasTemplate;
use Waaseyaa\Field\Tests\Fixtures\Templates\DuplicateKeyTemplate;
use Waaseyaa\Field\Tests\Fixtures\Templates\MethodFieldTemplate;
use Waaseyaa\Field\Tests\Fixtures\Templates\NoBundleTemplateClass;
use Waaseyaa\Field\Tests\Fixtures\Templates\RepeatableAttributeTemplate;
use Waaseyaa\Field\Tests\Fixtures\Templates\SampleArticleTemplate;

#[CoversClass(BundleTemplateCompiler::class)]
final class BundleTemplateCompilerTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * A recording stub registry.
     * Calls are stored on the object itself so no reference gymnastics needed.
     */
    private function makeRecordingRegistry(): RecordingFieldDefinitionRegistry
    {
        return new RecordingFieldDefinitionRegistry();
    }

    // ---------------------------------------------------------------------------
    // T009 test cases
    // ---------------------------------------------------------------------------

    #[Test]
    public function single_bundle_with_three_property_fields_produces_three_fields_in_declaration_order(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([SampleArticleTemplate::class]);

        self::assertCount(1, $registry->calls);
        $fields = $registry->calls[0]['fields'];
        self::assertCount(3, $fields);
        self::assertSame('title', $fields[0]->getName());
        self::assertSame('body', $fields[1]->getName());
        self::assertSame('tags', $fields[2]->getName());
    }

    #[Test]
    public function field_metadata_is_correctly_mapped(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([SampleArticleTemplate::class]);

        $title = $registry->calls[0]['fields'][0];
        self::assertSame('title', $title->getName());
        self::assertSame('string', $title->getType());
        self::assertSame('Title', $title->getLabel());
        self::assertSame('basic', $title->getGroup());
        self::assertSame(['headline', 'subject'], $title->getPromptAliases());
        self::assertTrue($title->isRequired());
        self::assertFalse($title->isReadOnly());
    }

    #[Test]
    public function method_fields_come_after_property_fields(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([MethodFieldTemplate::class]);

        $fields = $registry->calls[0]['fields'];
        self::assertCount(3, $fields);
        self::assertSame('prop_a', $fields[0]->getName());
        self::assertSame('prop_b', $fields[1]->getName());
        self::assertSame('method_c', $fields[2]->getName());
    }

    #[Test]
    public function repeatable_attributes_on_single_property_produce_multiple_fields(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([RepeatableAttributeTemplate::class]);

        $fields = $registry->calls[0]['fields'];
        self::assertCount(2, $fields);
        self::assertSame('start_date', $fields[0]->getName());
        self::assertSame('end_date', $fields[1]->getName());
    }

    #[Test]
    public function duplicate_key_within_bundle_throws_invalid_argument_exception(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Duplicate field key 'title' in bundle 'node:duplicate'/");

        $compiler->compile([DuplicateKeyTemplate::class]);
    }

    #[Test]
    public function duplicate_normalized_alias_within_bundle_throws_invalid_argument_exception(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate prompt alias/');

        $compiler->compile([DuplicateAliasTemplate::class]);
    }

    #[Test]
    public function class_without_bundle_template_attribute_is_ignored(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([NoBundleTemplateClass::class]);

        self::assertCount(0, $registry->calls);
    }

    #[Test]
    public function compile_is_idempotent(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([SampleArticleTemplate::class]);
        $compiler->compile([SampleArticleTemplate::class]);

        // Only one registerBundleFields call, not two.
        self::assertCount(1, $registry->calls);
    }

    #[Test]
    public function two_classes_for_different_bundles_are_both_processed(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([SampleArticleTemplate::class, MethodFieldTemplate::class]);

        self::assertCount(2, $registry->calls);
        self::assertSame('article', $registry->calls[0]['bundle']);
        self::assertSame('page', $registry->calls[1]['bundle']);
    }

    #[Test]
    public function entity_type_and_bundle_on_registered_fields_match_bundle_template(): void
    {
        $registry = $this->makeRecordingRegistry();
        $compiler = new BundleTemplateCompiler($registry);

        $compiler->compile([SampleArticleTemplate::class]);

        $call = $registry->calls[0];
        self::assertSame('node', $call['entityType']);
        self::assertSame('article', $call['bundle']);

        foreach ($call['fields'] as $field) {
            self::assertSame('node', $field->getTargetEntityTypeId());
            self::assertSame('article', $field->getTargetBundle());
        }
    }
}

/**
 * A recording stub that implements FieldDefinitionRegistryInterface.
 * Calls to registerBundleFields are accumulated in the public $calls array.
 */
final class RecordingFieldDefinitionRegistry implements FieldDefinitionRegistryInterface
{
    /** @var list<array{entityType: string, bundle: string, fields: list<object>}> */
    public array $calls = [];

    public function registerCoreFields(string $entityTypeId, array $fields): void {}

    public function mergeCoreFields(string $entityTypeId, array $fields): void {}

    public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void
    {
        $this->calls[] = ['entityType' => $entityTypeId, 'bundle' => $bundle, 'fields' => array_values($fields)];
    }

    public function coreFieldsFor(string $entityTypeId): array
    {
        return [];
    }

    public function bundleFieldsFor(string $entityTypeId, string $bundle): array
    {
        return [];
    }

    public function bundleNamesFor(string $entityTypeId): array
    {
        return [];
    }

    public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
    {
        return [];
    }
}
