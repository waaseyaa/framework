<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;

/** @template-covariant T @implements SecretConsumerInterface<T> @internal */
final class AnthropicCredentialOperation implements SecretConsumerInterface
{
    public const string PURPOSE = 'waaseyaa.ai.anthropic.v1';

    /** @var \Closure(array<string, string>, string): T */
    private readonly \Closure $operation;

    /** @param \Closure(array<string, string>, string): T $operation */
    public function __construct(\Closure $operation)
    {
        $this->operation = $operation;
    }

    public static function id(): string
    {
        return 'waaseyaa.ai-agent.anthropic-request.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ProviderCredential;
    }

    public static function purpose(): string
    {
        return self::PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): mixed
    {
        return ($this->operation)([
            'Content-Type' => 'application/json',
            'x-api-key' => $bytes,
            'anthropic-version' => '2023-06-01',
        ], $version);
    }
}
