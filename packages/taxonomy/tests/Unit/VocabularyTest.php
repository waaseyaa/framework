<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\ConfigEntityInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Taxonomy\Vocabulary;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Taxonomy\Vocabulary
 */
class VocabularyTest extends TestCase
{
    // ---------------------------------------------------------------
    // Construction and interfaces
    // ---------------------------------------------------------------

    public function testImplementsInterfaces(): void
    {
        $vocab = new Vocabulary();

        $this->assertInstanceOf(EntityInterface::class, $vocab);
        $this->assertInstanceOf(ConfigEntityInterface::class, $vocab);
        $this->assertInstanceOf(ConfigEntityBase::class, $vocab);
    }

    public function testEntityTypeId(): void
    {
        $vocab = new Vocabulary();

        $this->assertSame('taxonomy_vocabulary', $vocab->getEntityTypeId());
    }

    public function testIsNewWhenNoVidProvided(): void
    {
        $vocab = new Vocabulary();

        $this->assertTrue($vocab->isNew());
    }

    public function testIsNotNewWhenVidProvided(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertTrue($vocab->isNew());
    }

    public function testConfigEntityHasNoUuid(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        // Config entities do not have UUIDs.
        $this->assertSame('', $vocab->uuid());
    }

    public function testUuidPreservedWhenProvided(): void
    {
        $expected = '550e8400-e29b-41d4-a716-446655440000';
        $vocab = new Vocabulary(['vid' => 'tags', 'uuid' => $expected]);

        $this->assertSame($expected, $vocab->uuid());
    }

    // ---------------------------------------------------------------
    // getVid()
    // ---------------------------------------------------------------

    public function testGetVid(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertSame('tags', $vocab->getVid());
    }

    public function testGetVidReturnsEmptyStringWhenNotSet(): void
    {
        $vocab = new Vocabulary();

        $this->assertSame('', $vocab->getVid());
    }

    // ---------------------------------------------------------------
    // id() maps to vid
    // ---------------------------------------------------------------

    public function testIdReturnsVid(): void
    {
        $vocab = new Vocabulary(['vid' => 'categories']);

        $this->assertSame('categories', $vocab->id());
    }

    // ---------------------------------------------------------------
    // Name (label)
    // ---------------------------------------------------------------

    public function testGetName(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'name' => 'Tags']);

        $this->assertSame('Tags', $vocab->getName());
    }

    public function testGetNameReturnsEmptyStringWhenNotSet(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertSame('', $vocab->getName());
    }

    public function testSetName(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'name' => 'Tags']);

        $result = $vocab->setName('Updated Tags');

        $this->assertSame('Updated Tags', $vocab->getName());
        $this->assertSame($vocab, $result, 'setName() should return $this for fluent API');
    }

    public function testLabelMapsToName(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'name' => 'Tags']);

        $this->assertSame('Tags', $vocab->label());
    }

    // ---------------------------------------------------------------
    // Description
    // ---------------------------------------------------------------

    public function testGetDescriptionDefault(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertSame('', $vocab->getDescription());
    }

    public function testGetDescription(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'description' => 'Free-form tagging.']);

        $this->assertSame('Free-form tagging.', $vocab->getDescription());
    }

    public function testSetDescription(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $result = $vocab->setDescription('Updated description');

        $this->assertSame('Updated description', $vocab->getDescription());
        $this->assertSame($vocab, $result, 'setDescription() should return $this for fluent API');
    }

    // ---------------------------------------------------------------
    // Weight
    // ---------------------------------------------------------------

    public function testGetWeightDefault(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertSame(0, $vocab->getWeight());
    }

    public function testGetWeight(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'weight' => 5]);

        $this->assertSame(5, $vocab->getWeight());
    }

    public function testSetWeight(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $result = $vocab->setWeight(10);

        $this->assertSame(10, $vocab->getWeight());
        $this->assertSame($vocab, $result, 'setWeight() should return $this for fluent API');
    }

    public function testNegativeWeight(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'weight' => -3]);

        $this->assertSame(-3, $vocab->getWeight());
    }

    // ---------------------------------------------------------------
    // Status (inherited from ConfigEntityBase)
    // ---------------------------------------------------------------

    public function testStatusDefaultsToTrue(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertTrue($vocab->status());
    }

    public function testStatusCanBeSetToFalse(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'status' => false]);

        $this->assertFalse($vocab->status());
    }

    public function testEnableAndDisable(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $vocab->disable();
        $this->assertFalse($vocab->status());

        $vocab->enable();
        $this->assertTrue($vocab->status());
    }

    // ---------------------------------------------------------------
    // toConfig()
    // ---------------------------------------------------------------

    public function testToConfigIncludesAllFields(): void
    {
        $vocab = new Vocabulary([
            'vid' => 'tags',
            'name' => 'Tags',
            'description' => 'Free tagging',
            'weight' => 2,
        ]);

        $config = $vocab->toConfig();

        $this->assertSame('tags', $config['vid']);
        $this->assertSame('Tags', $config['name']);
        $this->assertSame('Free tagging', $config['description']);
        $this->assertSame(2, $config['weight']);
        $this->assertTrue($config['status']);
        $this->assertArrayNotHasKey('uuid', $config);
    }

    public function testToConfigReflectsStatusChanges(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags', 'name' => 'Tags']);
        $vocab->disable();

        $config = $vocab->toConfig();

        $this->assertFalse($config['status']);
    }

    // ---------------------------------------------------------------
    // toArray()
    // ---------------------------------------------------------------

    public function testToArrayContainsValues(): void
    {
        $vocab = new Vocabulary([
            'vid' => 'tags',
            'name' => 'Tags',
            'description' => 'Free tagging',
            'weight' => 0,
        ]);

        $array = $vocab->toArray();

        $this->assertSame('tags', $array['vid']);
        $this->assertSame('Tags', $array['name']);
        $this->assertSame('Free tagging', $array['description']);
        $this->assertSame(0, $array['weight']);
    }

    // ---------------------------------------------------------------
    // Bundle defaults to entity type ID
    // ---------------------------------------------------------------

    public function testBundleDefaultsToEntityTypeId(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        // Config entities typically use their entity type ID as bundle.
        $this->assertSame('taxonomy_vocabulary', $vocab->bundle());
    }

    // ---------------------------------------------------------------
    // Language
    // ---------------------------------------------------------------

    public function testLanguageDefaultsToEn(): void
    {
        $vocab = new Vocabulary(['vid' => 'tags']);

        $this->assertSame('en', $vocab->language());
    }
}
