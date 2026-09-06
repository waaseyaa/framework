<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Fail-closed generator-feature negotiation at a generation-execution
 * boundary (ADR-023 D-2, FW-SITE-BLUEPRINT-01D decision (g)).
 *
 * A manifest section such as `application_blueprint` derives a required
 * runtime-negotiation feature token (`SiteManifest::$requiredGeneratorFeatures`)
 * that is never itself authored. This negotiation refuses BEFORE any render,
 * lock, journal or write when the installed generation authority does not
 * advertise every token the manifest requires — closing the silent-ignore
 * path ADR-023 D-2 forbids, where an optional section that no active
 * compiler understands is rendered as if it were absent.
 *
 * Exact token equality only: a near-miss token (e.g. a `-v2` successor) does
 * not satisfy an authored `-v1` requirement, and an installed roster may
 * advertise extra tokens the manifest never asked for without affecting the
 * outcome.
 *
 * The refusal reuses `GEN007_UNSUPPORTED_DECLARATION` rather than minting a
 * new `SITE0xx` id: ADR-025 D-5 already reserves GEN007 for "an unsupported
 * field type or generator-feature token ... for the plan-compilation
 * boundary", and a second id for one refusal would be a dual-source
 * definition of the same concept.
 *
 * This is a pure type over `SiteManifest` and `GenerationRefusalException`
 * with no runtime FQCN knowledge, so it belongs in `site-contract` (Layer 0)
 * rather than beside the compilers that know concrete generator identities
 * (`cli`, Layer 6) — ADR-025 D-3's split.
 *
 * @api
 */
final class GeneratorFeatureNegotiation
{
    /**
     * @param list<string> $advertised the generator-feature tokens the installed
     *   generation authority advertises (e.g.
     *   `SiteArtifactRendererFactory::advertisedGeneratorFeatures()`)
     * @throws GenerationRefusalException when the manifest requires a token
     *   that is not in `$advertised` (exact string equality)
     */
    public static function assert(SiteManifest $manifest, array $advertised, string $source): void
    {
        $missing = array_values(array_diff($manifest->requiredGeneratorFeatures, $advertised));
        if ($missing === []) {
            return;
        }

        throw new GenerationRefusalException($source, [new GenerationViolation(
            GenerationErrorCode::UnsupportedDeclaration,
            sprintf(
                'The manifest requires generator feature(s) not advertised by the installed generation authority: %s. Advertised: %s.',
                implode(', ', $missing),
                $advertised === [] ? '(none)' : implode(', ', $advertised),
            ),
            pointer: '/application_blueprint',
        )]);
    }
}
