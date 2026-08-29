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
        $answers = $root . '/tests/ReferenceConsumer/site.answers.yaml';

        self::assertFileExists($localAdapter);
        self::assertTrue(is_executable($localAdapter));
        self::assertFileExists($hostedAdapter);
        self::assertFileExists($referenceGate);
        self::assertTrue(is_executable($referenceGate));
        self::assertFileExists($answers);

        $local = (string) file_get_contents($localAdapter);
        $hosted = (string) file_get_contents($hostedAdapter);
        $gate = (string) file_get_contents($referenceGate);
        $manifest = (string) file_get_contents($answers);

        self::assertSame(<<<'SH'
            #!/usr/bin/env sh
            set -eu

            project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

            exec "$project_root/bin/maintenance/site-verify"
            SH . "\n", $local);

        $hostedWorkflow = Yaml::parse($hosted);
        self::assertSame(
            ['composer install --no-interaction --prefer-dist', '.ci/site-verify'],
            array_values(array_filter(array_column($hostedWorkflow['jobs']['verify']['steps'], 'run'))),
        );
        self::assertSame(
            ['actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683', 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240'],
            array_values(array_filter(array_column($hostedWorkflow['jobs']['verify']['steps'], 'uses'))),
        );

        $frameworkWorkflow = Yaml::parseFile($frameworkAdapter);
        self::assertSame(
            ['tests/ReferenceConsumer/check-reference-consumer'],
            array_values(array_filter(array_column($frameworkWorkflow['jobs']['site-reference-consumer']['steps'], 'run'))),
        );

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

        // #2644: the canonical fresh-project lifecycle is site:init then
        // install:init. install:init is the only materialization command that
        // also activates the configuration generation, so a gate that proved
        // db:init plus migrate would be proving an invalid installation.
        self::assertStringContainsString('install:init', $gate);
        self::assertStringNotContainsString('waaseyaa db:init', $gate);
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
