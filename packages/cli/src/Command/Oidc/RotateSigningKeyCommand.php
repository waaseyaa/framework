<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Oidc;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Oidc\Key\SigningKeyRepository;

/**
 * `bin/waaseyaa oidc:rotate-signing-key` — generate a new RS256 signing
 * keypair and rotate out the current key.
 *
 * The new key becomes current; the prior current is retained as "previous"
 * for in-flight token verification. Keys older than the new previous are pruned.
 *
 * @api
 */
final class RotateSigningKeyCommand
{
    public function __construct(
        private readonly SigningKeyRepository $repository,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $previous = $this->repository->previousKey();
        $priorCurrentKid = null;

        // currentKey() before rotate to find what kid is currently active
        try {
            $priorCurrentKid = $this->repository->currentKey()->kid;
        } catch (\Throwable) {
        }

        $newKey = $this->repository->rotate();

        $io->writeln('New current kid: ' . $newKey->kid);

        if ($priorCurrentKid !== null && $priorCurrentKid !== $newKey->kid) {
            $io->writeln('Rotated out kid: ' . $priorCurrentKid);
        } else {
            $io->writeln('Rotated out kid: (none — first key)');
        }

        return 0;
    }
}
