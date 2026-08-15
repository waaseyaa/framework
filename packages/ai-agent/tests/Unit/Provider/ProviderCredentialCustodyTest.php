<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\AnthropicCredentialOperation;
use Waaseyaa\AI\Agent\Provider\AnthropicProvider;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\OpenAiCompatibleCredentialOperation;
use Waaseyaa\AI\Agent\Provider\OpenAiCompatibleProvider;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretHandle;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversNothing]
final class ProviderCredentialCustodyTest extends TestCase
{
    #[Test]
    public function raw_constructor_ingress_is_sensitive_and_never_retained_as_a_string(): void
    {
        $canary = 'CFG04-AI-RAW-CONSTRUCTOR-CANARY';
        $providers = [
            new AnthropicProvider(apiKey: $canary),
            new OpenAiCompatibleProvider(apiKey: $canary),
        ];

        foreach ($providers as $provider) {
            $this->assertViewsDoNotContain($provider, $canary);

            $parameter = new \ReflectionParameter([$provider::class, '__construct'], 'apiKey');
            self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
        }
    }

    #[Test]
    public function anthropic_resolves_and_pins_one_fresh_version_per_request(): void
    {
        $provider = new RotatingAiCredentialProvider('ANTHROPIC');
        $registry = $this->registry($provider, 'waaseyaa.ai.anthropic.v1', AnthropicCredentialOperation::class);
        $headers = [];
        $client = new AnthropicProvider(
            apiKey: SecretHandle::fromReference(
                $registry,
                $this->reference('waaseyaa.ai.anthropic.v1'),
                'waaseyaa/ai-agent',
                [AnthropicCredentialOperation::class],
            ),
            authenticatedTransport: static function (string $url, array $requestHeaders, array $body) use (&$headers): array {
                $headers[] = $requestHeaders;

                return [
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn',
                    'usage' => [],
                ];
            },
        );

        $request = new MessageRequest(messages: [['role' => 'user', 'content' => 'hello']]);
        $client->sendMessage($request);
        $client->sendMessage($request);

        self::assertSame(2, $provider->resolveCount);
        self::assertSame('CFG04-ANTHROPIC-v1', $headers[0]['x-api-key']);
        self::assertSame('CFG04-ANTHROPIC-v2', $headers[1]['x-api-key']);
    }

    #[Test]
    public function openai_compatible_resolves_and_pins_one_fresh_version_per_request(): void
    {
        $provider = new RotatingAiCredentialProvider('OPENAI-CHAT');
        $registry = $this->registry($provider, 'waaseyaa.ai.openai-chat.v1', OpenAiCompatibleCredentialOperation::class);
        $headers = [];
        $client = new OpenAiCompatibleProvider(
            apiKey: SecretHandle::fromReference(
                $registry,
                $this->reference('waaseyaa.ai.openai-chat.v1'),
                'waaseyaa/ai-agent',
                [OpenAiCompatibleCredentialOperation::class],
            ),
            authenticatedTransport: static function (string $url, array $requestHeaders, array $body) use (&$headers): array {
                $headers[] = $requestHeaders;

                return [
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'ok'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => [],
                ];
            },
        );

        $request = new MessageRequest(messages: [['role' => 'user', 'content' => 'hello']]);
        $client->sendMessage($request);
        $client->sendMessage($request);

        self::assertSame(2, $provider->resolveCount);
        self::assertSame('Bearer CFG04-OPENAI-CHAT-v1', $headers[0]['Authorization']);
        self::assertSame('Bearer CFG04-OPENAI-CHAT-v2', $headers[1]['Authorization']);
    }

    #[Test]
    public function credential_bearing_transport_exception_is_replaced_without_a_previous_chain(): void
    {
        $canary = 'CFG04-AI-EXCEPTION-CANARY';
        $client = new AnthropicProvider(
            apiKey: $canary,
            authenticatedTransport: static function (string $url, array $headers, array $body) use ($canary): never {
                throw new \RuntimeException('transport echoed ' . $headers['x-api-key'] . $canary);
            },
        );

        try {
            $client->sendMessage(new MessageRequest(messages: [['role' => 'user', 'content' => 'hello']]));
            self::fail('Credential-bearing transport errors must be replaced.');
        } catch (SecretConsumptionException $exception) {
            self::assertStringNotContainsString($canary, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function registry(
        SecretProviderInterface $provider,
        string $purpose,
        string $consumerClass,
    ): SecretResolverRegistry {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider($provider);
        $registry->allow('synthetic-vault', 'waaseyaa/ai-agent', SecretClass::ProviderCredential, $purpose, ['testing']);
        $registry->registerConsumer('waaseyaa/ai-agent', $consumerClass);
        $registry->freeze();

        return $registry;
    }

    private function reference(string $purpose): SecretReference
    {
        return SecretReference::create(
            'synthetic-vault',
            'tenant/provider/credential',
            SecretClass::ProviderCredential,
            $purpose,
        );
    }

    private function assertViewsDoNotContain(object $provider, string $canary): void
    {
        self::assertStringNotContainsString($canary, print_r($provider, true));
        self::assertStringNotContainsString($canary, var_export($provider, true));

        try {
            $serialized = serialize($provider);
            self::assertStringNotContainsString($canary, $serialized);
        } catch (\LogicException $exception) {
            self::assertStringNotContainsString($canary, $exception->getMessage());
        }

        $clone = clone $provider;
        self::assertStringNotContainsString($canary, print_r($clone, true));
    }
}

final class RotatingAiCredentialProvider implements SecretProviderInterface
{
    public int $resolveCount = 0;

    public function __construct(private readonly string $providerName) {}

    public function id(): string
    {
        return 'synthetic-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        ++$this->resolveCount;

        return SensitiveValue::fromBytes(
            'CFG04-' . $this->providerName . '-v' . $this->resolveCount,
            SecretClass::ProviderCredential,
            'synthetic-v' . $this->resolveCount,
        );
    }
}
