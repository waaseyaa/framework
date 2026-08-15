<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;

/**
 * Registered purpose consumer that keeps Authorization construction inside the
 * guarded request operation instead of returning credential bytes to callers.
 *
 * @template-covariant T
 * @implements SecretConsumerInterface<T>
 * @internal
 */
final class McpCredentialOperation implements SecretConsumerInterface
{
    /** @var \Closure(string, string): T */
    private readonly \Closure $operation;

    /** @param \Closure(string, string): T $operation */
    public function __construct(\Closure $operation)
    {
        $this->operation = $operation;
    }

    public static function id(): string
    {
        return 'waaseyaa.ai-agent.mcp-authorization.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::IntegrationCredential;
    }

    public static function purpose(): string
    {
        return McpServersConfig::AUTHORIZATION_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): mixed
    {
        return ($this->operation)($bytes, $version);
    }
}
