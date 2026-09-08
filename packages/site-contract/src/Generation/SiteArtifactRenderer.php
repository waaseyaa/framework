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
    private const string METADATA_PATH = '.waaseyaa/generated.json';

    /** @var array<string, SiteRecipeRendererInterface> */
    private array $recipeRenderers = [];

    /** @param iterable<SiteRecipeRendererInterface> $recipeRenderers */
    public function __construct(iterable $recipeRenderers = [])
    {
        foreach ($recipeRenderers as $renderer) {
            $id = $renderer->id();
            if ($id === '' || isset($this->recipeRenderers[$id])) {
                throw new \InvalidArgumentException("Duplicate or empty site recipe renderer identity: {$id}");
            }
            $this->recipeRenderers[$id] = $renderer;
        }
    }

    public function render(SiteManifest $manifest): GeneratedSite
    {
        foreach (array_keys($manifest->recipes) as $recipeId) {
            if (!isset($this->recipeRenderers[$recipeId])) {
                throw new \InvalidArgumentException("Unsupported first-party recipe: {$recipeId}");
            }
        }
        $manifestYaml = new SiteManifestParser()->render($manifest);
        $recipeTests = [];
        $recipeArtifacts = [];
        foreach ($manifest->recipes as $recipeId => $_selection) {
            foreach ($this->recipeRenderers[$recipeId]->render($manifest) as $artifact) {
                $recipeArtifacts[] = $artifact;
                if (str_starts_with($artifact->path, 'tests/Acceptance/') && str_ends_with($artifact->path, 'Test.php')) {
                    $recipeTests[] = $artifact->path;
                }
            }
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
        foreach ($recipeArtifacts as $artifact) {
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
        $artifacts[] = new GeneratedArtifact(self::METADATA_PATH, $metadata);

        return new GeneratedSite($manifest->generatorVersion, $manifest->digest, $artifacts);
    }

    /**
     * The root-unit plan the execution authority publishes (ADR-025 D-15.2).
     *
     * Identical artifact bytes to {@see self::render()} minus the ownership
     * document, which the transaction authority composes (D-2.6), plus every
     * wired recipe's fixed provider registration — the repair for the recipe
     * activation defect FW-RECIPE-ACTIVATION-AUTHORITY-01 describes: a
     * recipe's Composer fragment file remains a generated compatibility
     * artifact, but `PackageManifestCompiler` discovers providers only from
     * literal root `composer.json`, so the fragment is not provider-discovery
     * authority. The provider must instead enter this plan and, through it,
     * literal root `composer.json` (D-6.6).
     */
    public function compile(SiteManifest $manifest): ArtifactPlan
    {
        $rendered = $this->render($manifest);
        $artifacts = array_values(array_filter(
            $rendered->artifacts,
            static fn(GeneratedArtifact $artifact): bool => $artifact->path !== self::METADATA_PATH,
        ));

        $registrations = [];
        foreach ($this->recipeRenderers as $renderer) {
            if (!$renderer instanceof SiteRecipeProviderRegistrationInterface) {
                continue;
            }
            foreach ($renderer->providerRegistrations($manifest) as $registration) {
                $registrations[] = $registration;
            }
        }
        usort($registrations, self::compareRegistrations(...));

        return new ArtifactPlan(
            self::class,
            $manifest->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $manifest->digest,
            $artifacts,
            registrations: $registrations,
            setEvolution: ArtifactSetEvolution::Additive,
        );
    }

    /**
     * Same ordering `ArtifactPlan::compareRegistrations()` enforces (`null`
     * group first, then string groups by `strcmp`) — replicated here rather
     * than exposed from `ArtifactPlan` because that comparator is a private
     * implementation detail of the plan's own constructor invariant.
     */
    private static function compareRegistrations(
        ComposerProviderRegistration $left,
        ComposerProviderRegistration $right,
    ): int {
        $byFqcn = strcmp($left->fqcn, $right->fqcn);
        if ($byFqcn !== 0) {
            return $byFqcn;
        }
        if ($left->group === $right->group) {
            return 0;
        }
        if ($left->group === null) {
            return -1;
        }
        if ($right->group === null) {
            return 1;
        }

        return strcmp($left->group, $right->group);
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
                    $command = $root . '/' . $manifest->verificationCommand;
                    self::assertFileExists($command);
                    self::assertStringStartsWith('#!/usr/bin/env php', (string) file_get_contents($command));

                    // Two properties, measured two separate ways. A hardened
                    // execution environment can mount the project tree noexec
                    // (e.g. a sandboxed runner's tmpfs): the file is genuinely
                    // mode 0755 and owned by the running user, but
                    // is_executable() / posix_access(X_OK) both report false
                    // because the *mount*, not the inode, denies execution.
                    if (DIRECTORY_SEPARATOR === '/') {
                        // 1. The artifact carries the POSIX permission bits
                        // Waaseyaa promises. fileperms() reads the inode mode
                        // directly, which reports the real bits regardless of
                        // mount flags — so this still fails the day Framework
                        // stops chmod-ing the artifact.
                        $mode = fileperms($command);
                        self::assertNotFalse($mode, 'unable to stat the generated verification command');
                        self::assertSame(0111, $mode & 0111, 'the generated verification command must carry execute permission bits');
                    }

                    // 2. The command actually runs through the one invocation
                    // every caller uses — `PHP_BINARY <script>` (see
                    // .ci/site-verify.php, the composer.json `site-verify`
                    // script, and the .ci/site-verify exec wrapper). PHP
                    // interprets the file's bytes directly; it never asks the
                    // filesystem for permission to execute it, so this holds
                    // on a noexec mount. A shebang-prefix check alone proves
                    // nothing about whether the file actually runs — invoke it.
                    $invocation = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($command) . ' --self-test';
                    exec($invocation . ' 2>&1', $selfTestOutput, $selfTestExitCode);
                    self::assertSame(
                        0,
                        $selfTestExitCode,
                        "the generated verification command did not run through `PHP_BINARY <script>`:\n" . implode("\n", $selfTestOutput),
                    );
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

            // `--self-test` is a fast, side-effect-free exit that proves this
            // file runs through `PHP_BINARY <script>` — the one invocation
            // every caller uses — without recursing into the full
            // doctor+test pipeline below, which itself runs the very
            // acceptance test that spawns this flag (see
            // SiteArtifactRenderer::acceptanceTest()).
            if (($argv[1] ?? null) === '--self-test') {
                fwrite(STDOUT, "site-verify: self-test ok\n");
                exit(0);
            }

            $root = dirname(__DIR__, 2);
            if (!chdir($root)) {
                fwrite(STDERR, "site-verify: cannot enter the generated project root\n");
                exit(2);
            }
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
