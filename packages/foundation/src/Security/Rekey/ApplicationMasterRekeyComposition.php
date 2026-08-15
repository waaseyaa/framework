<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;

/** Deterministic boot-time composition of active application-master owners. @api */
final readonly class ApplicationMasterRekeyComposition
{
    /**
     * @param list<ApplicationMasterRekeyAdapterInterface> $adapters
     */
    private function __construct(
        private ApplicationMasterPurposeRegistry $purposes,
        private array $adapters,
    ) {}

    /**
     * @param iterable<object> $providers
     */
    public static function fromProviders(DatabaseInterface $database, iterable $providers): ?self
    {
        /** @var array<string, ApplicationMasterRekeyAdapterInterface> $adapters */
        $adapters = [];
        /** @var array<string, ApplicationMasterPurposePolicy> $policies */
        $policies = [];

        foreach ($providers as $provider) {
            if (!$provider instanceof ProvidesApplicationMasterRekeyContributionsInterface) {
                continue;
            }

            foreach ($provider->applicationMasterRekeyContributions() as $candidate) {
                $contribution = self::requireContribution($candidate);

                $adapter = $contribution->adapter();
                if ($adapter->databaseAuthority() !== $database) {
                    throw new ApplicationMasterRekeyConflictException(
                        'Application-master adapters must use the kernel transaction authority.',
                    );
                }
                if (isset($adapters[$adapter->id()])) {
                    throw new ApplicationMasterRekeyConflictException(
                        'Application-master adapter IDs must be unique.',
                    );
                }
                $adapters[$adapter->id()] = $adapter;

                foreach ($contribution->policies() as $policy) {
                    if (isset($policies[$policy->id])) {
                        throw new ApplicationMasterRekeyConflictException(
                            'Application-master purpose IDs must have exactly one active owner.',
                        );
                    }
                    $policies[$policy->id] = $policy;
                }
            }
        }

        if ($adapters === []) {
            return null;
        }

        ksort($adapters, SORT_STRING);
        ksort($policies, SORT_STRING);
        $registry = new ApplicationMasterPurposeRegistry();
        foreach ($policies as $policy) {
            $registry->register($policy);
        }
        $registry->freeze();

        return new self($registry, array_values($adapters));
    }

    public function purposes(): ApplicationMasterPurposeRegistry
    {
        return $this->purposes;
    }

    /** @return list<ApplicationMasterRekeyAdapterInterface> */
    public function adapters(): array
    {
        return $this->adapters;
    }

    private static function requireContribution(mixed $candidate): ApplicationMasterRekeyContribution
    {
        if (!$candidate instanceof ApplicationMasterRekeyContribution) {
            throw new ApplicationMasterRekeyConflictException(
                'Application-master contribution providers returned an invalid contribution.',
            );
        }

        return $candidate;
    }
}
