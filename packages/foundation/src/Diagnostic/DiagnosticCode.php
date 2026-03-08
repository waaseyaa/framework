<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

/**
 * Canonical operator-facing diagnostic error codes.
 *
 * Each case documents the trigger condition, default human message, and
 * remediation steps. When a code fires, DiagnosticEmitter logs a structured
 * entry so operators can correlate errors across distributed systems.
 */
enum DiagnosticCode: string
{
    case DEFAULT_TYPE_MISSING        = 'DEFAULT_TYPE_MISSING';
    case DEFAULT_TYPE_DISABLED       = 'DEFAULT_TYPE_DISABLED';
    case UNAUTHORIZED_V1_TAG         = 'UNAUTHORIZED_V1_TAG';
    case TAG_QUARANTINE_DETECTED     = 'TAG_QUARANTINE_DETECTED';
    case MANIFEST_VERSIONING_MISSING = 'MANIFEST_VERSIONING_MISSING';
    case NAMESPACE_RESERVED          = 'NAMESPACE_RESERVED';

    public function defaultMessage(): string
    {
        return match ($this) {
            self::DEFAULT_TYPE_MISSING =>
                'No content types are registered. At least one must be available at boot.',
            self::DEFAULT_TYPE_DISABLED =>
                'All registered content types are disabled. At least one must remain enabled.',
            self::UNAUTHORIZED_V1_TAG =>
                'A v1.0 tag was created without the required owner approval sentinel file.',
            self::TAG_QUARANTINE_DETECTED =>
                'Existing unauthorized v1.0 tag(s) detected in the repository.',
            self::MANIFEST_VERSIONING_MISSING =>
                'A defaults manifest is missing the required project_versioning block.',
            self::NAMESPACE_RESERVED =>
                'The "core." namespace is reserved for built-in platform types and cannot be used by extensions or tenants.',
        };
    }

    public function remediation(): string
    {
        return match ($this) {
            self::DEFAULT_TYPE_MISSING =>
                'Enable core.note (`waaseyaa type:enable note`) or register a custom content type via a service provider.',
            self::DEFAULT_TYPE_DISABLED =>
                'Run `waaseyaa type:enable note` to re-enable the default type, or enable any other registered type.',
            self::UNAUTHORIZED_V1_TAG =>
                'Open a release-quarantine issue and notify @jonesrussell. See VERSIONING.md §2 for the approval process.',
            self::TAG_QUARANTINE_DETECTED =>
                'Follow VERSIONING.md §2 to either approve the tag or delete it. Do not proceed with CI until resolved.',
            self::MANIFEST_VERSIONING_MISSING =>
                'Add a project_versioning block to the manifest per VERSIONING.md §3. Run `bin/check-milestones` to verify.',
            self::NAMESPACE_RESERVED =>
                'Use a custom namespace prefix (e.g., myorg.article). The "core." prefix is reserved for platform built-ins.',
        };
    }
}
