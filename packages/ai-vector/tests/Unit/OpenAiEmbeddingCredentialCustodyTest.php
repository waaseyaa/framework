<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\OpenAiEmbeddingCredentialOperation;
use Waaseyaa\AI\Vector\OpenAiEmbeddingProvider;
use Waaseyaa\AI\Vector\ProviderCredentialConfigurationException;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretHandle;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversNothing]
final class OpenAiEmbeddingCredentialCustodyTest extends TestCase
{
    #[Test]
    public function credential_free_transport_spy_receives_no_header_or_handle(): void
    {
        $arguments = [];
        $provider = new OpenAiEmbeddingProvider(
            apiKey: 'CFG04-EMBEDDING-SPY-CANARY',
            transport: static function (string $url, array $payload) use (&$arguments): array {
                $arguments = func_get_args();

                return ['data' => [['embedding' => [1.0, 2.0]]]];
            },
        );

        self::assertSame([1.0, 2.0], $provider->embed('hello'));
        self::assertCount(2, $arguments);
        self::assertArrayNotHasKey('Authorization', $arguments[1]);
        self::assertStringNotContainsString('CFG04-EMBEDDING-SPY-CANARY', serialize($arguments));
    }

    #[Test]
    public function embedding_requests_resolve_one_fresh_version_and_inject_below_the_spy_seam(): void
    {
        $secretProvider = new RotatingEmbeddingCredentialProvider();
        $registry = $this->registry($secretProvider);
        $headers = [];
        $provider = new OpenAiEmbeddingProvider(
            apiKey: SecretHandle::fromReference(
                $registry,
                $this->reference(),
                'waaseyaa/ai-vector',
                [OpenAiEmbeddingCredentialOperation::class],
            ),
            authenticatedTransport: static function (string $url, array $requestHeaders, array $payload) use (&$headers): array {
                $headers[] = $requestHeaders;

                return ['data' => [['embedding' => [1.0, 2.0]]]];
            },
        );

        $provider->embed('first');
        $provider->embed('second');

        self::assertSame(2, $secretProvider->resolveCount);
        self::assertSame('Bearer CFG04-EMBEDDING-v1', $headers[0]['Authorization']);
        self::assertSame('Bearer CFG04-EMBEDDING-v2', $headers[1]['Authorization']);
    }

    #[Test]
    public function state_and_transport_exceptions_never_disclose_the_key(): void
    {
        $canary = 'CFG04-EMBEDDING-EXCEPTION-CANARY';
        $provider = new OpenAiEmbeddingProvider(
            apiKey: $canary,
            authenticatedTransport: static function (string $url, array $headers, array $payload): never {
                throw new \RuntimeException('transport echoed ' . $headers['Authorization']);
            },
        );

        self::assertStringNotContainsString($canary, print_r($provider, true));
        self::assertStringNotContainsString($canary, var_export($provider, true));
        $parameter = new \ReflectionParameter([OpenAiEmbeddingProvider::class, '__construct'], 'apiKey');
        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));

        try {
            $provider->embed('hello');
            self::fail('Credential-bearing transport failures must be replaced.');
        } catch (SecretConsumptionException $exception) {
            self::assertStringNotContainsString($canary, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    #[Test]
    public function raw_openai_configuration_is_refused_without_reading_environment_fallbacks(): void
    {
        try {
            EmbeddingProviderFactory::fromConfig([
                'ai' => [
                    'embedding_provider' => 'openai',
                    'openai_api_key' => 'CFG04-RAW-CONFIG-CANARY',
                ],
            ]);
            self::fail('Raw provider credentials in ordinary configuration must be refused.');
        } catch (ProviderCredentialConfigurationException $exception) {
            self::assertStringNotContainsString('CFG04-RAW-CONFIG-CANARY', $exception->getMessage());
        }
    }

    #[Test]
    public function empty_default_reference_is_refused_with_the_typed_configuration_exception(): void
    {
        try {
            EmbeddingProviderFactory::fromConfig([
                'ai' => [
                    'embedding_provider' => 'openai',
                    'openai_credential_reference' => [
                        'provider' => '',
                        'identifier' => '',
                        'secret_class' => 'provider-credential',
                        'purpose' => OpenAiEmbeddingProvider::CREDENTIAL_PURPOSE,
                    ],
                ],
            ], $this->registry(new RotatingEmbeddingCredentialProvider()));
            self::fail('Empty default references must fail through the typed provider configuration boundary.');
        } catch (ProviderCredentialConfigurationException $exception) {
            self::assertSame(
                '[PROVIDER_CREDENTIAL_CONFIGURATION_REFUSED] OpenAI embedding credential reference fields are invalid.',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());
        }
    }

    private function registry(SecretProviderInterface $provider): SecretResolverRegistry
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider($provider);
        $registry->allow(
            'synthetic-vault',
            'waaseyaa/ai-vector',
            SecretClass::ProviderCredential,
            OpenAiEmbeddingProvider::CREDENTIAL_PURPOSE,
            ['testing'],
        );
        $registry->registerConsumer('waaseyaa/ai-vector', OpenAiEmbeddingCredentialOperation::class);
        $registry->freeze();

        return $registry;
    }

    private function reference(): SecretReference
    {
        return SecretReference::create(
            'synthetic-vault',
            'tenant/openai/embedding',
            SecretClass::ProviderCredential,
            OpenAiEmbeddingProvider::CREDENTIAL_PURPOSE,
        );
    }
}

final class RotatingEmbeddingCredentialProvider implements SecretProviderInterface
{
    public int $resolveCount = 0;

    public function id(): string
    {
        return 'synthetic-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        ++$this->resolveCount;

        return SensitiveValue::fromBytes(
            'CFG04-EMBEDDING-v' . $this->resolveCount,
            SecretClass::ProviderCredential,
            'synthetic-v' . $this->resolveCount,
        );
    }
}
