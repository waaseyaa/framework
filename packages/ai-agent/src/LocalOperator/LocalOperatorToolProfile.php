<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\LocalOperator;

use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

/**
 * The local plane's default tool catalogue: an explicit tool-ID allowlist with
 * capability checks layered underneath it (ADR-022 D-7).
 *
 * **Why an ID allowlist rather than a capability grant (D-7.1).**
 * `AbstractAgentTool::requireCapability()` evaluates a capability *string*
 * against the account and consults no tool roster
 * (`packages/ai-tools/src/AbstractAgentTool.php:216-226`). So "grant
 * `bimaaji.read`" is an open set: any class added later carrying
 * `#[AsAgentTool(capability: 'bimaaji.read')]` would join the default profile
 * the moment it is discovered — with no ADR edit, no review of what it reads,
 * and no signal to the operator. Today `bimaaji.read` happens to admit exactly
 * the three tools below; that coincidence is precisely why the allowlist has to
 * exist now, while it is free.
 *
 * **The two controls are layered, not alternatives (D-7.2).** The allowlist
 * narrows; it never widens. {@see admits()} answers "is this tool on the
 * default list", and a tool that passes it is *still* subject to
 * `requireCapability()` against {@see LocalOperatorPrincipal::hasPermission()},
 * which is itself a strict membership test. A tool on the allowlist whose
 * capability the principal does not hold is refused by the capability check.
 *
 * **The gate (D-7.3).** `bin/check-local-operator-tool-profile` scans every
 * `#[AsAgentTool]` in the repository, classifies each against this class's
 * constants, and diffs the result against
 * `support/local-operator-tool-profile-roster.json`. Adding, removing, or
 * renaming a tool that the default would admit fails CI until the roster is
 * deliberately regenerated with `--write-roster`. The most important class the
 * gate records is `capability-admissible` — a tool whose capability the default
 * grants but whose id is *not* on the allowlist. That set is empty today, and
 * the gate is what makes it impossible for it to grow silently.
 *
 * `bimaaji_search_specs` is on the list and returns nothing in a real consumer
 * install: `BimaajiServiceProvider::resolveSpecsDirectory()` returns `null`
 * unless `bimaaji.specs_directory` is configured, and `docs/specs/` ships in no
 * package. Making it answer is #2661 (lifecycle metadata and a sanitized
 * versioned corpus) then #2662 (cited, version-matched FTS5 search). ADR-022
 * D-7 keeps it listed rather than removing and re-adding it later, because its
 * membership is already reviewed and re-adding it would otherwise read to the
 * gate as a deliberate widening of the default.
 *
 * @api
 */
final class LocalOperatorToolProfile
{
    /**
     * The closed set of tool IDs the default local profile admits (D-7).
     *
     * All three are read-only and structural. The graph sections are schema,
     * not rows: `EntityIntrospectionProvider` iterates
     * `EntityTypeManagerInterface::getDefinitions()` and emits labels, classes,
     * keys, field definitions, and group/revisionable/translatable flags — it
     * touches no storage and returns no stored value.
     *
     * @var list<string>
     */
    public const array DEFAULT_TOOL_IDS = [
        'bimaaji_introspect_graph',
        'bimaaji_introspect_section',
        'bimaaji_search_specs',
    ];

    /**
     * The capabilities the default profile grants (D-7.2's lower layer).
     *
     * @var list<string>
     */
    public const array DEFAULT_CAPABILITIES = ['bimaaji.read'];

