<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Queue\QueueInterface;
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
        foreach (['MailerInterface', 'QueueInterface', 'deliveryEnabled', 'unsubscribeProven'] as $required) {
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
        mkdir($root, 0o777, true);
        try {
            file_put_contents($root . '/composer.lock', "{}\n");
            $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
            new SiteInitializationService($root)->initialize($this->renderer()->render(new SiteManifestParser()->parse($manifest)));

            $report = new SiteDoctorService()->inspect($root);

            self::assertTrue($report->passed, $report->canonicalJson());
        } finally {
            if (is_dir($root)) {
                (new Filesystem())->remove($root);
            }
        }
    }

    #[Test]
    public function itRendersSyntacticallyValidPhp(): void
    {
        $site = $this->renderer()->render(new SiteManifestParser()->parse($this->manifest()));
        $root = sys_get_temp_dir() . '/waaseyaa_subscription_lint_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        try {
            foreach ($site->artifacts as $artifact) {
                if (!str_ends_with($artifact->path, '.php')) {
                    continue;
                }
                $path = $root . '/' . str_replace('/', '_', $artifact->path);
                file_put_contents($path, $artifact->content);
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
                self::assertSame(0, $exitCode, $artifact->path . ': ' . implode("\n", $output));
                $output = [];
            }
        } finally {
            foreach (glob($root . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    #[Test]
    public function generatedConsentLifecycleAndDeliveryGateWorkTogether(): void
    {
        $site = $this->renderer()->render(new SiteManifestParser()->parse($this->manifest()));
        foreach ([
            'src/Subscription/SubscriberRecord.php',
            'src/Subscription/SubscriberRepositoryInterface.php',
            'src/Subscription/SubscriptionInput.php',
            'src/Subscription/SubscriptionResult.php',
            'src/Subscription/SubscriptionService.php',
            'src/Subscription/SubscriptionDelivery.php',
        ] as $path) {
            eval(substr($site->artifacts[$path]->content, 5));
        }

        $repository = new class implements \App\Subscription\SubscriberRepositoryInterface {
            public ?\App\Subscription\SubscriberRecord $record = null;

            public function recordConsent(string $normalizedIdentifier, string $consentedAt, array $consentEvidence, string $unsubscribeTokenHash, string $retentionExpiresAt): \App\Subscription\SubscriberRecord
            {
                return $this->record = new \App\Subscription\SubscriberRecord(
                    1,
                    $normalizedIdentifier,
                    $consentedAt,
                    $consentEvidence,
                    null,
                    $retentionExpiresAt,
                );
            }
            public function findByIdentifier(string $normalizedIdentifier): ?\App\Subscription\SubscriberRecord
            {
                return $this->record;
            }
            public function unsubscribe(string $unsubscribeTokenHash, string $unsubscribedAt): bool
            {
                return true;
            }
            public function export(string $normalizedIdentifier): ?array
            {
                return $this->record?->export();
            }
            public function delete(string $normalizedIdentifier): bool
            {
                return true;
            }
            public function purgeExpired(string $cutoff): int
            {
                return 1;
            }
        };
        $service = new \App\Subscription\SubscriptionService($repository, new \DateInterval('P1Y'));
        $result = $service->recordConsent(
            new \App\Subscription\SubscriptionInput(' Editor@Example.org ', true, 'footer', 'privacy-v1'),
            new \DateTimeImmutable('2026-08-13T12:00:00+00:00'),
        );

        self::assertSame('editor@example.org', $result->subscriber->normalizedIdentifier);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result->unsubscribeToken);
        self::assertTrue($service->unsubscribe($result->unsubscribeToken));

        $mailer = new class implements MailerInterface {
            public function send(Envelope $envelope): void
            {
                throw new \LogicException('Delivery must remain disabled.');
            }
        };
        $queue = new class implements QueueInterface {
            public function dispatch(object $message): void
            {
                throw new \LogicException('Delivery must remain disabled.');
            }
        };
        $delivery = new \App\Subscription\SubscriptionDelivery($mailer, $queue, false, true, 'queue');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('remains disabled');
        $delivery->deliver(
            $result->subscriber,
            new Envelope(['editor@example.org'], 'office@example.org', 'News'),
        );
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
            content_types:
              - id: page
                canonical_route: /{slug}
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
