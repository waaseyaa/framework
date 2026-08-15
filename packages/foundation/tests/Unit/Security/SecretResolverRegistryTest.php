<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolutionCode;
use Waaseyaa\Foundation\Security\SecretResolutionException;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversClass(SecretResolverRegistry::class)]
#[CoversClass(SecretResolutionException::class)]
final class SecretResolverRegistryTest extends TestCase
{
    #[Test]
    public function resolves_only_after_freeze_and_registers_bytes_before_return(): void
    {
        $canary = 'CFG04-SYNTHETIC-RESOLVER-CANARY';
        $sanitizer = new RedactorProcessor();
        $registry = new SecretResolverRegistry($sanitizer, 'testing');
        $registry->registerProvider($this->provider('synthetic-vault', $canary, SecretClass::ProviderCredential));
        $registry->allow(
            provider: 'synthetic-vault',
            package: 'waaseyaa/ai-vector',
            secretClass: SecretClass::ProviderCredential,
            purpose: 'waaseyaa.ai.embedding.v1',
            environments: ['testing'],
        );
        $registry->freeze();

        $value = $registry->resolve($this->reference(), 'waaseyaa/ai-vector');
        $record = $sanitizer->process(new LogRecord(LogLevel::INFO, 'resolved=' . $canary));

        $this->assertSame(SecretClass::ProviderCredential, $value->secretClass);
        $this->assertSame('resolved=[REDACTED]', $record->message);

        $property = new \ReflectionProperty(RedactorProcessor::class, 'registeredSensitiveRepresentations');
        $registries = $property->getValue();
        $this->assertInstanceOf(\WeakMap::class, $registries);
        $liveValues = $registries[$sanitizer];
        $this->assertCount(1, $liveValues);

        unset($value);
        gc_collect_cycles();

        $this->assertCount(0, $liveValues);
    }

    #[Test]
    public function resolution_before_freeze_fails_closed(): void
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');

        $this->expectResolutionCode(SecretResolutionCode::RegistryNotFrozen);

        $registry->resolve($this->reference(), 'waaseyaa/ai-vector');
    }

    #[Test]
    public function canonicalizes_environment_identity_before_policy_matching(): void
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), ' TESTING ');
        $registry->registerProvider($this->provider('synthetic-vault', 'synthetic-value', SecretClass::ProviderCredential));
        $registry->allow('synthetic-vault', 'waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', ['testing']);
        $registry->freeze();

        $this->assertInstanceOf(SensitiveValue::class, $registry->resolve($this->reference(), 'waaseyaa/ai-vector'));
    }

    #[Test]
    public function missing_provider_fails_closed_without_identifier_disclosure(): void
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->allow('synthetic-vault', 'waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', ['testing']);
        $registry->freeze();

        try {
            $registry->resolve($this->reference(), 'waaseyaa/ai-vector');
            self::fail('Missing provider must fail closed.');
        } catch (SecretResolutionException $exception) {
            $this->assertSame(SecretResolutionCode::ProviderUnknown, $exception->reason);
            $this->assertStringNotContainsString('tenant/example/private/provider/path', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-vault', $exception->getMessage());
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('deniedPolicyInputs')]
    public function wrong_package_purpose_class_or_environment_fails_closed(
        string $package,
        SecretClass $class,
        string $purpose,
        string $environment,
    ): void {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), $environment);
        $registry->registerProvider($this->provider('synthetic-vault', 'synthetic-value', SecretClass::ProviderCredential));
        $registry->allow('synthetic-vault', 'waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', ['testing']);
        $registry->freeze();

        $this->expectResolutionCode(SecretResolutionCode::PolicyDenied);

        $registry->resolve(SecretReference::create(
            'synthetic-vault',
            'tenant/example/private/provider/path',
            $class,
            $purpose,
        ), $package);
    }

    /** @return iterable<string, array{string, SecretClass, string, string}> */
    public static function deniedPolicyInputs(): iterable
    {
        yield 'package' => ['waaseyaa/ai-agent', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', 'testing'];
        yield 'purpose' => ['waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.chat.v1', 'testing'];
        yield 'class' => ['waaseyaa/ai-vector', SecretClass::IntegrationCredential, 'waaseyaa.ai.embedding.v1', 'testing'];
        yield 'environment' => ['waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', 'production'];
    }

    #[Test]
    public function provider_failure_is_wrapped_without_provider_error_text(): void
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider(new class implements SecretProviderInterface {
            public function id(): string
            {
                return 'synthetic-vault';
            }

            public function resolve(SecretReference $reference): SensitiveValue
            {
                throw new \RuntimeException('provider path and CFG04-SECRET-CANARY');
            }
        });
        $registry->allow('synthetic-vault', 'waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', ['testing']);
        $registry->freeze();

        try {
            $registry->resolve($this->reference(), 'waaseyaa/ai-vector');
            self::fail('Provider failure must be wrapped.');
        } catch (SecretResolutionException $exception) {
            $this->assertSame(SecretResolutionCode::ProviderFailure, $exception->reason);
            $this->assertStringNotContainsString('provider path', $exception->getMessage());
            $this->assertStringNotContainsString('CFG04-SECRET-CANARY', $exception->getMessage());
        }
    }

    #[Test]
    public function provider_returning_the_wrong_class_fails_closed(): void
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider($this->provider('synthetic-vault', 'synthetic-value', SecretClass::IntegrationCredential));
        $registry->allow('synthetic-vault', 'waaseyaa/ai-vector', SecretClass::ProviderCredential, 'waaseyaa.ai.embedding.v1', ['testing']);
        $registry->freeze();

        $this->expectResolutionCode(SecretResolutionCode::ClassMismatch);

        $registry->resolve($this->reference(), 'waaseyaa/ai-vector');
    }

    private function reference(): SecretReference
    {
        return SecretReference::create(
            'synthetic-vault',
            'tenant/example/private/provider/path',
            SecretClass::ProviderCredential,
            'waaseyaa.ai.embedding.v1',
        );
    }

    private function provider(string $id, string $bytes, SecretClass $secretClass): SecretProviderInterface
    {
        return new class ($id, $bytes, $secretClass) implements SecretProviderInterface {
            public function __construct(
                private readonly string $providerId,
                #[\SensitiveParameter]
                private readonly string $bytes,
                private readonly SecretClass $secretClass,
            ) {}

            public function id(): string
            {
                return $this->providerId;
            }

            public function resolve(SecretReference $reference): SensitiveValue
            {
                return SensitiveValue::fromBytes($this->bytes, $this->secretClass, 'synthetic-v1');
            }
        };
    }

    private function expectResolutionCode(SecretResolutionCode $code): void
    {
        $this->expectException(SecretResolutionException::class);
        $this->expectExceptionMessage('[' . $code->value . ']');
    }
}
