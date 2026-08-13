<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SubscriptionRecipe::class)]
final class SubscriptionRecipeTest extends TestCase
{
    #[Test]
    public function itRendersTheCompletePrivateSubscriptionRecipe(): void
    {
        $site = $this->renderer()->render(new SiteManifestParser()->parse($this->manifest()));

        foreach ([
            'composer.subscription-recipe.json',
            'config/waaseyaa-recipes/subscription.php',
            'migrations/2026_08_13_000001_create_subscriber_table.php',
            'src/Subscription/SubscriberRepository.php',
            'src/Subscription/SubscriptionService.php',
            'src/Subscription/SubscriptionInput.php',
            'src/Subscription/SubscriptionDelivery.php',
            'src/Provider/SubscriptionServiceProvider.php',
            'tests/Acceptance/SubscriptionRecipeTest.php',
        ] as $path) {
            self::assertArrayHasKey($path, $site->artifacts);
        }

        $migration = $site->artifacts['migrations/2026_08_13_000001_create_subscriber_table.php']->content;
        foreach (['extends Migration', 'subscriber', 'normalized_identifier', 'consented_at', 'consent_evidence', 'unsubscribed_at', 'retention_expires_at'] as $required) {
            self::assertStringContainsString($required, $migration);
        }

        $repository = $site->artifacts['src/Subscription/SubscriberRepository.php']->content;
        self::assertStringContainsString('DatabaseInterface', $repository);
        self::assertStringNotContainsString('new PDO', $repository);
        self::assertStringNotContainsString('CREATE TABLE', $repository);

        $service = $site->artifacts['src/Subscription/SubscriptionService.php']->content;
        foreach (['normalizeIdentifier', 'recordConsent', 'unsubscribe', 'export', 'delete', 'purgeExpired'] as $required) {
            self::assertStringContainsString($required, $service);
        }

        $delivery = $site->artifacts['src/Subscription/SubscriptionDelivery.php']->content;
        foreach (['MailerInterface', 'QueueInterface', 'delivery_enabled', 'unsubscribe_proven'] as $required) {
            self::assertStringContainsString($required, $delivery);
        }
        self::assertStringContainsString('false', $site->artifacts['config/waaseyaa-recipes/subscription.php']->content);

        $acceptance = $site->artifacts['tests/Acceptance/SubscriptionRecipeTest.php']->content;
        foreach (['testSubscriberStorageIsPrivate', 'testConsentIsRequiredAndRecorded', 'testUnsubscribePrecedesDelivery', 'testRetentionExportAndDeletionAreAvailable'] as $method) {
            self::assertStringContainsString($method, $acceptance);
        }
        self::assertStringContainsString('tests/Acceptance/SubscriptionRecipeTest.php', $site->artifacts['bin/maintenance/site-verify']->content);
    }

    #[Test]
    public function itRefusesASubstitutedRecipeDigest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subscription recipe digest');

        $this->renderer()->render(new SiteManifestParser()->parse(str_replace(SubscriptionRecipe::digest(), str_repeat('b', 64), $this->manifest())));
    }

    #[Test]
    public function itRefusesAnIncompletePrivacyLifecycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subscriber personal-data lifecycle');

        $this->renderer()->render(new SiteManifestParser()->parse(str_replace('subscriber:delete', 'subscriber:forget', $this->manifest())));
    }

    #[Test]
    public function aSubscriptionRecipePassesTheStrictGeneratedArtifactDoctor(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_subscription_recipe_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        try {
            file_put_contents($root . '/composer.lock', "{}\n");
            $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
            new SiteInitializationService($root)->initialize($this->renderer()->render(new SiteManifestParser()->parse($manifest)));

            $report = new SiteDoctorService()->inspect($root);

            self::assertTrue($report->passed, $report->canonicalJson());
        } finally {
            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($root);
            }
        }
    }

    private function renderer(): SiteArtifactRenderer
    {
        return new SiteArtifactRenderer([new SubscriptionRecipe()]);
    }

    private function manifest(): string
    {
        return sprintf(<<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Example Nation
              id: example-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types: []
            capabilities:
              - id: subscription
                state: active
                package: waaseyaa/database-legacy
                provider: site.subscription
                configuration_authority: .waaseyaa/site.yaml#/capabilities/subscription
                public_routes: [/subscribe, /unsubscribe]
                data_classification: personal
                lifecycle: [consent, export, unsubscribe, delete, retain]
                verification: [tests/Acceptance/SubscriptionRecipeTest.php]
            personal_data_stores:
              - id: subscriber
                classification: personal
                consent_operation: subscriber:consent
                retention: P2Y
                export_operation: subscriber:export
                deletion_operation: subscriber:delete
            recipes:
              - id: subscription
                version: 1
                capability: subscription
                artifact_digest: %s
            verification:
              command: bin/maintenance/site-verify
            YAML, SubscriptionRecipe::digest());
    }
}
