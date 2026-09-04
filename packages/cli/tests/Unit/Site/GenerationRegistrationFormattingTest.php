<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationRegistrationFormattingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_registration_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        new SiteInitializationService($this->root)->initialize($this->site());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function noOpAndGroupOnlyChangesPreserveComposerBytes(): void
    {
        $composer = "{\n    \"extra\": {\n        \"waaseyaa\": {\n            \"providers\": []\n        }\n    }\n}\n";
        $this->writeComposer($composer);
        $service = new SiteInitializationService($this->root);
        $plan = $this->plan([new ComposerProviderRegistration('App\\One', 'content')]);
        $this->publish($service, $plan);
        $afterAdd = (string) file_get_contents($this->root . '/composer.json');
        $this->publish($service, $plan);
        self::assertSame($afterAdd, file_get_contents($this->root . '/composer.json'));

        $groupOnly = $this->plan([new ComposerProviderRegistration('App\\One', 'admin')]);
        $this->publish($service, $groupOnly);
        self::assertSame($afterAdd, file_get_contents($this->root . '/composer.json'));
        self::assertSame('admin', $service->readUnitMetadata()['registrations'][0]['group']);
    }

    #[Test]
    public function changedMergePreservesUnrelatedLexemesAndDuplicateText(): void
    {
        $composer = <<<'JSON'
            {
                "name": "example",
                "description": "\u00e9",
                "big": 900719925474099312345,
                "emptyObject": {},
                "emptyArray": [],
                "unrelated": {"duplicate": 1, "duplicate": 2},
                "extra": {"waaseyaa": {"providers": ["User\\\\Existing"]}}
            }
            JSON;
        $composer = str_replace("\n    ", "\n\t", $composer);
        $this->writeComposer($composer);
        $before = (string) file_get_contents($this->root . '/composer.json');
        $this->publish(new SiteInitializationService($this->root), $this->plan([
            new ComposerProviderRegistration('App\\One'),
            new ComposerProviderRegistration('App\\Two'),
        ]));
        $after = (string) file_get_contents($this->root . '/composer.json');
        self::assertNotSame($before, $after);
        self::assertSame(
            preg_replace('/("providers": )\[[^\]]*\]/', '$1[]', $before),
            preg_replace('/("providers": )\[[^\]]*\]/', '$1[]', $after),
        );
        self::assertStringContainsString('900719925474099312345', $after);
        self::assertStringContainsString('"description": "\u00e9"', $after);
        self::assertStringContainsString('"emptyObject": {}', $after);
        self::assertStringContainsString('"emptyArray": []', $after);
        self::assertSame(2, substr_count($after, '"duplicate"'));
        self::assertStringContainsString('"App\\\\Two"', $after);
    }

    #[Test]
    #[DataProvider('formattingStyles')]
    public function insertedProviderRespectsExistingNewlineAndIndent(string $newline, string $indent): void
    {
        $composer = '{' . $newline . $indent . '"extra": {' . $newline . $indent . $indent . '"waaseyaa": {' . $newline . $indent . $indent . $indent . '"providers": []' . $newline . $indent . $indent . '}' . $newline . $indent . '}' . $newline . '}' . $newline;
        self::assertIsArray(json_decode($composer, true));
        $this->writeComposer($composer);
        $this->publish(new SiteInitializationService($this->root), $this->plan([new ComposerProviderRegistration('App\\Inserted')]));
        $after = (string) file_get_contents($this->root . '/composer.json');
        self::assertStringContainsString($newline, $after);
        self::assertStringNotContainsString("\n", str_replace($newline, '', $after));
        self::assertStringContainsString($indent . $indent . $indent . '"providers": ["App\\\\Inserted"', $after);
    }

    public static function formattingStyles(): iterable
    {
        yield 'crlf-tabs' => ["\r\n", "\t"];
        yield 'lf-spaces' => ["\n", '  '];
    }

    #[Test]
    public function unownedProvidersRetainOrderAndValue(): void
    {
        $composer = '{"extra":{"waaseyaa":{"providers":["User\\\\First", "User\\\\Second"]}}}' . "\n";
        $this->writeComposer($composer);
        $this->publish(new SiteInitializationService($this->root), $this->plan([new ComposerProviderRegistration('App\\Owned')]));
        $after = (string) file_get_contents($this->root . '/composer.json');
        self::assertLessThan(strpos($after, 'User\\\\Second'), strpos($after, 'User\\\\First'));
        self::assertStringContainsString('"User\\\\First"', $after);
        self::assertStringContainsString('"User\\\\Second"', $after);
    }

    #[Test]
    public function missingAncestorIsInsertedAtDeepestExistingObject(): void
    {
        $this->writeComposer("{\n  \"extra\": {}\n}\n");
        $this->publish(new SiteInitializationService($this->root), $this->plan([new ComposerProviderRegistration('App\\Inserted')]));
        $after = (string) file_get_contents($this->root . '/composer.json');
        self::assertStringContainsString('"extra"', $after);
        self::assertStringContainsString('"waaseyaa"', $after);
        self::assertStringContainsString('"providers"', $after);
        self::assertStringContainsString('"App\\\\Inserted"', $after);
    }

    #[Test]
    public function rootRegistrationMetadataIsIndependentOfRendererProjection(): void
    {
        $this->writeComposer("{}\n");
        $site = $this->site();
        $plan = new ArtifactPlan(SiteArtifactRenderer::class, $site->generatorVersion, 'site', GenerationUnitDisposition::Managed, $site->manifestDigest, array_values(array_filter($site->artifacts, static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json')), registrations: [new ComposerProviderRegistration('App\\Root')]);
        $this->publish(new SiteInitializationService($this->root), $plan);
        foreach ($site->artifacts as $path => $artifact) {
            if ($path !== '.waaseyaa/generated.json') {
                self::assertSame($artifact->content, file_get_contents($this->root . '/' . $path));
            }
        }
        self::assertSame([['fqcn' => 'App\\Root']], $this->metadata()['registrations']);
    }

    #[Test]
    public function multilineProviderMergeAndWithdrawalPreserveForeignBytesAndFormatting(): void
    {
        $composer = "{\r\n  \"name\": \"example\",\r\n  \"foreign\": {\"keep\": 900719925474099312345},\r\n  \"extra\": {\r\n    \"waaseyaa\": {\r\n      \"providers\": [\r\n        \"User\\\\Foreign\"\r\n      ],\r\n      \"keep\": \"\\\\u00e9\"\r\n    }\r\n  }\r\n}\r\n";
        $this->writeComposer($composer);
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan([
            new ComposerProviderRegistration('App\\One'),
            new ComposerProviderRegistration('App\\Two'),
        ]));
        $merged = (string) file_get_contents($this->root . '/composer.json');
        self::assertStringContainsString("\r\n", $merged);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $merged));
        self::assertSame(['User\\Foreign', 'App\\One', 'App\\Two'], json_decode($merged, true, flags: JSON_THROW_ON_ERROR)['extra']['waaseyaa']['providers']);
        self::assertStringContainsString('"keep": "\\\\u00e9"', $merged);
        self::assertStringContainsString('"keep": 900719925474099312345', $merged);

        $this->publish($service, $this->plan([new ComposerProviderRegistration('App\\One')]));
        $withdrawn = (string) file_get_contents($this->root . '/composer.json');
        self::assertSame(['User\\Foreign', 'App\\One'], json_decode($withdrawn, true, flags: JSON_THROW_ON_ERROR)['extra']['waaseyaa']['providers']);
        self::assertStringContainsString('"keep": "\\\\u00e9"', $withdrawn);
        self::assertStringContainsString('"keep": 900719925474099312345', $withdrawn);
        self::assertStringNotContainsString('App\\\\Two', $withdrawn);
    }

    #[Test]
    public function missingProvidersInNonemptyWaaseyaaObjectPreserveForeignMembers(): void
    {
        $this->writeComposer("{\n  \"extra\": {\n    \"waaseyaa\": {\n      \"keep\": true\n    }\n  }\n}\n");
        $this->publish(new SiteInitializationService($this->root), $this->plan([new ComposerProviderRegistration('App\\Nested')]));
        $document = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($document['extra']['waaseyaa']['keep']);
        self::assertSame(['App\\Nested'], $document['extra']['waaseyaa']['providers']);
        self::assertSame(1, substr_count((string) file_get_contents($this->root . '/composer.json'), '"providers"'));
    }

    #[Test]
    #[DataProvider('nonemptyAncestorFixtures')]
    public function missingAncestorsAreInsertedWithoutDiscardingForeignMembers(string $composer, array $path): void
    {
        $this->writeComposer($composer);
        $this->publish(new SiteInitializationService($this->root), $this->plan([new ComposerProviderRegistration('App\\Ancestor')]));
        $raw = (string) file_get_contents($this->root . '/composer.json');
        $document = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $cursor = $document;
        foreach ($path as $key) {
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }
        self::assertSame(['App\\Ancestor'], $cursor);
        self::assertSame('keep', $document['foreign']['value']);
        self::assertSame(1, substr_count($raw, '"providers"'));
    }

    public static function nonemptyAncestorFixtures(): iterable
    {
        yield 'missing waaseyaa in nonempty extra pretty' => ["{\n  \"foreign\": {\"value\": \"keep\"},\n  \"extra\": {\"keep\": true}\n}\n", ['extra', 'waaseyaa', 'providers']];
        yield 'missing extra in nonempty root compact' => ["{\"foreign\":{\"value\":\"keep\"},\"name\":\"example\"}\n", ['extra', 'waaseyaa', 'providers']];
    }

    /** @param list<ComposerProviderRegistration> $registrations */
    private function plan(array $registrations): ArtifactPlan
    {
        return new ArtifactPlan('ExampleCompiler', 1, 'scaffold:example', GenerationUnitDisposition::Managed, str_repeat('b', 64), [new GeneratedArtifact('src/Example.php', "<?php // original\n")], registrations: $registrations);
    }

    private function publish(SiteInitializationService $service, ArtifactPlan $plan): void
    {
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($service, 'prepareUnitPlan', $plan);
            $this->invoke($service, 'publish', $prepared['prepared'], $prepared['retirements'], $prepared['composerMerge'] ?? null);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }

    private function writeComposer(string $content): void
    {
        json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/composer.json', $content);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function site(): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - {id: page, canonical_route: '/{slug}'}
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;
        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }
}
