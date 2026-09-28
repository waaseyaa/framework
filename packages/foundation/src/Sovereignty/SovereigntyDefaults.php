<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Sovereignty;

/**
 * Profile-specific defaults for sovereignty-related subsystems.
 *
 * SelfHosted intentionally mirrors Local except queue_backend today. Vector
 * storage names the implemented portable database backend; it does not imply
 * a native vector extension.
 */
final class SovereigntyDefaults
{
    /** @var array<string, array<string, string>> */
    private const DEFAULTS = [
        'local' => [
            'storage' => 'filesystem',
            'embeddings' => 'database',
            'llm_provider' => 'ollama',
            'transcriber' => 'whisper_ollama',
            'vector_store' => 'database',
            'queue_backend' => 'sync',
        ],
        'self_hosted' => [
            'storage' => 'filesystem',
            'embeddings' => 'database',
            'llm_provider' => 'ollama',
            'transcriber' => 'whisper_ollama',
            'vector_store' => 'database',
            'queue_backend' => 'database',
        ],
        'northops' => [
            'storage' => 's3',
            'embeddings' => 'database',
            'llm_provider' => 'api',
            'transcriber' => 'api',
            'vector_store' => 'database',
            'queue_backend' => 'redis',
        ],
    ];

    /** @return array<string, string> */
    public static function for(SovereigntyProfile $profile): array
    {
        return self::DEFAULTS[$profile->value];
    }
}
