<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigSyncDeserializer;

#[CoversClass(ConfigSyncDeserializer::class)]
final class StrictConfigSyncDeserializerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function lexicalHazards(): iterable
    {
        yield 'duplicate key after null' => ["title:\ntitle: Waaseyaa\n"];
        yield 'quoted duplicate key after null' => ["'title':\ntitle: Waaseyaa\n"];
        yield 'anchor' => ["title: &shared Waaseyaa\ncopy: Waaseyaa\n"];
        yield 'alias' => ["title: Waaseyaa\ncopy: *shared\n"];
        yield 'merge key' => ["title: Waaseyaa\n<<: {label: merged}\n"];
        yield 'explicit tag' => ["title: !php/object Waaseyaa\n"];
        yield 'directive' => ["%YAML 1.2\n---\ntitle: Waaseyaa\n"];
        yield 'implicit date' => ["title: Waaseyaa\nchanged: 2026-08-15\n"];
        yield 'flow implicit date' => ["title: Waaseyaa\nchanged: [2026-08-15]\n"];
    }

    #[Test]
    #[DataProvider('lexicalHazards')]
    public function lexical_hazards_are_rejected_before_yaml_parsing(string $fields): void
    {
        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($this->yaml($fields), 'system.site.yml');
    }

    #[Test]
    public function invalid_utf8_is_rejected(): void
    {
        $this->expectException(ConfigSerializationException::class);
        new ConfigSyncDeserializer()->fromYaml($this->yaml("title: \xC3\x28\n"), 'system.site.yml');
    }

    #[Test]
    public function floats_and_unsafe_integers_are_rejected_by_the_typed_walk(): void
    {
        foreach (["ratio: 1.5\n", "count: 9007199254740992\n"] as $fields) {
            try {
                new ConfigSyncDeserializer()->fromYaml($this->yaml($fields), 'system.site.yml');
                self::fail('Expected numeric hazard to be rejected.');
            } catch (ConfigSerializationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function syntax_like_text_inside_quoted_and_block_scalars_is_preserved(): void
    {
        $yaml = $this->yaml("description: |\n  Literal &anchor, *alias, !tag, and 2026-08-15.\nlabel: '*literal'\n");

        $file = new ConfigSyncDeserializer()->fromYaml($yaml, 'system.site.yml');

        self::assertSame("Literal &anchor, *alias, !tag, and 2026-08-15.\n", $file->fields['description']);
        self::assertSame('*literal', $file->fields['label']);
    }

    #[Test]
    public function repeated_keys_in_distinct_sequence_items_are_not_duplicates(): void
    {
        $file = new ConfigSyncDeserializer()->fromYaml(
            $this->yaml("items:\n  - id: first\n  - id: second\n"),
            'system.site.yml',
        );

        self::assertSame([['id' => 'first'], ['id' => 'second']], $file->fields['items']);
    }

    private function yaml(string $fields): string
    {
        return "_meta:\n"
            . "  dependencies: []\n"
            . "  entity_id: site\n"
            . "  entity_type: system\n"
            . "  format: waaseyaa.config-sync/1\n"
            . "  langcode: en\n"
            . "  owner_config_contract_version: 1\n"
            . "  owner_package: waaseyaa/config\n"
            . "  schema_hash: 'sha256:" . str_repeat('a', 64) . "'\n"
            . "  schema_id: waaseyaa.system.site\n"
            . "  schema_version: 1\n"
            . "  uuid: 0193abc\n"
            . $fields;
    }
}
