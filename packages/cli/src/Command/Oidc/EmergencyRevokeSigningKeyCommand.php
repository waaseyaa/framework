<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Oidc;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Oidc\Key\SigningKeyEmergencyRevocationService;

/** Separately confirmed CFG-04 compromise command. @api */
final readonly class EmergencyRevokeSigningKeyCommand
{
    public function __construct(private SigningKeyEmergencyRevocationService $service) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if (!(bool) $io->option('confirm')) {
            $io->error(
                'oidc:emergency-revoke-signing-key requires --confirm; this is not ordinary rotation.',
            );

            return 1;
        }
        $requestId = $this->required($io, 'request-id');
        $kid = $this->required($io, 'kid');
        $actor = $this->required($io, 'actor');
        $reason = $this->required($io, 'reason');
        if ($requestId === null || $kid === null || $actor === null || $reason === null) {
            return 1;
        }

        try {
            $record = $this->service->revoke($requestId, $kid, $actor, $reason);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return 1;
        }

        $io->writeln(sprintf(
            'Emergency revocation request=%s kid=%s version=%d access=%d refresh=%d stateless-window=%d..%d.',
            $record->requestId,
            $record->kid,
            $record->keyVersion,
            $record->persistedAccessAffected,
            $record->persistedRefreshAffected,
            $record->statelessWindowFrom,
            $record->statelessWindowTo,
        ));

        return 0;
    }

    private function required(SymfonyCommandIO $io, string $name): ?string
    {
        $value = $io->option($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $io->error(sprintf('Option --%s is required.', $name));

        return null;
    }
}
