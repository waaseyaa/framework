<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Waaseyaa\Database\DatabaseIdentityProviderInterface;

/** Joint-owner inventory, transition, verification, and rollback seam. @api */
interface ApplicationMasterRekeyAdapterInterface extends DatabaseIdentityProviderInterface
{
    public function id(): string;

    /** @return list<string> */
    public function purposeIds(): array;

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot;

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult;

    /** @return array<string, ApplicationMasterPurposeVerification> */
    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array;

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult;
}
