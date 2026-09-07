<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Blueprint\Emitter\AccessPolicyEmitter;
use Waaseyaa\SiteContract\Blueprint\BlueprintConditionKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintOperation;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicy;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicyCondition;

/**
 * Generates a real, registrable {@see \Waaseyaa\Access\AccessPolicyInterface}
 * implementation bound to an explicit entity type and explicit
 * operation/permission grants (#2848).
 *
 * Renders through {@see AccessPolicyEmitter::renderPolicyClass()} — the same
 * code path the blueprint compiler (#2788) uses for a manifest-declared
 * policy — so a manual `make:policy` invocation and an equivalent blueprint
 * `policies:` declaration converge on the same generated shape: a
 * `#[PolicyAttribute(entityType: ...)]`-carrying class implementing
 * `access()`/`createAccess()`/`appliesTo()`, granting only explicit matches
 * (`Allowed` or `Neutral`; entity access denies absent an allowance), with zero
 * `--grant` producing an entity-bound, all-`Neutral` (default-deny) class.
 *
 * Only the `permission` condition kind is reachable here — `ownership` and
 * `workflow_state` conditions need entity metadata (`keys.owner`, a
 * workflow binding) this bare CLI grammar has no way to declare; those stay
 * blueprint-only (docs/history/plans/2848-policy-workflow-convergence.md).
 *
 * Stays stdout-only, zero side effects (ADR-025 D-4 `keep` disposition):
 * this handler never writes a file, never throws
 * `GenerationRefusalException`, and never touches the staged generation
 * authority (`ArtifactPlan` et al.) — required so it stays outside
 * `tests/Architecture/GenerationStagedActivationBoundaryTest.php`'s closed
 * allowlists. Malformed input is refused locally with exit `2`.
 *
 * @api
 */
final class MakePolicyHandler extends AbstractMakeHandler
{
    private const array SUPPORTED_OPERATIONS = ['view', 'create', 'update', 'delete'];

    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        try {
            $this->validateIdentifier($name, 'name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $entityId = trim((string) ($io->option('entity') ?? ''));
        if ($entityId === '') {
            $io->error('--entity is required.');

            return 2;
        }
        try {
            $this->validateMachineName($entityId, 'entity');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 2;
        }

        /** @var array<mixed> $rawGrants */
        $rawGrants = (array) ($io->option('grant') ?? []);
        $policies = $this->parseGrants($rawGrants, $entityId);
        if ($policies === null) {
            $io->error('Invalid --grant format. Use operation:permission, where operation is one of ' . implode('|', self::SUPPORTED_OPERATIONS) . '.');

            return 2;
        }

        $entityClassOption = trim((string) ($io->option('entity-class') ?? ''));
        if ($entityClassOption !== '') {
            try {
                $this->validateIdentifier($entityClassOption, 'entity-class', self::FQCN_PATTERN);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return 2;
            }
        }

        $className = $this->toPascalCase($name);

        $rendered = new AccessPolicyEmitter()->renderPolicyClass(
            $entityId,
            ownerField: null,
            className: $className,
            policies: $policies,
            workflowBound: false,
            entityClassFqcn: $entityClassOption !== '' ? ltrim($entityClassOption, '\\') : null,
        );

        $io->write($rendered);

        return 0;
    }

    /**
     * @param array<mixed> $rawGrants
     * @return list<BlueprintPolicy>|null null means a malformed grant was supplied
     */
    private function parseGrants(array $rawGrants, string $entityId): ?array
    {
        $policies = [];
        $index = 0;
        foreach ($rawGrants as $raw) {
            if (!is_string($raw)) {
                return null;
            }
            $separator = strpos($raw, ':');
            if ($separator === false) {
                return null;
            }
            $operation = trim(substr($raw, 0, $separator));
            $permission = trim(substr($raw, $separator + 1));
            if ($operation === '' || $permission === '') {
                return null;
            }
            $blueprintOperation = BlueprintOperation::tryFrom($operation);
            if ($blueprintOperation === null) {
                return null;
            }

            $policies[] = new BlueprintPolicy(
                id: sprintf('%s_grant_%d', $entityId, $index),
                entity: $entityId,
                operation: $blueprintOperation,
                condition: new BlueprintPolicyCondition(BlueprintConditionKind::Permission, permission: $permission),
            );
            ++$index;
        }

        return $policies;
    }
}
