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
];
