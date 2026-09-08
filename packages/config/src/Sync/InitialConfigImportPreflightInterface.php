<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;

/** Narrow signed-envelope gate used by the fresh-project activation composition. @api */
interface InitialConfigImportPreflightInterface
{
    /** @param array<string, ConfigSyncFile> $syncFiles @param list<string> $activeRefs */
    public function assertReadyFromEnvelope(
        SignedConfigManifestEnvelope $envelope,
        array $syncFiles,
        array $activeRefs,
    ): VerifiedConfigBundle;

    /**
     * Revalidate one exact already-committed bundle after an interrupted caller retries.
     *
     * This is deliberately separate from ordinary import verification: it can
     * only accept equality with a caller-supplied committed sequence.
     *
     * @param array<string, ConfigSyncFile> $syncFiles
     * @param list<string> $activeRefs
     */
    public function assertCommittedReplayReadyFromEnvelope(
        SignedConfigManifestEnvelope $envelope,
        int $committedBundleSequence,
        array $syncFiles,
        array $activeRefs,
    ): VerifiedConfigBundle;
}
