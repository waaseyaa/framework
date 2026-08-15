<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

/** Complete, non-truncating result of strict sync-directory validation. @api */
final readonly class ConfigSyncBundleValidationResult
{
    /**
     * @param list<ValidatedConfigSyncEntry> $entries
     * @param list<ConfigBundleDiagnostic> $diagnostics
     */
    public function __construct(
        public array $entries,
        public array $diagnostics,
    ) {}

    public function isValid(): bool
    {
        return $this->diagnostics === [];
    }

    /** @return list<ValidatedConfigSyncEntry> */
    public function requireValidEntries(): array
    {
        if (!$this->isValid()) {
            throw new \LogicException('A sync bundle with diagnostics cannot become a manifest or activation candidate.');
        }

        return $this->entries;
    }
}
