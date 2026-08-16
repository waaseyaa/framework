<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Drift;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Sync\ConfigBundleDiagnostic;

/** Token-bound, sorted, non-mutating CFG-03 drift verdict. @api */
final readonly class ConfigDriftVerificationResult
{
    /**
     * @param list<ConfigBundleDiagnostic> $diagnostics
     * @param array<string, string> $artifactsBefore
     * @param array<string, string> $artifactsAfter
     */
    public function __construct(
        public ConfigurationActiveToken $activeToken,
        public array $diagnostics,
        public array $artifactsBefore,
        public array $artifactsAfter,
    ) {}

    public function isValid(): bool
    {
        return $this->diagnostics === [] && $this->artifactsBefore === $this->artifactsAfter;
    }
}
