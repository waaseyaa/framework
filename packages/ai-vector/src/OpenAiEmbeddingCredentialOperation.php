<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;

/** @template-covariant T @implements SecretConsumerInterface<T> @internal */
final class OpenAiEmbeddingCredentialOperation implements SecretConsumerInterface
{
    /** @var \Closure(array<string, string>, string): T */
    private readonly \Closure $operation;

    /** @param \Closure(array<string, string>, string): T $operation */
    public function __construct(\Closure $operation)
    {
        $this->operation = $operation;
    }

    public static function id(): string
    {
        return 'waaseyaa.ai-vector.openai-embedding-request.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ProviderCredential;
    }

    public static function purpose(): string
    {
        return OpenAiEmbeddingProvider::CREDENTIAL_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): mixed
    {
        return ($this->operation)([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $bytes,
        ], $version);
    }
}
