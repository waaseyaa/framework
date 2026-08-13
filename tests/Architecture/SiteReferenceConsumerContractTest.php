<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\SiteContract\SiteManifestParser;

final class SiteReferenceConsumerContractTest extends TestCase
{
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
        self::assertStringContainsString('site:init', $gate);
        self::assertStringContainsString('site:doctor --strict', $gate);
        self::assertStringContainsString('bin/maintenance/site-verify', $gate);

        $site = new SiteManifestParser()->parse($manifest, 'tests/ReferenceConsumer/site.answers.yaml');
        self::assertSame(['announcement', 'event', 'job', 'page', 'update'], array_keys($site->contentTypes));
        self::assertSame(['governed_authoring', 'published_content', 'subscription'], array_keys($site->capabilities));
        self::assertSame(['governed_authoring', 'published_content', 'subscription'], array_keys($site->recipes));
    }
}
