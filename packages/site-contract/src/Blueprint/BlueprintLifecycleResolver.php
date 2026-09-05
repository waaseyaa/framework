<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\SiteManifest;

/**
 * Resolves blueprint lifecycle from request-scoped decisions and optional
 * durable applied evidence — never from authored YAML (#2785/#2787,
 * ADR-023 D-5).
 *
 * @api
 */
final class BlueprintLifecycleResolver
{
    public function resolve(
        SiteManifest $manifest,
        ?BlueprintDecisionReceipt $receipt,
        ?BlueprintAppliedEvidence $appliedEvidence = null,
    ): BlueprintLifecycle {
        if ($appliedEvidence?->matches($manifest) === true) {
            return BlueprintLifecycle::Applied;
        }

        if ($manifest->applicationBlueprint !== null && $receipt?->matches($manifest) === true) {
            return match ($receipt->decision) {
                BlueprintDecision::Approved => BlueprintLifecycle::Approved,
                BlueprintDecision::Rejected => BlueprintLifecycle::Rejected,
            };
        }

        if ($appliedEvidence !== null) {
            return BlueprintLifecycle::Superseded;
        }

        if ($manifest->applicationBlueprint === null) {
            throw new \LogicException('A blueprint lifecycle cannot be resolved for a manifest without an application_blueprint section.');
        }

        return BlueprintLifecycle::Proposed;
    }
}
