<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Recipe;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\SiteRecipeRendererInterface;
use Waaseyaa\SiteContract\PersonalDataStore;
use Waaseyaa\SiteContract\SiteManifest;

final class SubscriptionRecipe implements SiteRecipeRendererInterface
{
    public const int VERSION = 1;

    /** @return list<GeneratedArtifact> */
    public function render(SiteManifest $manifest): array
    {
        $selection = $manifest->recipes['subscription'] ?? null;
        if ($selection === null) {
            return [];
        }
        if ($selection->version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported subscription recipe version.');
        }
        if (!hash_equals(self::digest(), $selection->artifactDigest)) {
            throw new \InvalidArgumentException('The subscription recipe digest does not match the installed first-party recipe.');
        }
        if ($selection->capability !== 'subscription') {
            throw new \InvalidArgumentException('The subscription recipe must bind the subscription capability.');
        }

        $store = $manifest->personalDataStores['subscriber'] ?? null;
        if (!$store instanceof PersonalDataStore
            || $store->classification !== 'personal'
            || $store->consentOperation !== 'subscriber:consent'
            || $store->exportOperation !== 'subscriber:export'
            || $store->deletionOperation !== 'subscriber:delete'
        ) {
            throw new \InvalidArgumentException('The subscriber personal-data lifecycle must declare consent, export, and deletion operations.');
        }
        try {
            new \DateInterval($store->retention);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('The subscriber retention period must be a valid ISO-8601 duration.', previous: $exception);
        }

        return [
            new GeneratedArtifact('composer.subscription-recipe.json', $this->composerFragment()),
            new GeneratedArtifact('config/waaseyaa-recipes/subscription.php', $this->configuration($store)),
            new GeneratedArtifact('migrations/2026_08_13_000001_create_subscriber_table.php', $this->migration()),
            new GeneratedArtifact('src/Subscription/SubscriberRecord.php', $this->record()),
            new GeneratedArtifact('src/Subscription/SubscriberRepositoryInterface.php', $this->repositoryInterface()),
            new GeneratedArtifact('src/Subscription/SubscriberRepository.php', $this->repository()),
            new GeneratedArtifact('src/Subscription/SubscriptionInput.php', $this->input()),
            new GeneratedArtifact('src/Subscription/SubscriptionResult.php', $this->result()),
            new GeneratedArtifact('src/Subscription/SubscriptionService.php', $this->service()),
            new GeneratedArtifact('src/Subscription/SubscriptionDelivery.php', $this->delivery()),
            new GeneratedArtifact('src/Provider/SubscriptionServiceProvider.php', $this->provider()),
            new GeneratedArtifact('tests/Acceptance/SubscriptionRecipeTest.php', $this->acceptanceTest()),
        ];
    }

    public function id(): string
    {
        return 'subscription';
    }

