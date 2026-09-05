<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Blueprint\Emitter\EntityClassEmitter;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Field\Item\EnumItem;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntityKeys;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintStorage;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(EntityClassEmitter::class)]
final class EntityClassEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('entity-class', new EntityClassEmitter()->id());
    }

    #[Test]
    public function itEmitsOneArtifactPerEntityMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new EntityClassEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(['src/Entity/Article.php'], $this->paths($emission->artifacts));
        self::assertSame($this->expected('minimal/src/Entity/Article.php'), $this->content($emission->artifacts, 'src/Entity/Article.php'));
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itEmitsOneArtifactPerEntityMatchingTheCompleteGoldenFixtureWithTheRelationshipFieldOnTheFromEntityExactlyOnce(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new EntityClassEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(
            ['src/Entity/Article.php', 'src/Entity/Enum/ArticleStatus.php', 'src/Entity/Person.php'],
            $this->paths($emission->artifacts),
        );
        self::assertSame($this->expected('complete/src/Entity/Article.php'), $this->content($emission->artifacts, 'src/Entity/Article.php'));
        self::assertSame($this->expected('complete/src/Entity/Person.php'), $this->content($emission->artifacts, 'src/Entity/Person.php'));
        self::assertSame($this->expected('complete/src/Entity/Enum/ArticleStatus.php'), $this->content($emission->artifacts, 'src/Entity/Enum/ArticleStatus.php'));

        $articleContent = $this->content($emission->artifacts, 'src/Entity/Article.php');
        self::assertSame(1, substr_count($articleContent, 'public ?int $author'));
        self::assertSame(0, substr_count($this->content($emission->artifacts, 'src/Entity/Person.php'), '$author'));
    }

    /**
     * `keys.owner: author` is declared on `complete.yaml`'s `article` entity
     * (validated by `ApplicationBlueprintValidator` for ownership policies),
     * but `ContentEntityKeys` has no `owner` parameter and the entity
     * runtime has no "owner" key at all — the emitter intentionally does not
     * carry it onto the generated class. Recorded as a known limitation in
     * this emitter's docblock (FW-SITE-BLUEPRINT-01D); a future ownership
     * emitter must re-derive it from the blueprint itself.
     */
    #[Test]
    public function keysOwnerIsIntentionallyNotEmittedOntoTheGeneratedEntityClass(): void
    {
        $manifest = $this->manifest('complete.yaml');
        self::assertSame('author', $manifest->applicationBlueprint->entities['article']->keys->owner);

        $articleContent = $this->content(
            new EntityClassEmitter()->emit($manifest->applicationBlueprint, $manifest)->artifacts,
            'src/Entity/Article.php',
        );

        self::assertStringNotContainsString('owner', $articleContent);
    }

    /**
     * `addslashes()` also escapes a double quote, which single-quoted PHP
     * never unescapes — a label containing one was silently corrupted before
     * this fix (F2). `singleQuoted()` escapes only backslash and single
     * quote, so the literal round-trips exactly through `php -l` and through
     * `var_export` re-parsing.
     */
    #[Test]
    public function aLabelContainingSingleAndDoubleQuotesRoundTripsExactly(): void
    {
        $blueprint = new ApplicationBlueprint(
            contractVersion: 1,
            entities: [
                'quoted' => new BlueprintEntity(
                    id: 'quoted',
                    label: 'Editor\'s "Article"',
                    storage: BlueprintStorage::SqlBlob,
                    revisionable: false,
                    translatable: false,
                    keys: new BlueprintEntityKeys(id: 'id', uuid: 'uuid', label: 'title'),
                    fields: [
                        'title' => new BlueprintField('title', BlueprintFieldType::String, true, 1, false, false, false),
                    ],
                ),
            ],
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [],
            fixtures: [],
            checks: [],
        );
        $manifest = $this->manifest('minimal.yaml');

        $content = $this->content(
            new EntityClassEmitter()->emit($blueprint, $manifest)->artifacts,
            'src/Entity/Quoted.php',
        );

        self::assertStringContainsString('label: \'Editor\\\'s "Article"\'', $content);
        self::assertStringNotContainsString('\\"', $content);
        $this->assertLints($content);

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintQuotedLabel' . bin2hex(random_bytes(4));
        $content = str_replace('namespace App\\Entity;', 'namespace ' . $namespace . ';', $content);
        $reflected = self::evalPhpString($content, $namespace . '\\Quoted');
        $attribute = $reflected->getAttributes(\Waaseyaa\Entity\Attribute\ContentEntityType::class)[0]->newInstance();
        self::assertSame('Editor\'s "Article"', $attribute->label);
    }

    /**
     * F1: `EnumItem::schemaFor()` requires `settings.enum_class`; a `values`
     * setting alone made the golden `status` field crash at runtime with
     * `EnumFieldTypeException::MISSING_ENUM_CLASS`. This loads the emitted
     * entity class AND its generated backed-enum class through the real
     * `EntityMetadataReader` / `EnumItem` runtime (not a snapshot
     * comparison) to prove the fix.
     */
    #[Test]
    public function theEmittedEnumFieldLoadsThroughTheRealEntityAndFieldRuntime(): void
    {
        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintRuntimeLoad' . bin2hex(random_bytes(4));
        $entitySource = str_replace(
            ['namespace App\\Entity;', 'App\\Entity\\Enum\\ArticleStatus'],
            ['namespace ' . $namespace . ';', $namespace . '\\Enum\\ArticleStatus'],
            $this->expected('complete/src/Entity/Article.php'),
        );
        $enumSource = str_replace(
            'namespace App\\Entity\\Enum;',
            'namespace ' . $namespace . '\\Enum;',
            $this->expected('complete/src/Entity/Enum/ArticleStatus.php'),
        );

        $this->assertLints($entitySource);
        $this->assertLints($enumSource);

        $dir = sys_get_temp_dir() . '/waaseyaa_entity_emitter_runtime_' . bin2hex(random_bytes(8));
        mkdir($dir . '/Enum', 0o700, true);
        file_put_contents($dir . '/Enum/ArticleStatus.php', $enumSource);
        file_put_contents($dir . '/Article.php', $entitySource);

        try {
            require $dir . '/Enum/ArticleStatus.php';
            require $dir . '/Article.php';

            $enumClass = $namespace . '\\Enum\\ArticleStatus';
            self::assertTrue(enum_exists($enumClass));
            self::assertSame(
                ['draft', 'final'],
                array_map(static fn(\BackedEnum $case): int|string => $case->value, $enumClass::cases()),
            );

            $fields = EntityMetadataReader::resolveFields($namespace . '\\Article');
            self::assertArrayHasKey('status', $fields);
            $statusDefinition = $fields['status'];
            self::assertSame('enum', $statusDefinition->getType());
            self::assertSame($enumClass, $statusDefinition->getSetting('enum_class'));

            $schema = EnumItem::schemaFor($statusDefinition);
            self::assertSame(['value' => ['type' => 'varchar', 'length' => 255]], $schema);
        } finally {
            new Filesystem()->remove($dir);
        }
    }

    private function assertLints(string $content): void
    {
        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_lint_') . '.php';
        file_put_contents($file, $content);
        try {
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            unlink($file);
        }
    }

    private static function evalPhpString(string $content, string $class): \ReflectionClass
    {
        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_eval_') . '.php';
        file_put_contents($file, $content);
        try {
            require $file;

            return new \ReflectionClass($class);
        } finally {
            unlink($file);
        }
    }

    /** @param list<GeneratedArtifact> $artifacts @return list<string> */
    private function paths(array $artifacts): array
    {
        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $artifacts);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function content(array $artifacts, string $path): string
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact->content;
            }
        }
        self::fail("No artifact at {$path}");
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