    /**
     * Capabilities the default profile withholds, exhaustively per ADR-022 D-7.
     *
     * These are the *capability strings the shipped tools actually declare*,
     * which is what a membership test can act on. Two entries in the ADR's
     * prose list — `tool.entity.rollback` and `tool.entity.set_current_revision`
     * — are tool *names*, not capabilities: `EntityRollbackTool` and
     * `EntitySetCurrentRevisionTool` both declare `capability:
     * 'tool.entity.update'`, which is already withheld here, so both tools are
     * covered. `'present guided content'` is the single Wayfinding capability
     * covering both trail reads and trail writes, so it cannot be granted for
     * reading alone and is withheld entirely.
     *
     * @var list<string>
     */
    public const array WITHHELD_CAPABILITIES = [
        // Content and entity values.
        'tool.entity.read',
        'tool.entity.list',
        'tool.entity.search',
        'tool.content.search',
        // Relationships.
        'tool.relationship.traverse',
        // Vectors.
        'tool.vector.search',
        // Every mutation.
        'tool.entity.create',
        'tool.entity.update',
        'tool.entity.delete',
        'bimaaji.mutate',
        'present guided content',
    ];

    /** @var list<string> */
    private readonly array $toolIds;

    /** @var list<string> */
    private readonly array $capabilities;

    /**
     * @param list<string>       $toolIds     Tool IDs the profile admits. Defaults to {@see DEFAULT_TOOL_IDS}.
     * @param list<string>       $capabilities Capabilities the profile grants. Defaults to {@see DEFAULT_CAPABILITIES}.
     * @param ?SovereigntyProfile $readPolicy The active read-side sovereignty policy, or null when it could not
     *                                        be resolved. It participates in the claims digest (D-4.1) so a
     *                                        tightened policy retires cache entries computed under the looser one.
     *
     * @throws LocalOperatorRefusal D-7 on a malformed list; D-8 when a content-bearing capability is granted.
     */
    public function __construct(
        array $toolIds = self::DEFAULT_TOOL_IDS,
        array $capabilities = self::DEFAULT_CAPABILITIES,
        private readonly ?SovereigntyProfile $readPolicy = null,
    ) {
        foreach ($toolIds as $toolId) {
            if (trim($toolId) === '') {
                throw LocalOperatorRefusal::malformedProfile('a tool id may not be empty.');
            }
        }
        foreach ($capabilities as $capability) {
            if (trim($capability) === '') {
                throw LocalOperatorRefusal::malformedProfile('a capability may not be empty.');
            }
            // ADR-022 D-8.2 — fail closed. Enabling a content-bearing read
            // capability must be evaluated against the active
            // SovereigntyProfile, and that read-side evaluation surface does
            // not exist yet (SovereigntyGuardrails takes a MutationRequest and
            // cannot express a read decision). Until it does, granting one
            // here is refused rather than defaulted open.
            if (in_array($capability, self::WITHHELD_CAPABILITIES, true)) {
                throw LocalOperatorRefusal::contentCapabilityWithoutReadPolicy($capability);
            }
        }

        // Normalised: deduplicated and sorted, so the same authority always
        // digests to the same claims generation regardless of declaration
        // order (D-4.1).
        $normalizedToolIds = array_values(array_unique($toolIds));
        sort($normalizedToolIds);
        $normalizedCapabilities = array_values(array_unique($capabilities));
        sort($normalizedCapabilities);

        $this->toolIds = $normalizedToolIds;
        $this->capabilities = $normalizedCapabilities;
    }

    /**
     * Exact-membership test against the tool-ID allowlist (D-7.1).
     *
     * Never a prefix, pattern, or wildcard match — the same discipline
     * {@see LocalOperatorPrincipal::hasPermission()} applies to capabilities.
     */
    public function admits(string $toolId): bool
    {
        return in_array($toolId, $this->toolIds, true);
    }

    /** @return list<string> */
    public function toolIds(): array
    {
        return $this->toolIds;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function readPolicy(): ?SovereigntyProfile
    {
        return $this->readPolicy;
    }

    /**
     * The read-side sovereignty policy as a digest input.
     *
     * An unresolved policy digests to a distinct token rather than being
     * omitted, so "no policy resolved" and "profile local" are different
     * authorities and therefore different claims generations.
     */
    public function readPolicyToken(): string
    {
        return $this->readPolicy === null ? 'unresolved' : $this->readPolicy->value;
    }
}
