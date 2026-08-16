<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Oidc;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Oidc\Key\SigningKeyRepository;

/** Explicit operator surface for the gated CFG-04 signing lifecycle. @api */
final readonly class SigningKeyLifecycleCommand
{
    public function __construct(private SigningKeyRepository $repository) {}

    public function initialize(SymfonyCommandIO $io): int
    {
        if (!$this->confirmed($io, 'oidc:init-signing-key')) {
            return 1;
        }

        return $this->run($io, function () use ($io): void {
            $key = $this->repository->initialize();
            $io->writeln(sprintf('Initialized active signing key kid=%s version=%d.', $key->kid, $key->version));
        });
    }

    public function stage(SymfonyCommandIO $io): int
    {
        if (!$this->confirmed($io, 'oidc:stage-signing-key')) {
            return 1;
        }

        return $this->run($io, function () use ($io): void {
            $key = $this->repository->stageSuccessor();
            $io->writeln(sprintf('Published staged verify-only key kid=%s version=%d.', $key->kid, $key->version));
        });
    }

    public function recordPropagation(SymfonyCommandIO $io): int
    {
        if (!$this->confirmed($io, 'oidc:record-signing-key-propagation')) {
            return 1;
        }
        $kid = $this->requiredString($io, 'kid');
        $evidenceHash = $this->requiredString($io, 'evidence-hash');
        if ($kid === null || $evidenceHash === null) {
            return 1;
        }

        return $this->run($io, function () use ($io, $kid, $evidenceHash): void {
            $key = $this->repository->recordPropagation($kid, $evidenceHash);
            $io->writeln(sprintf('Recorded propagation evidence for kid=%s version=%d.', $key->kid, $key->version));
        });
    }

    public function activate(SymfonyCommandIO $io): int
    {
        if (!$this->confirmed($io, 'oidc:activate-signing-key')) {
            return 1;
        }
        $kid = $this->requiredString($io, 'kid');
        $expectedVersion = $io->option('expected-active-version');
        if ($kid === null
            || (!is_int($expectedVersion) && !is_string($expectedVersion))
            || preg_match('/^[1-9][0-9]*$/D', (string) $expectedVersion) !== 1) {
            $io->error('Option --expected-active-version must be a positive integer.');

            return 1;
        }

        return $this->run($io, function () use ($io, $kid, $expectedVersion): void {
            $key = $this->repository->activateSuccessor($kid, (int) $expectedVersion);
            $io->writeln(sprintf('Activated signing key kid=%s version=%d.', $key->kid, $key->version));
        });
    }

    public function cleanup(SymfonyCommandIO $io): int
    {
        if (!$this->confirmed($io, 'oidc:cleanup-signing-keys')) {
            return 1;
        }

        return $this->run($io, function () use ($io): void {
            $removed = $this->repository->cleanupExpired();
            $io->writeln(sprintf('Removed %d policy-expired retired signing key(s).', $removed));
        });
    }

    private function confirmed(SymfonyCommandIO $io, string $command): bool
    {
        if ((bool) $io->option('confirm')) {
            return true;
        }
        $io->error($command . ' requires --confirm after reviewing current lifecycle evidence.');

        return false;
    }

    private function requiredString(SymfonyCommandIO $io, string $name): ?string
    {
        $value = $io->option($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $io->error(sprintf('Option --%s is required.', $name));

        return null;
    }

    /** @param \Closure(): void $operation */
    private function run(SymfonyCommandIO $io, \Closure $operation): int
    {
        try {
            $operation();

            return 0;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return 1;
        }
    }
}
