<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\SiteContract\SiteManifestSchema;

/** @api */
final class SiteArtifactRenderer
{
    public function render(SiteManifest $manifest): GeneratedSite
    {
        foreach (array_keys($manifest->recipes) as $recipeId) {
            if ($recipeId !== 'published_content') {
                throw new \InvalidArgumentException("Unsupported first-party recipe: {$recipeId}");
            }
        }
        $manifestYaml = new SiteManifestParser()->render($manifest);
        $publishedContentArtifacts = new PublishedContentRecipe()->render($manifest);
        $recipeTests = [];
        if ($publishedContentArtifacts !== []) {
            $recipeTests[] = 'tests/Acceptance/PublishedContentRecipeTest.php';
        }

        $artifacts = [
            new GeneratedArtifact('.waaseyaa/site.yaml', $manifestYaml),
            new GeneratedArtifact('.waaseyaa/site.schema.json', SiteManifestSchema::canonicalJson() . "\n"),
            new GeneratedArtifact('.waaseyaa/.gitignore', $this->controlIgnore()),
            new GeneratedArtifact('AGENTS.md', $this->agents(), extensionRegion: 'local-guidance'),
            new GeneratedArtifact('tests/Architecture/SiteContractTest.php', $this->architectureTest()),
            new GeneratedArtifact('tests/Acceptance/SiteGoldenPathTest.php', $this->acceptanceTest()),
            new GeneratedArtifact('bin/maintenance/site-verify', $this->verificationScript($recipeTests), 0o755),
        ];
        foreach ($publishedContentArtifacts as $artifact) {
            $artifacts[] = $artifact;
        }

        $metadataRows = [];
        foreach ($artifacts as $artifact) {
            $row = [
                'path' => $artifact->path,
                'mode' => sprintf('%04o', $artifact->mode),
                'managed_sha256' => $artifact->managedDigest(),
            ];
            if ($artifact->extensionRegion !== null) {
                $row['extension_region'] = $artifact->extensionRegion;
            }
            $metadataRows[] = $row;
        }
        usort($metadataRows, static fn(array $left, array $right): int => $left['path'] <=> $right['path']);
        $metadata = CanonicalJson::encode([
            'schema' => 'waaseyaa.generated',
            'version' => 1,
            'generator_version' => $manifest->generatorVersion,
            'manifest_digest' => $manifest->digest,
            'artifacts' => $metadataRows,
        ]) . "\n";
        $artifacts[] = new GeneratedArtifact('.waaseyaa/generated.json', $metadata);

        return new GeneratedSite($manifest->generatorVersion, $manifest->digest, $artifacts);
    }

    private function agents(): string
    {
        return <<<'MARKDOWN'
            # Site contract

            This application is governed by `.waaseyaa/site.yaml`.

            Before changing application behavior:

            1. Read the capability manifest and its active, planned, and excluded decisions.
            2. Use the selected first-party Waaseyaa recipes and extension points.
            3. Run `tests/Architecture/SiteContractTest.php`.
            4. Run the strict site diagnostics.
            5. Run `bin/maintenance/site-verify` without network access.

            Generated files are owned by `.waaseyaa/generated.json`. Regeneration refuses edits outside the extension region below.

            <!-- waaseyaa:extension:start local-guidance -->
            <!-- waaseyaa:extension:end local-guidance -->
            MARKDOWN;
    }

    private function controlIgnore(): string
    {
        return <<<'IGNORE'
            site-init.lock
            site-init.transaction.json
            site-init.transaction.json.tmp-*
            site-init-stage-*
            site-init-backup-*
            IGNORE;
    }

    private function architectureTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use PHPUnit\Framework\TestCase;
            use Waaseyaa\SiteContract\SiteManifestParser;
            use Waaseyaa\SiteContract\SiteManifestSchema;

            final class SiteContractTest extends TestCase
            {
                public function testGeneratedSiteContractIsPresentAndValid(): void
                {
                    $root = dirname(__DIR__, 2);
                    $manifest = (string) file_get_contents($root . '/.waaseyaa/site.yaml');
                    self::assertNotSame('', $manifest);
                    self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', new SiteManifestParser()->parse($manifest)->digest);
                    self::assertSame(SiteManifestSchema::canonicalJson(), trim((string) file_get_contents($root . '/.waaseyaa/site.schema.json')));
                }
            }
            PHP;
    }

    private function acceptanceTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use PHPUnit\Framework\TestCase;
            use Waaseyaa\SiteContract\SiteManifestParser;

            final class SiteGoldenPathTest extends TestCase
            {
                public function testProviderNeutralVerificationCommandIsDeclared(): void
                {
                    $root = dirname(__DIR__, 2);
                    $manifest = new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml'));
                    self::assertSame('bin/maintenance/site-verify', $manifest->verificationCommand);
                    self::assertFileIsExecutable($root . '/' . $manifest->verificationCommand);
                }
            }
            PHP;
    }

    /** @param list<string> $recipeTests */
    private function verificationScript(array $recipeTests): string
    {
        $tests = var_export([
            'tests/Architecture/SiteContractTest.php',
            'tests/Acceptance/SiteGoldenPathTest.php',
            ...$recipeTests,
        ], true);

        return str_replace('__TESTS__', $tests, <<<'PHP'
            #!/usr/bin/env php
            <?php

            declare(strict_types=1);

            $root = dirname(__DIR__, 2);
            $runner = $root . '/vendor/bin/phpunit';
            $waaseyaa = $root . '/vendor/bin/waaseyaa';
            if (!is_file($runner)) {
                fwrite(STDERR, "site-verify: dependencies are not installed\n");
                exit(2);
            }
            if (!is_file($waaseyaa)) {
                fwrite(STDERR, "site-verify: Waaseyaa CLI is not installed\n");
                exit(2);
            }
            $doctor = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($waaseyaa)
                . ' site:doctor --strict --format=json --project-root=' . escapeshellarg($root);
            passthru($doctor, $exitCode);
            if ($exitCode !== 0) {
                exit($exitCode);
            }
            $tests = __TESTS__;
            foreach ($tests as $test) {
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($root . '/' . $test) . ' --no-coverage';
                passthru($command, $exitCode);
                if ($exitCode !== 0) {
                    exit($exitCode);
                }
            }
            exit(0);
            PHP);
    }
}
