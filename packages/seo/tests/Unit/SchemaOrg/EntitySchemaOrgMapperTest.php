<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\SchemaOrg;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper;

#[CoversClass(EntitySchemaOrgMapper::class)]
final class EntitySchemaOrgMapperTest extends TestCase
{
    private function entity(string $type, string $bundle, string $label): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($type);
        $entity->method('bundle')->willReturn($bundle);
        $entity->method('label')->willReturn($label);

        return $entity;
    }

    #[Test]
    public function maps_known_type_and_required_fields(): void
    {
        $node = new EntitySchemaOrgMapper()->map(
            $this->entity('node', 'article', 'Hello'),
            'https://x.test/hello',
            'A summary.',
            '2026-06-17T00:00:00+00:00',
        );

        self::assertSame('https://schema.org', $node['@context']);
        self::assertSame('Article', $node['@type']);
        self::assertSame('Hello', $node['name']);
        self::assertSame('https://x.test/hello', $node['url']);
        self::assertSame('A summary.', $node['description']);
        self::assertSame('2026-06-17T00:00:00+00:00', $node['dateModified']);
    }

    #[Test]
    public function bundle_specific_mapping_wins_over_type_default(): void
    {
        $node = new EntitySchemaOrgMapper()->map($this->entity('node', 'page', 'P'), 'https://x.test/p');
        self::assertSame('WebPage', $node['@type']);
    }

    #[Test]
    public function falls_back_to_webpage_for_unknown_type(): void
    {
        $node = new EntitySchemaOrgMapper()->map($this->entity('widget', '', 'W'), 'https://x.test/w');
        self::assertSame('WebPage', $node['@type']);
    }

    #[Test]
    public function overrides_merge_over_defaults(): void
    {
        $mapper = new EntitySchemaOrgMapper(['widget' => 'SoftwareApplication']);
        $node = $mapper->map($this->entity('widget', '', 'W'), 'https://x.test/w');
        self::assertSame('SoftwareApplication', $node['@type']);
    }

    #[Test]
    public function omits_optional_fields_when_empty(): void
    {
        $node = new EntitySchemaOrgMapper()->map($this->entity('taxonomy_term', '', 'Term'), 'https://x.test/t');
        self::assertSame('DefinedTerm', $node['@type']);
        self::assertArrayNotHasKey('description', $node);
        self::assertArrayNotHasKey('dateModified', $node);
    }

    #[Test]
    public function renders_script_tag(): void
    {
        $mapper = new EntitySchemaOrgMapper();
        $tag = $mapper->toScriptTag($mapper->map($this->entity('node', 'article', 'Hi'), 'https://x.test/hi'));

        self::assertStringStartsWith('<script type="application/ld+json">', $tag);
        self::assertStringEndsWith('</script>', $tag);
        self::assertStringContainsString('"@type":"Article"', $tag);
        // Slashes in URLs are not escaped (readability).
        self::assertStringContainsString('https://x.test/hi', $tag);
    }
}
