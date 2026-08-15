<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;
use Waaseyaa\Foundation\Security\SecretConsumptionCode;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversClass(SecretResolverRegistry::class)]
#[CoversClass(SensitiveValue::class)]
#[CoversClass(SecretConsumptionException::class)]
final class SecretConsumptionTest extends TestCase
{
    private const string PACKAGE = 'waaseyaa/ai-vector';
    private const string PURPOSE = 'waaseyaa.ai.embedding.v1';

    #[Test]
    public function registered_purpose_consumer_receives_one_resolved_version(): void
    {
        $provider = new RotatingSyntheticProvider('CFG04-CONSUMER-CANARY');
        $registry = $this->registry($provider);
        $registry->registerConsumer(self::PACKAGE, SyntheticEmbeddingConsumer::class);
        $registry->freeze();

        $first = $registry->consume($this->reference(), self::PACKAGE, new SyntheticEmbeddingConsumer());
        $second = $registry->consume($this->reference(), self::PACKAGE, new SyntheticEmbeddingConsumer());

        self::assertSame('synthetic-v1:' . hash('sha256', 'CFG04-CONSUMER-CANARY-v1'), $first);
        self::assertSame('synthetic-v2:' . hash('sha256', 'CFG04-CONSUMER-CANARY-v2'), $second);
        self::assertSame(2, $provider->resolveCount);
    }

    #[Test]
    public function unregistered_consumer_is_denied_before_secret_resolution(): void
    {
        $provider = new RotatingSyntheticProvider('CFG04-UNREGISTERED-CANARY');
        $registry = $this->registry($provider);
        $registry->freeze();

        try {
            $registry->consume($this->reference(), self::PACKAGE, new SyntheticEmbeddingConsumer());
            self::fail('An unregistered consumer must not receive secret bytes.');
        } catch (SecretConsumptionException $exception) {
            self::assertSame(SecretConsumptionCode::ConsumerNotRegistered, $exception->reason);
            self::assertSame(0, $provider->resolveCount);
            self::assertStringNotContainsString('CFG04-UNREGISTERED-CANARY', $exception->getMessage());
        }
    }

    #[Test]
    public function consumer_metadata_must_match_the_reference_before_resolution(): void
    {
        $provider = new RotatingSyntheticProvider('CFG04-WRONG-PURPOSE-CANARY');
        $registry = $this->registry($provider);
        $registry->registerConsumer(self::PACKAGE, WrongPurposeConsumer::class);
        $registry->freeze();

        try {
            $registry->consume($this->reference(), self::PACKAGE, new WrongPurposeConsumer());
            self::fail('A consumer registered for another purpose must be denied.');
        } catch (SecretConsumptionException $exception) {
            self::assertSame(SecretConsumptionCode::ConsumerMismatch, $exception->reason);
            self::assertSame(0, $provider->resolveCount);
        }
    }

    #[Test]
    public function consumer_failure_crosses_the_boundary_as_a_non_secret_code(): void
    {
        $canary = 'CFG04-CONSUMER-FAILURE-CANARY';
        $provider = new RotatingSyntheticProvider($canary);
        $registry = $this->registry($provider);
        $registry->registerConsumer(self::PACKAGE, FailingEmbeddingConsumer::class);
        $registry->freeze();

        try {
            $registry->consume($this->reference(), self::PACKAGE, new FailingEmbeddingConsumer());
            self::fail('Consumer failures must be wrapped at the custody boundary.');
        } catch (SecretConsumptionException $exception) {
            self::assertSame(SecretConsumptionCode::ConsumerFailure, $exception->reason);
            self::assertStringNotContainsString($canary, $exception->getMessage());
            self::assertNull($exception->getPrevious(), 'The secret-bearing exception chain must not escape.');
        }
    }

    private function registry(SecretProviderInterface $provider): SecretResolverRegistry
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider($provider);
        $registry->allow(
            provider: 'synthetic-vault',
            package: self::PACKAGE,
            secretClass: SecretClass::ProviderCredential,
            purpose: self::PURPOSE,
            environments: ['testing'],
        );

        return $registry;
    }

    private function reference(): SecretReference
    {
        return SecretReference::create(
            provider: 'synthetic-vault',
            identifier: 'tenant/example/provider-credential',
            secretClass: SecretClass::ProviderCredential,
            purpose: self::PURPOSE,
        );
    }
}

final class RotatingSyntheticProvider implements SecretProviderInterface
{
    public int $resolveCount = 0;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $prefix,
    ) {}

    public function id(): string
    {
        return 'synthetic-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        ++$this->resolveCount;
        $version = 'synthetic-v' . $this->resolveCount;

        return SensitiveValue::fromBytes(
            $this->prefix . '-v' . $this->resolveCount,
            SecretClass::ProviderCredential,
            $version,
        );
    }
}

final class SyntheticEmbeddingConsumer implements SecretConsumerInterface
{
    public static function id(): string
    {
        return 'waaseyaa.ai-vector.embedding-request.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ProviderCredential;
    }

    public static function purpose(): string
    {
        return 'waaseyaa.ai.embedding.v1';
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): string
    {
        return $version . ':' . hash('sha256', $bytes);
    }
}

final class WrongPurposeConsumer implements SecretConsumerInterface
{
    public static function id(): string
    {
        return 'waaseyaa.ai-vector.chat-request.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ProviderCredential;
    }

    public static function purpose(): string
    {
        return 'waaseyaa.ai.chat.v1';
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): never
    {
        throw new \LogicException('Wrong-purpose consumer must never run.');
    }
}

final class FailingEmbeddingConsumer implements SecretConsumerInterface
{
    public static function id(): string
    {
        return 'waaseyaa.ai-vector.embedding-failure.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ProviderCredential;
    }

    public static function purpose(): string
    {
        return 'waaseyaa.ai.embedding.v1';
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): never
    {
        throw new \RuntimeException('consumer leaked ' . $bytes);
    }
}
