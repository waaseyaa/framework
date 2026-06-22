<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Mutation;

use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Policy\SovereigntyGuardrails;

final class MutationValidator
{
    private const array CREATION_OPERATIONS = ['add_entity_type'];

    /**
     * @param ?SovereigntyGuardrails $guardrails Gates sovereignty-sensitive
     *        operations (e.g. delete_entity_type / modify_sovereignty on the
     *        managed-hosting profile). The README guarantees every mutation
     *        request passes these; they run BEFORE structural validation. The
     *        kernel binding always supplies one — nullable only so the
     *        validator stays constructible standalone (no guardrails = no
     *        sovereignty gating, for tests/tools that set up their own).
     */
    public function __construct(
        private readonly ApplicationGraph $graph,
        private readonly ?SovereigntyGuardrails $guardrails = null,
    ) {}

    public function validate(MutationRequest $request): MutationResult
    {
        // Sovereignty guardrails first: on managed hosting (NorthOps) this is
        // the only thing blocking delete_entity_type / delete_field /
        // modify_sovereignty, and the live agent-tool path runs through here.
        if ($this->guardrails !== null) {
            $guardrailResult = $this->guardrails->validate($request);
            if (!$guardrailResult->isSuccess()) {
                return $guardrailResult;
            }
        }

        $errors = [];

        if (!in_array($request->operation, self::CREATION_OPERATIONS, true)) {
            $entitiesSection = $this->graph->getSection('entities');
            $entities = $entitiesSection === null ? [] : $entitiesSection->data;

            if (!isset($entities[$request->entityType])) {
                $errors[] = 'UNKNOWN_ENTITY_TYPE';
            }
        }

        if ($errors !== []) {
            return MutationResult::failure($request, $errors);
        }

        return MutationResult::success($request);
    }
}
