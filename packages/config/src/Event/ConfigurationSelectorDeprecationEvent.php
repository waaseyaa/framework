<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Event;

/** Immutable evidence that a transitional bootstrap selector was consumed. @api */
final readonly class ConfigurationSelectorDeprecationEvent
{
    public const string NAME = 'configuration.selector.deprecated';

    public function __construct(
        public string $legacySelector,
        public string $canonicalSelector,
        public string $authorityId,
    ) {
        if (!in_array($legacySelector, ['config_dir', 'WAASEYAA_CONFIG_DIR'], true)) {
            throw new \InvalidArgumentException('Configuration selector deprecation requires a recognized legacy selector.');
        }
        if (!in_array($canonicalSelector, ['config.sync_path', 'WAASEYAA_CONFIG_SYNC_PATH'], true)) {
            throw new \InvalidArgumentException('Configuration selector deprecation requires a recognized canonical selector.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $authorityId) !== 1) {
            throw new \InvalidArgumentException('Configuration selector deprecation requires an authority SHA-256 identifier.');
        }
    }
}