    public static function digest(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'id' => 'subscription',
            'version' => self::VERSION,
            'store' => 'subscriber',
            'classification' => 'personal',
            'identifier' => 'normalized email',
            'lifecycle' => ['consent', 'export', 'unsubscribe', 'delete', 'retain'],
            'delivery_default' => 'disabled',
            'authorities' => ['database', 'migration', 'validation', 'mail', 'queue', 'container'],
        ]));
    }

    private function composerFragment(): string
    {
        return CanonicalJson::encode([
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\SubscriptionServiceProvider'],
                ],
            ],
        ]) . "\n";
    }

    private function configuration(PersonalDataStore $store): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'table' => 'subscriber',
            'classification' => $store->classification,
            'consent_operation' => $store->consentOperation,
            'retention' => $store->retention,
            'export_operation' => $store->exportOperation,
            'deletion_operation' => $store->deletionOperation,
            'delivery_enabled' => false,
            'unsubscribe_proven' => true,
            'delivery_mode' => 'queue',
        ], true) . ";\n";
    }

    private function migration(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Waaseyaa\Foundation\Migration\Migration;
            use Waaseyaa\Foundation\Migration\SchemaBuilder;

            return new class extends Migration {
                public function up(SchemaBuilder $schema): void
                {
                    $schema->getConnection()->executeStatement(<<<'SQL'
                        CREATE TABLE subscriber (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            normalized_identifier VARCHAR(320) NOT NULL UNIQUE,
                            consented_at VARCHAR(40) NOT NULL,
                            consent_evidence TEXT NOT NULL,
                            unsubscribe_token_hash VARCHAR(64) NOT NULL UNIQUE,
                            unsubscribed_at VARCHAR(40),
                            retention_expires_at VARCHAR(40) NOT NULL,
                            created_at VARCHAR(40) NOT NULL,
                            updated_at VARCHAR(40) NOT NULL
                        )
                        SQL);
                    $schema->getConnection()->executeStatement(
                        'CREATE INDEX idx_subscriber_retention ON subscriber (retention_expires_at)',
                    );
                }

                public function down(SchemaBuilder $schema): void
                {
                    $schema->dropIfExists('subscriber');
                }
            };
            PHP;
    }

    private function record(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            final readonly class SubscriberRecord
            {
                /** @param array<string, scalar|null> $consentEvidence */
                public function __construct(
                    public int $id,
                    public string $normalizedIdentifier,
                    public string $consentedAt,
                    public array $consentEvidence,
                    public ?string $unsubscribedAt,
                    public string $retentionExpiresAt,
                ) {}

                public function isSubscribed(): bool
                {
                    return $this->unsubscribedAt === null;
                }

                /** @return array<string, mixed> */
                public function export(): array
                {
                    return [
                        'id' => $this->id,
                        'identifier' => $this->normalizedIdentifier,
                        'consented_at' => $this->consentedAt,
                        'consent_evidence' => $this->consentEvidence,
                        'unsubscribed_at' => $this->unsubscribedAt,
                        'retention_expires_at' => $this->retentionExpiresAt,
                    ];
                }
            }
            PHP;
    }

    private function repositoryInterface(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            interface SubscriberRepositoryInterface
            {
                /** @param array<string, scalar|null> $consentEvidence */
                public function recordConsent(
                    string $normalizedIdentifier,
                    string $consentedAt,
                    array $consentEvidence,
                    string $unsubscribeTokenHash,
                    string $retentionExpiresAt,
                ): SubscriberRecord;

                public function findByIdentifier(string $normalizedIdentifier): ?SubscriberRecord;

                public function unsubscribe(string $unsubscribeTokenHash, string $unsubscribedAt): bool;

                /** @return array<string, mixed>|null */
                public function export(string $normalizedIdentifier): ?array;

                public function delete(string $normalizedIdentifier): bool;

                public function purgeExpired(string $cutoff): int;
            }
            PHP;
    }

    private function repository(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            use Waaseyaa\Database\DatabaseInterface;

            final readonly class SubscriberRepository implements SubscriberRepositoryInterface
            {
                public function __construct(private DatabaseInterface $database) {}

                public function recordConsent(
                    string $normalizedIdentifier,
                    string $consentedAt,
                    array $consentEvidence,
                    string $unsubscribeTokenHash,
                    string $retentionExpiresAt,
                ): SubscriberRecord {
                    $fields = [
                        'normalized_identifier' => $normalizedIdentifier,
                        'consented_at' => $consentedAt,
                        'consent_evidence' => json_encode($consentEvidence, JSON_THROW_ON_ERROR),
                        'unsubscribe_token_hash' => $unsubscribeTokenHash,
                        'unsubscribed_at' => null,
                        'retention_expires_at' => $retentionExpiresAt,
                        'updated_at' => $consentedAt,
                    ];
                    $existing = $this->findByIdentifier($normalizedIdentifier);
                    if ($existing === null) {
                        $fields['created_at'] = $consentedAt;
                        $this->database->insert('subscriber')->fields(array_keys($fields))->values($fields)->execute();
                    } else {
                        $this->database->update('subscriber')
                            ->fields($fields)
                            ->condition('normalized_identifier', $normalizedIdentifier)
                            ->execute();
                    }

                    return $this->findByIdentifier($normalizedIdentifier)
                        ?? throw new \RuntimeException('Subscriber consent was not persisted.');
                }

                public function findByIdentifier(string $normalizedIdentifier): ?SubscriberRecord
                {
                    $query = $this->database->select('subscriber', 's')
                        ->fields('s')
                        ->condition('normalized_identifier', $normalizedIdentifier)
                        ->range(0, 1);
                    foreach ($query->execute() as $row) {
                        return $this->hydrate((array) $row);
                    }

                    return null;
                }

                public function unsubscribe(string $unsubscribeTokenHash, string $unsubscribedAt): bool
                {
                    return $this->database->update('subscriber')
                        ->fields(['unsubscribed_at' => $unsubscribedAt, 'updated_at' => $unsubscribedAt])
                        ->condition('unsubscribe_token_hash', $unsubscribeTokenHash)
                        ->condition('unsubscribed_at', null, 'IS NULL')
                        ->execute() === 1;
                }

                public function export(string $normalizedIdentifier): ?array
                {
                    return $this->findByIdentifier($normalizedIdentifier)?->export();
                }

                public function delete(string $normalizedIdentifier): bool
                {
                    return $this->database->delete('subscriber')
                        ->condition('normalized_identifier', $normalizedIdentifier)
                        ->execute() === 1;
                }

                public function purgeExpired(string $cutoff): int
                {
                    return $this->database->delete('subscriber')
                        ->condition('retention_expires_at', $cutoff, '<=')
                        ->execute();
                }

                /** @param array<string, mixed> $row */
                private function hydrate(array $row): SubscriberRecord
                {
                    $evidence = json_decode((string) $row['consent_evidence'], true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($evidence)) {
                        throw new \UnexpectedValueException('Subscriber consent evidence must decode to an object.');
                    }

                    return new SubscriberRecord(
                        (int) $row['id'],
                        (string) $row['normalized_identifier'],
                        (string) $row['consented_at'],
                        $evidence,
                        isset($row['unsubscribed_at']) ? (string) $row['unsubscribed_at'] : null,
                        (string) $row['retention_expires_at'],
                    );
                }
            }
            PHP;
    }

    private function input(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            final readonly class SubscriptionInput
            {
                public string $normalizedIdentifier;

                public function __construct(
                    string $identifier,
                    public bool $consent,
                    public string $source,
                    public string $policyVersion,
                ) {
                    $this->normalizedIdentifier = self::normalizeIdentifier($identifier);
                    if (!$this->consent) {
                        throw new \InvalidArgumentException('Explicit subscription consent is required.');
                    }
                    if (trim($this->source) === '' || trim($this->policyVersion) === '') {
                        throw new \InvalidArgumentException('Consent source and policy version are required.');
                    }
                }

                public static function normalizeIdentifier(string $identifier): string
                {
                    $normalized = strtolower(trim($identifier));
                    if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || strlen($normalized) > 320) {
                        throw new \InvalidArgumentException('A valid email address is required.');
                    }

                    return $normalized;
                }
            }
            PHP;
    }

    private function result(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            final readonly class SubscriptionResult
            {
                public function __construct(
                    public SubscriberRecord $subscriber,
                    public string $unsubscribeToken,
                ) {}
            }
            PHP;
    }

    private function service(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            final readonly class SubscriptionService
            {
                public function __construct(
                    private SubscriberRepositoryInterface $repository,
                    private \DateInterval $retention,
                ) {}

                public function recordConsent(SubscriptionInput $input, ?\DateTimeImmutable $now = null): SubscriptionResult
                {
                    $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $unsubscribeToken = bin2hex(random_bytes(32));
                    $record = $this->repository->recordConsent(
                        $input->normalizedIdentifier,
                        $now->format(DATE_ATOM),
                        [
                            'operation' => 'subscriber:consent',
                            'source' => $input->source,
                            'policy_version' => $input->policyVersion,
                            'recorded_at' => $now->format(DATE_ATOM),
                        ],
                        hash('sha256', $unsubscribeToken),
                        $now->add($this->retention)->format(DATE_ATOM),
                    );

                    return new SubscriptionResult($record, $unsubscribeToken);
                }

                public function unsubscribe(string $token, ?\DateTimeImmutable $now = null): bool
                {
                    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
                        return false;
                    }
                    $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                    return $this->repository->unsubscribe(hash('sha256', $token), $now->format(DATE_ATOM));
                }

                /** @return array<string, mixed>|null */
                public function export(string $identifier): ?array
                {
                    return $this->repository->export(SubscriptionInput::normalizeIdentifier($identifier));
                }

                public function delete(string $identifier): bool
                {
                    return $this->repository->delete(SubscriptionInput::normalizeIdentifier($identifier));
                }

                public function purgeExpired(?\DateTimeImmutable $now = null): int
                {
                    $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                    return $this->repository->purgeExpired($now->format(DATE_ATOM));
                }
            }
            PHP;
    }

    private function delivery(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Subscription;

            use Waaseyaa\Mail\Envelope;
            use Waaseyaa\Mail\MailerInterface;
            use Waaseyaa\Queue\Message\GenericMessage;
            use Waaseyaa\Queue\QueueInterface;

            final readonly class SubscriptionDelivery
            {
                public function __construct(
                    private MailerInterface $mailer,
                    private QueueInterface $queue,
                    private bool $deliveryEnabled,
                    private bool $unsubscribeProven,
                    private string $mode,
                ) {}

                public function deliver(SubscriberRecord $subscriber, Envelope $envelope): void
                {
                    if (!$this->deliveryEnabled || !$this->unsubscribeProven) {
                        throw new \LogicException('Subscription delivery remains disabled until unsubscribe is proven.');
                    }
                    if (!$subscriber->isSubscribed()) {
                        throw new \LogicException('Delivery to an unsubscribed recipient is forbidden.');
                    }
                    if ($envelope->to !== [$subscriber->normalizedIdentifier]) {
                        throw new \LogicException('The delivery recipient must match the subscribed identifier.');
                    }
                    if ($this->mode === 'queue') {
                        $this->queue->dispatch(new GenericMessage('subscription.mail', [
                            'subscriber_id' => $subscriber->id,
                            'to' => $envelope->to,
                            'from' => $envelope->from,
                            'subject' => $envelope->subject,
                            'text_body' => $envelope->textBody,
                            'html_body' => $envelope->htmlBody,
                            'headers' => $envelope->headers,
                        ]));

                        return;
                    }
                    if ($this->mode === 'sync') {
                        $this->mailer->send($envelope);

                        return;
                    }

                    throw new \LogicException('Unknown subscription delivery mode.');
                }
            }
            PHP;
    }

    private function provider(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Provider;

            use App\Subscription\SubscriberRepository;
            use App\Subscription\SubscriberRepositoryInterface;
            use App\Subscription\SubscriptionDelivery;
            use App\Subscription\SubscriptionService;
            use Waaseyaa\Database\DatabaseInterface;
            use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
            use Waaseyaa\Mail\MailerInterface;
            use Waaseyaa\Queue\QueueInterface;

            final class SubscriptionServiceProvider extends ServiceProvider
            {
                public function register(): void
                {
                    $config = require dirname(__DIR__, 2) . '/config/waaseyaa-recipes/subscription.php';
                    $this->singleton(SubscriberRepositoryInterface::class, fn(): SubscriberRepositoryInterface => new SubscriberRepository(
                        $this->resolve(DatabaseInterface::class),
                    ));
                    $this->singleton(SubscriptionService::class, fn(): SubscriptionService => new SubscriptionService(
                        $this->resolve(SubscriberRepositoryInterface::class),
                        new \DateInterval((string) $config['retention']),
                    ));
                    $this->singleton(SubscriptionDelivery::class, fn(): SubscriptionDelivery => new SubscriptionDelivery(
                        $this->resolve(MailerInterface::class),
                        $this->resolve(QueueInterface::class),
                        (bool) $config['delivery_enabled'],
                        (bool) $config['unsubscribe_proven'],
                        (string) $config['delivery_mode'],
                    ));
                }
            }
            PHP;
    }

    private function acceptanceTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Subscription\SubscriptionInput;
            use PHPUnit\Framework\TestCase;

            final class SubscriptionRecipeTest extends TestCase
            {
                public function testSubscriberStorageIsPrivate(): void
                {
                    $root = dirname(__DIR__, 2);
                    $migration = (string) file_get_contents($root . '/migrations/2026_08_13_000001_create_subscriber_table.php');
                    $provider = (string) file_get_contents($root . '/src/Provider/SubscriptionServiceProvider.php');
                    self::assertStringContainsString('CREATE TABLE subscriber', $migration);
                    self::assertStringNotContainsString('RouteBuilder', $provider);
                    self::assertStringNotContainsString('_public', $provider);
                }

                public function testConsentIsRequiredAndRecorded(): void
                {
                    $input = new SubscriptionInput(' Editor@Example.org ', true, 'footer-form', 'privacy-v1');
                    self::assertSame('editor@example.org', $input->normalizedIdentifier);
                    $this->expectException(\InvalidArgumentException::class);
                    new SubscriptionInput('editor@example.org', false, 'footer-form', 'privacy-v1');
                }

                public function testUnsubscribePrecedesDelivery(): void
                {
                    $config = require dirname(__DIR__, 2) . '/config/waaseyaa-recipes/subscription.php';
                    self::assertTrue($config['unsubscribe_proven']);
                    self::assertFalse($config['delivery_enabled']);
                }

                public function testRetentionExportAndDeletionAreAvailable(): void
                {
                    $service = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Subscription/SubscriptionService.php');
                    foreach (['export(', 'delete(', 'purgeExpired('] as $operation) {
                        self::assertStringContainsString($operation, $service);
                    }
                }
            }
            PHP;
    }
}
