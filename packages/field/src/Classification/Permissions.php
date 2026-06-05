<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

/**
 * Classification-related permission and role identifiers.
 *
 * Declared as constants (not as an enum) so they can be referenced from
 * route registrations, audit attributes, and policy code without coercion.
 *
 * Permissions:
 *   - {@see LEGAL_HOLD_BYPASS} — grants read access to entities carrying a
 *     `hold-*` classification label. Per C-004, hold semantics override
 *     every other access decision; this permission is the only escape
 *     hatch. The bundled `admin` role does NOT carry it — only a dedicated
 *     `legal-hold-bypass` role (typically assigned to a small number of
 *     legal-counsel users) should grant it.
 *
 * Roles:
 *   - {@see ROLE_LEGAL_HOLD_BYPASS} — carries the `legal-hold-bypass`
 *     permission; nothing else does by default.
 *   - {@see ROLE_GOVERNANCE_VIEWER} — read-only access to the
 *     `RetentionPolicy` entities served from `/api/classification/policies`;
 *     enables audit/legal staff to inspect retention configuration without
 *     mutation rights.
 *
 * @api
 */
final class Permissions
{
    public const string LEGAL_HOLD_BYPASS = 'legal-hold-bypass';

    public const string ROLE_LEGAL_HOLD_BYPASS = 'legal-hold-bypass';
    public const string ROLE_GOVERNANCE_VIEWER = 'governance-viewer';

    private function __construct() {}
}
