<?php

declare(strict_types=1);

/**
 * Reviewed production runtime-policy authorities.
 *
 * Keys are "repository-relative path:detector". A stale entry fails the gate.
 *
 * @return array<string, string>
 */
return [
    'packages/mail/src/MailConfiguration.php:development-classifier' => 'Mail transport names include local and array; this validates a delivery adapter, never APP_ENV, debug mode or production classification. MailConfiguration tests prove explicit selection and rejection.',
    'packages/database-legacy/src/SqliteTopology.php:development-classifier' => 'Low-layer SQLite path policy cannot depend on Foundation without creating a package cycle; exact allowlist parity is Architecture-tested.',
    'packages/foundation/src/Kernel/RuntimePolicy.php:app-debug-source' => 'Canonical bootstrap debug-policy resolver.',
    'packages/foundation/src/Kernel/RuntimePolicy.php:development-classifier' => 'Canonical normalized environment classifier for Foundation-dependent packages.',
];
