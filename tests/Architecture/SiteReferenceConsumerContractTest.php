<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\SiteContract\SiteManifestParser;

final class SiteReferenceConsumerContractTest extends TestCase
{
    #[Test]
    public function generationStateReadsTheCanonicalLedgerWithoutMutatingIt(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/waaseyaa-generation-state-' . bin2hex(random_bytes(8));
        $databasePath = $fixture . '/storage/waaseyaa.sqlite';
        $generationId = str_repeat('a', 64);

        mkdir(dirname($databasePath), 0o777, true);
        $database = new \SQLite3($databasePath);
        $database->exec('CREATE TABLE waaseyaa_config_generation_v2 (authority_id TEXT NOT NULL, generation_id TEXT NOT NULL)');
        $database->exec('CREATE TABLE waaseyaa_config_activation_v2 (authority_id TEXT NOT NULL, generation_id TEXT NOT NULL, activation_sequence INTEGER NOT NULL)');
        $statement = $database->prepare('INSERT INTO waaseyaa_config_generation_v2 VALUES (:authority, :generation)');
        self::assertNotFalse($statement);
        $statement->bindValue(':authority', 'default', SQLITE3_TEXT);
        $statement->bindValue(':generation', $generationId, SQLITE3_TEXT);
        self::assertNotFalse($statement->execute());
        $statement = $database->prepare('INSERT INTO waaseyaa_config_activation_v2 VALUES (:authority, :generation, :sequence)');
        self::assertNotFalse($statement);
        $statement->bindValue(':authority', 'default', SQLITE3_TEXT);
        $statement->bindValue(':generation', $generationId, SQLITE3_TEXT);
        $statement->bindValue(':sequence', 1, SQLITE3_INTEGER);
        self::assertNotFalse($statement->execute());
        $database->close();

        try {
            $before = hash_file('sha256', $databasePath);
            $command = sprintf(
                '%s %s generation-state %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root . '/tests/ReferenceConsumer/prepare.php'),
                escapeshellarg($root),
                escapeshellarg($fixture),
            );
            exec($command, $output, $exitCode);

            self::assertSame(0, $exitCode, implode("\n", $output));
            self::assertSame($before, hash_file('sha256', $databasePath));
            self::assertSame([
                'generation_count' => 1,
                'activation_count' => 1,
                'generations' => [[
                    'authority_id' => 'default',
                    'generation_id' => $generationId,
                ]],
                'activations' => [[
                    'authority_id' => 'default',
                    'generation_id' => $generationId,
                    'activation_sequence' => 1,
                ]],
            ], json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            new Filesystem()->remove($fixture);
        }
    }

    #[Test]
    public function theCandidatePathRepositoriesHaveExplicitBranchIndependentVersions(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/waaseyaa-reference-versions-' . bin2hex(random_bytes(8));
        $framework = $fixture . '/framework';
        $consumer = $fixture . '/consumer';

        mkdir($framework . '/packages/example', 0o777, true);
        mkdir($consumer, 0o777, true);
        file_put_contents($framework . '/composer.json', json_encode(['name' => 'waaseyaa/framework'], JSON_THROW_ON_ERROR));
        file_put_contents($framework . '/packages/example/composer.json', json_encode(['name' => 'waaseyaa/example'], JSON_THROW_ON_ERROR));
        file_put_contents($consumer . '/composer.json', json_encode([
            'name' => 'waaseyaa/consumer',
            'require' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $command = sprintf(
                '%s %s configure %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($root . '/tests/ReferenceConsumer/prepare.php'),
                escapeshellarg($framework),
                escapeshellarg($consumer),
            );
            exec($command, $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));

            $composer = json_decode((string) file_get_contents($consumer . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(
                ['waaseyaa/framework' => 'dev-main'],
                $composer['repositories'][0]['options']['versions'],
            );
            self::assertSame(
                ['waaseyaa/example' => 'dev-main'],
                $composer['repositories'][1]['options']['versions'],
            );
        } finally {
            new Filesystem()->remove($fixture);
        }
    }

    #[Test]
    public function theSkeletonAndReferenceConsumerUseOneProviderNeutralVerificationBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $localAdapter = $root . '/skeleton/.ci/site-verify';
        $hostedAdapter = $root . '/skeleton/.github/workflows/site-verify.yml';
        $frameworkAdapter = $root . '/.github/workflows/ci.yml';
        $referenceGate = $root . '/tests/ReferenceConsumer/check-reference-consumer';
        $referencePreparation = $root . '/tests/ReferenceConsumer/prepare.php';
        $answers = $root . '/tests/ReferenceConsumer/site.answers.yaml';

        self::assertFileExists($localAdapter);
        self::assertTrue(is_executable($localAdapter));
        self::assertFileExists($hostedAdapter);
        self::assertFileExists($referenceGate);
        self::assertTrue(is_executable($referenceGate));
        self::assertFileExists($referencePreparation);
        self::assertFileExists($answers);

        $local = (string) file_get_contents($localAdapter);
        $hosted = (string) file_get_contents($hostedAdapter);
        $gate = (string) file_get_contents($referenceGate);
        $preparation = (string) file_get_contents($referencePreparation);
        $manifest = (string) file_get_contents($answers);

        // #2644: the sh adapter delegates to the portable PHP entry rather than
        // exec'ing the generated command directly. There is one implementation,
        // so the pre-init instruction cannot differ between invocation paths,
        // and native Windows — where Composer cannot execute a shebang script —
        // reaches the same code through `composer site-verify`.
        self::assertSame(<<<'SH'
            #!/usr/bin/env sh
            set -eu

            project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

            exec php "$project_root/.ci/site-verify.php" "$@"
            SH . "\n", $local);

        $portableEntry = $root . '/skeleton/.ci/site-verify.php';
        self::assertFileExists($portableEntry);
        $entry = (string) file_get_contents($portableEntry);
        self::assertStringContainsString('declare(strict_types=1);', $entry);
        self::assertStringContainsString('site:init', $entry, 'The pre-init failure must name its own remedy.');
        self::assertStringContainsString('exit(3)', $entry);
        // The entry runs before `composer install` and before the project has a
        // site contract, so it must not depend on either.
        self::assertStringNotContainsString('vendor/autoload.php', $entry);

        $skeletonComposer = json_decode(
            (string) file_get_contents($root . '/skeleton/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($skeletonComposer);
        self::assertSame(
            '@php .ci/site-verify.php',
            $skeletonComposer['scripts']['site-verify'] ?? null,
            'The Composer verification entry must run through Composer\'s own PHP so it works on native Windows.',
        );

        $hostedWorkflow = Yaml::parse($hosted);
        // #2644: the shipped adapter invokes the portable Composer entry, so a
        // consumer whose CI runs on Windows needs no change, and a consumer who
        // pushes before running site:init gets the exit-3 instruction rather
        // than a bare shell "not found".
        self::assertSame(
            ['composer install --no-interaction --prefer-dist', 'composer site-verify'],
            array_values(array_filter(array_column($hostedWorkflow['jobs']['verify']['steps'], 'run'))),
        );
        self::assertSame(
            ['actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683', 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240'],
            array_values(array_filter(array_column($hostedWorkflow['jobs']['verify']['steps'], 'uses'))),
        );

        $frameworkWorkflow = Yaml::parseFile($frameworkAdapter);
        $windowsGate = implode("\n", array_values(array_filter(
            array_column($frameworkWorkflow['jobs']['skeleton-create-project-windows']['steps'], 'run'),
        )));
        self::assertSame(
            ['tests/ReferenceConsumer/check-reference-consumer'],
            array_values(array_filter(array_column($frameworkWorkflow['jobs']['site-reference-consumer']['steps'], 'run'))),
        );

        // #2644: the create-project proof is a two-platform matrix. Reducing it
        // to Linux alone would silently restore the state this issue fixed —
        // site:init could not complete on Windows at all, and nothing noticed.
        self::assertArrayHasKey('skeleton-create-project', $frameworkWorkflow['jobs']);
        self::assertArrayHasKey('skeleton-create-project-windows', $frameworkWorkflow['jobs']);

        self::assertStringContainsString('COMPOSER_DISABLE_NETWORK=1', $gate);
        self::assertStringContainsString('framework_source="$work_root/framework-source"', $gate);
        self::assertStringContainsString('candidate_revision=$(git -C "$framework_root" rev-parse HEAD)', $gate);
        self::assertStringContainsString('git -C "$framework_root" archive --format=tar "$candidate_revision"', $gate);
        self::assertStringContainsString('tar -xf - -C "$framework_source"', $gate);
        self::assertStringContainsString('configure "$framework_source" "$consumer_root"', $gate);
        self::assertStringContainsString('find "$framework_source" -type l -print -quit', $gate);
        self::assertStringContainsString('(cd "$consumer_root" && php vendor/bin/waaseyaa', $gate);
        self::assertStringContainsString('site:init', $gate);
        self::assertStringContainsString('site:doctor --strict', $gate);
        self::assertStringContainsString('bin/maintenance/site-verify', $gate);

        // #2664: one installed reference consumer proves the composed command
        // rather than substituting the in-tree stub process fixture. Its
        // interactive refusal reaches the real wizard and cannot continue to
        // install:init; the JSON lifecycle and retry then exercise the same
        // copied candidate packages without another Composer installation.
        self::assertStringContainsString("script -qefc 'php vendor/bin/waaseyaa project:init'", $gate);
        self::assertStringContainsString('timeout --signal=TERM --kill-after=5s 30s', $gate);
        self::assertStringContainsString('What is the public name of this application?', $gate);
        self::assertStringContainsString('Publish this complete generated site contract?', $gate);
        self::assertStringContainsString('assert_project_init_json', $gate);
        self::assertStringContainsString('project:init --answers=site.answers.yaml --project-root="$consumer_root" --yes --json --no-interaction', $gate);
        self::assertStringContainsString('Configuration already initialized', $gate);
        self::assertStringContainsString('generation_before=', $gate);
        self::assertStringContainsString('generation_after=', $gate);
        self::assertStringContainsString('configuration generation changed during idempotent project:init retry', $gate);
        self::assertStringContainsString("\$operation === 'generation-state'", $preparation);
        self::assertStringContainsString('SQLITE3_OPEN_READONLY', $preparation);
        self::assertStringContainsString('waaseyaa_config_generation_v2', $preparation);
        self::assertStringContainsString('waaseyaa_config_activation_v2', $preparation);

        // Native Windows retains the direct site/init boundary that caught
        // restricted-boot database creation, while also executing the composed
        // command through the installed provider and production runner.
        self::assertStringContainsString('project:init --dry-run --answers=site.answers.yaml --project-root=$work --yes --json --no-interaction', $windowsGate);
        self::assertStringContainsString('project:init --answers=site.answers.yaml --project-root=$work --yes --json --no-interaction', $windowsGate);
        $windowsDirectSite = strpos($windowsGate, 'php vendor/bin/waaseyaa site:init --answers=site.answers.yaml --project-root=$work --yes');
        $windowsNoDatabase = strpos($windowsGate, "throw 'site:init created the application database before install:init.'");
        $windowsDirectInstall = strpos($windowsGate, 'php vendor/bin/waaseyaa install:init --no-interaction');
        self::assertNotFalse($windowsDirectSite);
        self::assertNotFalse($windowsNoDatabase);
        self::assertNotFalse($windowsDirectInstall);
        self::assertLessThan($windowsNoDatabase, $windowsDirectSite);
        self::assertLessThan($windowsDirectInstall, $windowsNoDatabase);

        // #2644: the canonical fresh-project lifecycle is site:init then
        // install:init. install:init is the only materialization command that
        // also activates the configuration generation, so a gate that proved
        // db:init plus migrate would be proving an invalid installation.
        self::assertStringContainsString('install:init', $gate);
        self::assertStringNotContainsString('waaseyaa db:init', $gate);
        self::assertStringContainsString('.ci/site-verify.php', $gate, 'The gate must prove the pre-init refusal.');
        self::assertLessThan(
            (int) strpos($gate, 'install:init'),
            (int) strpos($gate, 'site:init'),
            'The reference gate must run site:init before install:init.',
        );

        $site = new SiteManifestParser()->parse($manifest, 'tests/ReferenceConsumer/site.answers.yaml');
        self::assertSame(['announcement', 'event', 'job', 'page', 'update'], array_keys($site->contentTypes));
        self::assertSame(['governed_authoring', 'published_content', 'subscription'], array_keys($site->capabilities));
        self::assertSame(['governed_authoring', 'published_content', 'subscription'], array_keys($site->recipes));
    }
}
