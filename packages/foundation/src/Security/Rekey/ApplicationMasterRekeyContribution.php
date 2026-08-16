<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;

/** Exact adapter and purpose-policy roster contributed by one active owner. @api */
final readonly class ApplicationMasterRekeyContribution
{
    /** @var list<ApplicationMasterPurposePolicy> */
    private array $policies;

    /**
     * @param list<ApplicationMasterPurposePolicy> $policies
     */
    public function __construct(
        private ApplicationMasterRekeyAdapterInterface $adapter,
        array $policies,
    ) {
        if ($policies === []) {
            throw new ApplicationMasterRekeyConflictException(
                'Application-master rekey contributions must own at least one purpose.',
            );
        }

        $purposeIds = [];
        foreach ($policies as $policy) {
            if ($policy->adapterId !== $adapter->id()) {
                throw new ApplicationMasterRekeyConflictException(
                    'Application-master purpose policies must name their contributing adapter.',
                );
            }
            if (isset($purposeIds[$policy->id])) {
                throw new ApplicationMasterRekeyConflictException(
                    'Application-master rekey contributions cannot repeat purpose IDs.',
                );
            }
            $purposeIds[$policy->id] = true;
        }

        $policyRoster = array_keys($purposeIds);
        $adapterRoster = $adapter->purposeIds();
        sort($policyRoster, SORT_STRING);
        sort($adapterRoster, SORT_STRING);
        if ($policyRoster !== $adapterRoster) {
            throw new ApplicationMasterRekeyConflictException(
                'Application-master adapter and policy purpose rosters must match exactly.',
            );
        }

        usort(
            $policies,
            static fn(ApplicationMasterPurposePolicy $left, ApplicationMasterPurposePolicy $right): int => strcmp($left->id, $right->id),
        );
        $this->policies = $policies;
    }

    public function adapter(): ApplicationMasterRekeyAdapterInterface
    {
        return $this->adapter;
    }

    /** @return list<ApplicationMasterPurposePolicy> */
    public function policies(): array
    {
        return $this->policies;
    }
}
