<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Taxonomy\TaxonomyServiceProvider;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Entity\EntityTypeForeignKeyDefinitionInterface;
use Waaseyaa\Taxonomy\VocabularyReferenceConstraint;

#[CoversClass(TaxonomyServiceProvider::class)]
final class TaxonomyServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_term_and_vocabulary(): void
    {
        $provider = new TaxonomyServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(2, $entityTypes);
        $this->assertSame('taxonomy_term', $entityTypes[0]->id());
        $this->assertSame(Term::class, $entityTypes[0]->getClass());
        self::assertInstanceOf(EntityTypeForeignKeyDefinitionInterface::class, $entityTypes[0]);
        self::assertSame(VocabularyReferenceConstraint::NAME, $entityTypes[0]->getStorageForeignKeys()[0]['name']);
        $this->assertSame('taxonomy_vocabulary', $entityTypes[1]->id());
        $this->assertSame(Vocabulary::class, $entityTypes[1]->getClass());
    }

    #[Test]
    public function term_has_field_definitions(): void
    {
        $provider = new TaxonomyServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[0]->getFieldDefinitions();

        $this->assertArrayHasKey('description', $fields);
        $this->assertArrayHasKey('weight', $fields);
        $this->assertArrayHasKey('parent_id', $fields);
        $this->assertArrayHasKey('status', $fields);
    }

    #[Test]
    public function vocabulary_has_field_definitions(): void
    {
        $provider = new TaxonomyServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[1]->getFieldDefinitions();

        $this->assertArrayHasKey('vid', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('description', $fields);
        $this->assertArrayHasKey('weight', $fields);
    }

    /**
     * Regression for #1388: every core FieldDefinition shipped by TaxonomyServiceProvider
     * MUST declare `targetEntityTypeId` matching its owning EntityType id, otherwise
     * `FieldDefinitionRegistry::registerCoreFields()` rejects the bundle at registration
     * time and the kernel cannot register `taxonomy_vocabulary`. The original issue
     * called out `description`; the planning sweep also caught `weight` — this test
     * locks both.
     */
    #[Test]
    public function taxonomy_vocabulary_field_definitions_declare_target_entity_type_id(): void
    {
        $provider = new TaxonomyServiceProvider();
        $provider->register();

        $vocabulary = null;
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'taxonomy_vocabulary') {
                $vocabulary = $t;
                break;
            }
        }
        self::assertNotNull(
            $vocabulary,
            'TaxonomyServiceProvider must register the taxonomy_vocabulary entity type.',
        );

        $fieldDefs = $vocabulary->getFieldDefinitions();
        self::assertArrayHasKey('description', $fieldDefs, '#1388: description must remain a core field on taxonomy_vocabulary.');
        self::assertArrayHasKey('weight', $fieldDefs, '#1388 sweep: weight must remain a core field on taxonomy_vocabulary.');

        foreach ($fieldDefs as $name => $def) {
            self::assertInstanceOf(
                FieldDefinitionInterface::class,
                $def,
                sprintf('taxonomy_vocabulary field "%s" must be a FieldDefinitionInterface instance.', $name),
            );
            self::assertSame(
                'taxonomy_vocabulary',
                $def->getTargetEntityTypeId(),
                sprintf(
                    '#1388: taxonomy_vocabulary core field "%s" must declare targetEntityTypeId '
                    . '"taxonomy_vocabulary"; got "%s". An empty value will be rejected by '
                    . 'FieldDefinitionRegistry::registerCoreFields().',
                    $name,
                    $def->getTargetEntityTypeId(),
                ),
            );
        }
    }
}
