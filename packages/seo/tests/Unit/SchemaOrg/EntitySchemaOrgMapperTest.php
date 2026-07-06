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

    #[Test]
    public function script_tag_does_not_allow_closing_tag_breakout(): void
    {
        // A hostile entity label (reachable via SsrPageHandler, which renders
        // $entity->label() into the JSON-LD `name`) must not be able to close
        // the <script> element and inject markup into the page.
        $mapper = new EntitySchemaOrgMapper();
        $tag = $mapper->toScriptTag($mapper->map(
            $this->entity('node', 'article', '</script><img src=x onerror=alert(1)>'),
            'https://x.test/hi',
        ));

        // The only `</script>` in the output must be the genuine closing tag.
        self::assertSame(1, substr_count($tag, '</script>'));
        // The raw `<` / `>` from the label must be hex-escaped in the JSON body.
        self::assertStringNotContainsString('<img', $tag);
        self::assertStringContainsString('<', $tag);
        // Round-trips back to the original label once parsed.
        $json = substr($tag, \strlen('<script type="application/ld+json">'), -\strlen('</script>'));
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('</script><img src=x onerror=alert(1)>', $decoded['name']);
    }

    #[Test]
    public function invalid_utf8_label_is_scrubbed_instead_of_crashing_the_page(): void
    {
        // Storable via the entity system (no UTF-8 validation gate on field
        // values); previously this threw JsonException from json_encode's
        // JSON_THROW_ON_ERROR and 500'd the whole page render (audit
        // L3-seo.md, MAJOR finding).
        $invalidLabel = "Hello \xB1\x31 World";
        self::assertFalse(mb_check_encoding($invalidLabel, 'UTF-8'));

        $mapper = new EntitySchemaOrgMapper();
        $node = $mapper->map($this->entity('node', 'article', $invalidLabel), 'https://x.test/hello');

        self::assertTrue(mb_check_encoding($node['name'], 'UTF-8'));

        // Must not throw, and must produce valid, parseable JSON-LD.
        $tag = $mapper->toScriptTag($node);
        $json = substr($tag, \strlen('<script type="application/ld+json">'), -\strlen('</script>'));
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsString($decoded['name']);
    }

    #[Test]
    public function invalid_utf8_description_is_scrubbed_instead_of_crashing_the_page(): void
    {
        $invalidDescription = "A summary \xFF\xFE with bad bytes.";
        self::assertFalse(mb_check_encoding($invalidDescription, 'UTF-8'));

        $mapper = new EntitySchemaOrgMapper();
        $node = $mapper->map(
            $this->entity('node', 'article', 'Hello'),
            'https://x.test/hello',
            $invalidDescription,
        );

        self::assertTrue(mb_check_encoding($node['description'], 'UTF-8'));

        $tag = $mapper->toScriptTag($node);
        $json = substr($tag, \strlen('<script type="application/ld+json">'), -\strlen('</script>'));
        json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function invalid_utf8_label_override_is_scrubbed(): void
    {
        $invalidOverride = "Safe \xC0\xAF Label";
        self::assertFalse(mb_check_encoding($invalidOverride, 'UTF-8'));

        $mapper = new EntitySchemaOrgMapper();
        $node = $mapper->map(
            $this->entity('node', 'article', 'Original'),
            'https://x.test/hello',
            labelOverride: $invalidOverride,
        );

        self::assertTrue(mb_check_encoding($node['name'], 'UTF-8'));
        $mapper->toScriptTag($node);
    }
}
