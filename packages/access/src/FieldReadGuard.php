<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\Context\FastAccountFieldReadScopeInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Fail-closed accessor backstop installed by the WP4 entity-read runtime.
 *
 * @internal
 */
final class FieldReadGuard implements EntityValueReadGuardInterface
{
    /** @var \Closure(AuthorizationPrincipalInterface, EntityStructure, PolicySubjectViewInterface, string, EntityInterface): AccessResult */
    private \Closure $protectedDecision;

    /** @var \Closure(EntityBase, string, object): PolicySubjectViewInterface */
    private \Closure $policySubject;

    /** @var \WeakMap<object, true> Contexts retained weakly only for explicit mutation invalidation. */
    private \WeakMap $contexts;

    private readonly ?FastAccountFieldReadScopeInterface $fastScope;
    private readonly ?AccountFieldReadScope $concreteScope;

    /**
     * @param callable(AuthorizationPrincipalInterface, EntityStructure, PolicySubjectViewInterface, string, EntityInterface): AccessResult $protectedDecision
     */
    public function __construct(
        private readonly AccountFieldReadScopeInterface $scope,
        callable $protectedDecision,
    ) {
        $this->protectedDecision = $protectedDecision(...);
        $this->policySubject = \Closure::bind(
            static fn(EntityBase $entity, string $field, object $viewIdentity): PolicySubjectViewInterface =>
                $entity->valueContainer->policySubjectView($field, $viewIdentity),
            null,
            EntityBase::class,
        );
        $this->contexts = new \WeakMap();
        $this->fastScope = $scope instanceof FastAccountFieldReadScopeInterface ? $scope : null;
        $this->concreteScope = $scope instanceof AccountFieldReadScope ? $scope : null;
    }

    /** Optimized activation path after the entity accessor resolves its compiled field rule. */
    public function assertCompiled(
        EntityInterface $entity,
        CompiledFieldReadRule $rule,
        ?PolicySubjectViewInterface $policySubject = null,
    ): void {
        $field = $rule->field;
        $level = $rule->level;
        if ($level === FieldReadLevel::Public) {
            return;
        }
        if ($level === FieldReadLevel::Internal) {
            throw new FieldReadDenied(sprintf('Field %s.%s requires an explicit audited capability.', $entity->getEntityTypeId(), $field));
        }

        $context = $this->concreteScope?->currentContext() ?? $this->fastScope?->currentContext();
        if ($context === null) {
            $principal = $this->scope->current();
            if ($principal === null) {
                throw new MissingFieldReadContext(sprintf('Field %s.%s requires an account read context.', $entity->getEntityTypeId(), $field));
            }
            if (!(($this->protectedDecision)(
                $principal,
                $this->structure($entity, $field),
                $policySubject ?? $this->emptySubject(),
                $field,
                $entity,
            ))->isAllowed()) {
                throw new FieldReadDenied(sprintf('Field %s.%s is not readable in this account context.', $entity->getEntityTypeId(), $field));
            }

            return;
        }
        $principal = $context->principal;
        if ($context->hotAllows($entity, $rule)) {
            return;
        }
        $this->contexts[$context] = true;
        $allowed = $context->decision($entity, $field);
        if ($allowed === null) {
            $allowed = (($this->protectedDecision)(
                $principal,
                $this->structure($entity, $field),
                $policySubject ?? $this->emptySubject(),
                $field,
                $entity,
            ))->isAllowed();
            $context->remember($entity, $field, $allowed);
        }
        if (!$allowed) {
            throw new FieldReadDenied(sprintf('Field %s.%s is not readable in this account context.', $entity->getEntityTypeId(), $field));
        }
        $context->rememberHotRule($rule);
    }

    public function assertProtectedReadable(EntityBase $entity, string $field, object $viewIdentity): void
    {
        $this->assertCompiled(
            $entity,
            $entity->compiledFieldReadRule($field),
            ($this->policySubject)($entity, $field, $viewIdentity),
        );
    }

    /**
     * Invalidate decisions after authorization-input mutation.
     *
     * WP4 accessor activation must call this from the entity mutation path;
     * dormant WP3 callers use it explicitly in mutation fixtures.
     */
    public function invalidate(EntityInterface $entity): void
    {
        foreach ($this->contexts as $context => $_) {
            $context->invalidate($entity);
        }
    }

    private function emptySubject(): PolicySubjectViewInterface
    {
        return new class implements PolicySubjectViewInterface {
            public function fields(): array
            {
                return [];
            }
            public function get(string $fieldName): mixed
            {
                return null;
            }
        };
    }

    private function structure(EntityInterface $entity, string $field): EntityStructure
    {
        if ($entity instanceof EntityBase && $entity->_hasEntityStructure()) {
            return $entity->entityStructure();
        }

        $language = $entity->language();

        return new EntityStructure(
            entityTypeId: $entity->getEntityTypeId(),
            bundleId: $entity->bundle(),
            id: $entity->id(),
            uuid: $entity->uuid() !== '' ? $entity->uuid() : null,
            activeLanguageId: $language,
            defaultLanguageId: $language,
            knownTranslationIds: $language !== '' ? [$language] : [],
            fieldNames: [$field],
        );
    }
}
