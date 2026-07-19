<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Process-local authoritative entity read-layout generation. @api */
final class EntityReadLayoutGeneration
{
    private int $generation = 1;

    /** @var (\Closure(): string)|null */
    private ?\Closure $semanticFingerprintProvider;

    private ?string $semanticFingerprint = null;

    /** @param (\Closure(): string)|null $semanticFingerprintProvider */
    public function __construct(?\Closure $semanticFingerprintProvider = null)
    {
        $this->replaceSemanticFingerprintProvider($semanticFingerprintProvider);
    }

    public function current(): int
    {
        $this->synchronizeSemanticFingerprint(advanceOnChange: true);

        return $this->generation;
    }

    /** Registration is a process boundary; previously sealed entities become unreadable. */
    public function advance(): int
    {
        $this->synchronizeSemanticFingerprint(advanceOnChange: false);

        return ++$this->generation;
    }

    /** Replace the registry-owned drift probe without resetting generation identity. @internal */
    public function replaceSemanticFingerprintProvider(?\Closure $semanticFingerprintProvider): void
    {
        $this->semanticFingerprintProvider = $semanticFingerprintProvider;
        $this->semanticFingerprint = $semanticFingerprintProvider !== null
            ? $semanticFingerprintProvider()
            : null;
    }

    private function synchronizeSemanticFingerprint(bool $advanceOnChange): void
    {
        if ($this->semanticFingerprintProvider === null) {
            return;
        }

        $current = ($this->semanticFingerprintProvider)();
        if ($current === $this->semanticFingerprint) {
            return;
        }

        $this->semanticFingerprint = $current;
        if ($advanceOnChange) {
            ++$this->generation;
        }
    }
}
