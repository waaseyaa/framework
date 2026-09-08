<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Stable machine codes for `ai:verify` findings.
 *
 * @api
 */
enum VerifyFindingCode: string
{
    case ManifestMissing = 'manifest_missing';
    case ManifestUnreadable = 'manifest_unreadable';
    case ManifestMalformed = 'manifest_malformed';
    case ManifestUnsupportedSchema = 'manifest_unsupported_schema';
    case ManifestDuplicatePath = 'manifest_duplicate_path';
    case ManifestInvalidDigest = 'manifest_invalid_digest';
    case ManifestUnsafePath = 'manifest_unsafe_path';
    case ManifestTargetEscapes = 'manifest_target_escapes';
    case TargetMissing = 'target_missing';
    case TargetUnreadable = 'target_unreadable';
    case TargetOversize = 'target_oversize';
    case TargetWholefileDrift = 'target_wholefile_drift';
    case TargetManagedRegionDrift = 'target_managed_region_drift';
    case TargetManagedRegionUnprovable = 'target_managed_region_unprovable';
    case TargetRetiredPresent = 'target_retired_present';
    case TargetUnrecorded = 'target_unrecorded';
    case UnknownClient = 'unknown_client';
    case ClientNotInstalled = 'client_not_installed';
    case NoRecordedInstallation = 'no_recorded_installation';
    case SkillSourceFailure = 'skill_source_failure';
}
