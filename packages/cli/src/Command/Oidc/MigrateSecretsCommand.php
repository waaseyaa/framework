<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Oidc;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Oidc\Security\LegacyOidcSecretMigrator;

/** @api */
final class MigrateSecretsCommand
{
    public function __construct(private readonly LegacyOidcSecretMigrator $migrator) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if (!(bool) $io->option('confirm')) {
            $io->error('oidc:migrate-secrets requires --confirm after verifying a trusted backup.');

            return 1;
        }

        try {
            $counts = $this->migrator->migrate();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf(
            'Encrypted %d signing key(s), %d access token(s), and %d refresh token(s).',
            $counts['signing_keys'],
            $counts['access_tokens'],
            $counts['refresh_tokens'],
        ));

        return 0;
    }
}
