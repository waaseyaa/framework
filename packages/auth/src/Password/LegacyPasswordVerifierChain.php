<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

/**
 * The verifiers a deployment accepts, tried in order.
 *
 * An empty chain — the default for a deployment that migrated nothing — accepts
 * nothing, which is the correct posture: legacy verification is opt-in, and a
 * framework that silently understood foreign hash formats would be widening its
 * own authentication surface for every consumer.
 *
 * @api
 */
final readonly class LegacyPasswordVerifierChain implements LegacyPasswordVerifierInterface
{
    /** @var list<LegacyPasswordVerifierInterface> */
    private array $verifiers;

    public function __construct(LegacyPasswordVerifierInterface ...$verifiers)
    {
        $this->verifiers = array_values($verifiers);
    }

    public function name(): string
    {
        return 'chain';
    }

    public function supports(string $legacyHash): bool
    {
        return $this->verifierFor($legacyHash) !== null;
    }

    public function verify(string $password, string $legacyHash): bool
    {
        // Only the verifier that recognizes the format runs. Trying every
        // verifier against every hash would multiply the work an unrecognized
        // value costs, which is the shape a denial of service takes here.
        return $this->verifierFor($legacyHash)?->verify($password, $legacyHash) ?? false;
    }

    /**
     * The format name a stored credential is in, for operator diagnostics, or
     * null when nothing recognizes it.
     *
     * Names the FORMAT only. It is derived from the first three characters of
     * a value that must never be logged, and returns no part of it.
     */
    public function formatName(string $legacyHash): ?string
    {
        return $this->verifierFor($legacyHash)?->name();
    }

    private function verifierFor(string $legacyHash): ?LegacyPasswordVerifierInterface
    {
        if ($legacyHash === '') {
            return null;
        }
        foreach ($this->verifiers as $verifier) {
            if ($verifier->supports($legacyHash)) {
                return $verifier;
            }
        }

        return null;
    }
}
