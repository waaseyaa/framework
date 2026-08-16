<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

/** Closed CFG-04 signing and verification algorithm policy. @api */
final readonly class SigningAlgorithmPolicy
{
    /** @var list<string> */
    private array $allowedAlgorithms;

    /** @param list<string> $allowedAlgorithms */
    public function __construct(array $allowedAlgorithms = ['RS256'])
    {
        if ($allowedAlgorithms === [] || count($allowedAlgorithms) !== count(array_unique($allowedAlgorithms))) {
            throw new \InvalidArgumentException('Signing algorithm policy must be a unique non-empty allowlist.');
        }
        foreach ($allowedAlgorithms as $algorithm) {
            if (preg_match('/^[A-Z0-9-]+$/D', $algorithm) !== 1) {
                throw new \InvalidArgumentException('Signing algorithm policy contains an invalid identifier.');
            }
        }
        $this->allowedAlgorithms = $allowedAlgorithms;
    }

    public function assertAllowed(string $algorithm): void
    {
        if (!in_array($algorithm, $this->allowedAlgorithms, true)) {
            throw new \RuntimeException(sprintf(
                'OIDC signing algorithm "%s" is not allowed by CFG-04 policy.',
                $algorithm,
            ));
        }
    }

    public function isAllowed(string $algorithm): bool
    {
        return in_array($algorithm, $this->allowedAlgorithms, true);
    }
}
