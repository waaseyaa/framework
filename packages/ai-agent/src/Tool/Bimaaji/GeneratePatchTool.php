<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Mutation\MutationRequest;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;

/**
 * Patch-generation tool.
 *
 * Re-runs {@see MutationValidator::validate()} against the supplied mutation
 * arguments and, on success, calls {@see PatchGenerator::generate()} to emit
 * a {@see \Waaseyaa\Bimaaji\Patch\PatchSet}. The returned envelope wraps
 * {@see \Waaseyaa\Bimaaji\Patch\PatchSet::toArray()}; the consuming agent
 * loop decides what to do with the patches.
 *
 * **SC-005 invariant:** this tool never touches the filesystem. The
 * underlying {@see PatchGenerator} builds patch content + diffText
 * in-memory; this tool surfaces them verbatim without any write step.
 *
 * **Re-validation:** the spec contemplated accepting a pre-validated
 * `MutationResult` so an agent could chain `bimaaji_propose_mutation` →
 * `bimaaji_generate_patch` without re-paying validator cost. JSON tool
 * arguments cannot carry a `MutationResult` object, so the tool re-validates
 * the request internally. The duplicated work is the price of refusing to
 * trust client-supplied validation state.
 *
 * Capability: `bimaaji.mutate`.
 *
 * @api
 */
#[AsAgentTool(
    name: 'bimaaji_generate_patch',
    capability: 'bimaaji.mutate',
    destructive: false,
    dryRunSupported: true,
    category: 'bimaaji',
)]
final class GeneratePatchTool extends AbstractAgentTool
{
    public function __construct(
        private readonly PatchGenerator $patchGenerator,
        private readonly MutationValidator $validator,
    ) {}

    public function description(): string
    {
        return 'Generate a PatchSet for a validated mutation. Returns patches in-memory; no filesystem writes.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'Mutation operation key (e.g. add_field, add_entity_type).',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'description' => 'Target entity-type id.',
                ],
                'field' => [
                    'type' => ['string', 'null'],
                    'description' => 'Target field machine name when the operation is field-scoped.',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Operation-specific parameters bag.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['operation', 'entity_type'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.mutate', $account);
        if ($denied !== null) {
            return $denied;
        }

        $request = $this->buildRequest($arguments);
        if ($request instanceof AgentToolResult) {
            return $request;
        }

        try {
            $result = $this->validator->validate($request);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('bimaaji_generate_patch: [%s] %s', $e::class, $e->getMessage()),
                summary: 'validation failed',
            );
        }

        if (!$result->isSuccess()) {
            return AgentToolResult::error(
                message: sprintf(
                    'bimaaji_generate_patch: validation rejected request (%s).',
                    implode(', ', $result->errors),
                ),
                summary: 'validation failed',
            );
        }

        try {
            $patchSet = $this->patchGenerator->generate($result);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('bimaaji_generate_patch: [%s] %s', $e::class, $e->getMessage()),
                summary: 'patch generation failed',
            );
        }

        $payload = $patchSet->toArray();

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_generate_patch', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('PatchSet: %d patches', count($payload['patches'])),
            structuredContent: $payload,
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildRequest(array $arguments): MutationRequest|AgentToolResult
    {
        $operation = $arguments['operation'] ?? null;
        if (!is_string($operation) || $operation === '') {
            return AgentToolResult::error(
                message: 'bimaaji_generate_patch: missing required argument "operation".',
                summary: 'missing argument',
            );
        }

        $entityType = $arguments['entity_type'] ?? null;
        if (!is_string($entityType) || $entityType === '') {
            return AgentToolResult::error(
                message: 'bimaaji_generate_patch: missing required argument "entity_type".',
                summary: 'missing argument',
            );
        }

        $field = $arguments['field'] ?? null;
        if ($field !== null && !is_string($field)) {
            return AgentToolResult::error(
                message: 'bimaaji_generate_patch: "field" must be a string or null.',
                summary: 'invalid argument',
            );
        }

        $parameters = $arguments['parameters'] ?? [];
        if (!is_array($parameters)) {
            return AgentToolResult::error(
                message: 'bimaaji_generate_patch: "parameters" must be an object.',
                summary: 'invalid argument',
            );
        }

        /** @var array<string, mixed> $parameters */

        return new MutationRequest(
            operation: $operation,
            entityType: $entityType,
            field: $field,
            parameters: $parameters,
        );
    }
}
