<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationGenesisActivatorInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;

/**
 * The governed installation phase (#2428).
 *
 * A fresh deployment has schema it has not applied and no activated CFG-02
 * generation. Ordinary runtime needs both: access-policy discovery and the
 * PRE_SAVE workflow guard resolve configuration, which reads the active
 * generation. Nothing in the framework ever created that first generation, so a
 * new site could migrate and still neither boot in production nor write in any
 * environment.
 *
 * This command is that missing lifecycle transition. It runs under restricted
 * discovery (see ConsoleKernel::handle()), so it never constructs a runtime
 * consumer that would require the result it is producing, and it exits without
 * entering ordinary runtime boot.
 *
 * It deliberately does NOT read configuration. Its authority is procedural and
 * bounded: it writes and activates the initial generation, after which the
 * ordinary production resolver becomes authoritative. There is no installation
 * read authority and no bypass of requireActiveGenerationId() — the enforcement
 * landed in #2426 is untouched.
 *
 * @api
 */
final readonly class InstallInitHandler
{
    /**
     * @param callable(): void $prepareSchema applies migrations and synchronizes entity schema
     */
    public function __construct(
        private mixed $prepareSchema,
        private ConfigurationActivatorInterface $activator,
        private ConfigurationGenesisActivatorInterface $genesis,
        private ConfigurationAuthorityContext $authority,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $io->writeln('Applying migrations and synchronizing entity schema...');
        ($this->prepareSchema)();

        // A site needs somewhere to receive an authored configuration bundle
        // (#2430). Installation creates the directory and puts nothing in it:
        // strict CFG-03 validation requires every member of a sync directory to
        // be a versioned config sync file, so a placeholder file would make the
        // directory unsignable and unimportable the moment it existed.
        $this->ensureSyncDirectory($io);

        $requestId = self::requestId($this->authority);
        $existing = $this->activator->currentToken();

        if ($existing !== null) {
            // Idempotent: a generation is already active. Re-running install
            // must never mint a competing one.
            $io->writeln(sprintf(
                'Configuration already initialized (generation %s, sequence %d).',
                $existing->generationId,
                $existing->activationSequence,
            ));
            $io->writeln('Installation is complete.');

            return 0;
        }

        $committed = $this->activator->committedResult($requestId);
        if ($committed !== null) {
            // A prior run of THIS request committed, yet nothing is active.
            // That is contradictory partial state; minting a second generation
            // would paper over it, so refuse and let an operator inspect.
            $io->writeln(sprintf(
                'Refusing: installation request %s already committed, but no generation is active.',
                $requestId,
            ));
            $io->writeln('Inspect the configuration generation tables before retrying.');

            return 1;
        }

        $io->writeln('Activating the canonical empty configuration generation...');
        // Genesis claims no CFG-03 verification and can carry no content. Every
        // configuration change after this one goes through the ordinary
        // verified import path.
        $result = $this->genesis->activateGenesis($requestId)->token;

        $io->writeln(sprintf(
            'Activated generation %s (sequence %d).',
            $result->generationId,
            $result->activationSequence,
        ));
        $io->writeln('Installation is complete.');

        return 0;
    }

    private function ensureSyncDirectory(SymfonyCommandIO $io): void
    {
        $syncPath = $this->authority->syncPath;
        if (is_dir($syncPath)) {
            return;
        }
        if (file_exists($syncPath)) {
            $io->writeln(sprintf('Configuration sync path %s exists and is not a directory; leaving it alone.', $syncPath));

            return;
        }
        if (!mkdir($syncPath, 0o755, true) && !is_dir($syncPath)) {
            $io->writeln(sprintf('Could not create the configuration sync directory %s.', $syncPath));

            return;
        }
        $io->writeln(sprintf('Created the configuration sync directory %s.', $syncPath));
    }

    /**
     * Stable per-site identity, so an interrupted run correlates with its own
     * prior attempt through committedResult() instead of starting a new one.
     */
    public static function requestId(ConfigurationAuthorityContext $authority): string
    {
        return 'install-init-' . substr(hash('sha256', 'configuration.install.initial.v1|' . $authority->authorityId), 0, 32);
    }
}
