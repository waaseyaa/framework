<?php

declare(strict_types=1);

/**
 * Reviewed non-secret file reads in packages governed by CFG-04.
 *
 * @return array<string, string>
 */
return [
    'packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php:file_get_contents'
        => 'Reads bounded public Markdown specification files under the configured project root; it does not read credential, key, environment, or external secret-store material.',
    'packages/ai-vector/src/OllamaEmbeddingProvider.php:file_get_contents'
        => 'Uses an HTTP stream context for the configured Ollama endpoint; the argument is a URL, not a local credential or key path.',
    'packages/ai-vector/src/OpenAiEmbeddingProvider.php:file_get_contents'
        => 'Uses an HTTP stream context below the registered provider-credential consumer; the argument is the fixed embedding endpoint URL, not a local credential or key path.',
];
