<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiteReferenceConsumerContractTest extends TestCase
{
    #[Test]
    public function theSkeletonAndReferenceConsumerUseOneProviderNeutralVerificationBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $localAdapter = $root . '/skeleton/.ci/site-verify';
        $hostedAdapter = $root . '/skeleton/.github/workflows/site-verify.yml';
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

        self::assertStringContainsString('bin/maintenance/site-verify', $local);
        self::assertStringContainsString('.ci/site-verify', $hosted);
        self::assertStringNotContainsString('github.com', $local);
        self::assertStringNotContainsString('gh ', $local);

        self::assertStringContainsString('COMPOSER_DISABLE_NETWORK=1', $gate);
        self::assertStringContainsString('site:init', $gate);
        self::assertStringContainsString('site:doctor --strict', $gate);
        self::assertStringContainsString('bin/maintenance/site-verify', $gate);
        self::assertStringContainsString('offline regeneration is not byte-identical', $gate);
        self::assertStringContainsString('framework upgrade continuity', $gate);
        self::assertStringContainsString('provider-neutral adapter', $gate);
        self::assertStringNotContainsString('gh ', $gate);
        self::assertStringNotContainsString('api.github.com', $gate);

        foreach (['page', 'update', 'event', 'job', 'announcement'] as $contentType) {
            self::assertMatchesRegularExpression('/id:\s+' . preg_quote($contentType, '/') . '\b/', $manifest);
        }
        self::assertStringContainsString('id: governed_authoring', $manifest);
        self::assertStringContainsString('id: published_content', $manifest);
        self::assertStringContainsString('id: subscription', $manifest);
    }
}
