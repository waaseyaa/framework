<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\GenealogyBootstrap;
use Waaseyaa\Genealogy\GenealogyLivingSemantics;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\Workflows\WorkflowVisibility;

#[PolicyAttribute(entityType: ['genealogy_person', 'genealogy_family', 'genealogy_event', 'genealogy_tree'])]
final class GenealogyContentAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    private const array ENTITY_TYPES = ['genealogy_person', 'genealogy_family', 'genealogy_event', 'genealogy_tree'];

    /**
     * Shared forbidden reason for the living-person privacy guard (genealogy m-a).
     * Kept phrased around "Living persons" so the genealogy_person path reads
     * naturally and existing assertions on that substring hold.
     */
    private const string LIVING_PRIVACY_REASON = 'Living persons are not visible without an explicit grant.';

    private readonly WorkflowVisibility $workflowVisibility;

    public function __construct(?WorkflowVisibility $workflowVisibility = null)
    {
        $this->workflowVisibility = $workflowVisibility ?? new WorkflowVisibility();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::ENTITY_TYPES, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (self::isTombstoned($entity)) {
            return match ($operation) {
                'view' => AccessResult::forbidden('Soft-deleted genealogy entity is not visible.'),
                default => AccessResult::neutral('Tombstone blocks default mutation grants.'),
            };
        }

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account),
            'update', 'delete' => $this->mutateAccess($entity, $operation, $account),
            default => AccessResult::neutral(),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!in_array($entityTypeId, self::ENTITY_TYPES, true)) {
            return AccessResult::neutral();
        }

        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Genealogy create requires authentication.');
        }

        if ($entityTypeId === 'genealogy_tree') {
            return AccessResult::allowed('Authenticated user may create a tree workspace.');
        }

        return AccessResult::neutral('Genealogy nested creates require tree ownership checks at write time.');
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    private function viewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        if ($account instanceof DevAdminAccount) {
            return AccessResult::allowed('Dev fallback account may view genealogy content under the built-in server.');
        }

        if (!$account->isAuthenticated()) {
            return $this->anonymousPublishedViewAccess($entity);
        }

        if ($entity instanceof GenealogyTree) {
            return $this->treeView($entity, $account);
        }

        $tree = $this->treeForContent($entity);
        if ($tree === null) {
            return AccessResult::forbidden('Genealogy row is not attached to a viewable tree.');
        }

        if (!$this->accountOwnsTree($account, $tree)) {
            $published = $this->workflowVisibility->isEntityPublic($entity->getEntityTypeId(), EntityValues::toCastAwareMap($entity))
                && $this->workflowVisibility->isEntityPublic('genealogy_tree', EntityValues::toCastAwareMap($tree));
            if (!$published) {
                return AccessResult::forbidden('Genealogy content is private outside the owning account.');
            }
        }

        if (!$this->accountOwnsTree($account, $tree) && $this->concealsForLivingPrivacy($entity)) {
            return AccessResult::forbidden(self::LIVING_PRIVACY_REASON);
        }

        if ($this->accountOwnsTree($account, $tree)) {
            return AccessResult::allowed('Tree owner may view genealogy workspace content.');
        }

        // Reaching this point implies the entity AND tree both passed the
        // `$published` gate at the top of this method, so the visibility
        // check is provably true here. The earlier guards have already
        // filtered out private content and living persons under non-owners.
        return AccessResult::allowed('Published genealogy resource is viewable under tree policy.');
    }

    /**
     * Anonymous visitors may only load published genealogy metadata and published
     * rows under a published tree; living persons remain redacted.
     */
    private function anonymousPublishedViewAccess(EntityInterface $entity): AccessResult
    {
        if ($entity instanceof GenealogyTree) {
            return $this->workflowVisibility->isEntityPublic('genealogy_tree', EntityValues::toCastAwareMap($entity))
                ? AccessResult::allowed('Published tree metadata is viewable.')
                : AccessResult::forbidden('Tree is not published.');
        }

        $tree = $this->treeForContent($entity);
        if ($tree === null) {
            return AccessResult::forbidden('Genealogy row is not attached to a viewable tree.');
        }

        $entityPublic = $this->workflowVisibility->isEntityPublic(
            $entity->getEntityTypeId(),
            EntityValues::toCastAwareMap($entity),
        );
        $treePublic = $this->workflowVisibility->isEntityPublic('genealogy_tree', EntityValues::toCastAwareMap($tree));
        if (!$entityPublic || !$treePublic) {
            return AccessResult::forbidden('Genealogy content is not published for anonymous viewing.');
        }

        if ($this->concealsForLivingPrivacy($entity)) {
            return AccessResult::forbidden(self::LIVING_PRIVACY_REASON);
        }

        return AccessResult::allowed('Published genealogy resource is viewable anonymously.');
    }

    /**
     * Whether this row's identity channel must be concealed from a non-owner /
     * anonymous viewer for living-person privacy, even after the
     * published-entity + published-tree gates have passed (genealogy m-a).
     *
     * - `genealogy_person`: concealed iff the person is (effectively) living
     *   ({@see GenealogyLivingSemantics::effectiveIsLiving()}) — the original rule.
     * - `genealogy_family` / `genealogy_event`: ALWAYS concealed for non-owners.
     *   Both carry a REQUIRED free-text `display_name` that in practice names
     *   living people, and — unlike a person's `is_living` flag — that free-text
     *   channel has no living/deceased axis to test, so it fails CLOSED. The
     *   crawler-enumeration companion is `SeoPublicController::NON_PUBLIC_TYPES`.
     *   Tree owners reach this only after the ownership bypass above, and
     *   {@see DevAdminAccount} short-circuits to Allowed in {@see viewAccess()},
     *   so both keep access (the local demo still renders).
     * - `genealogy_tree`: its `display_name` is a workspace label, not a person
     *   name; tree visibility is handled by {@see treeView()} /
     *   {@see anonymousPublishedViewAccess()} before this method is reached, so
     *   the default is a no-op.
     */
    private function concealsForLivingPrivacy(EntityInterface $entity): bool
    {
        return match ($entity->getEntityTypeId()) {
            'genealogy_person' => GenealogyLivingSemantics::effectiveIsLiving($entity),
            'genealogy_family', 'genealogy_event' => true,
            default => false,
        };
    }

    private function mutateAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Genealogy mutations require authentication.');
        }

        if ($entity instanceof GenealogyTree) {
            return $this->accountOwnsTree($account, $entity)
                ? AccessResult::allowed("Tree owner may {$operation} this tree.")
                : AccessResult::forbidden('Only the tree owner may change this tree.');
        }

        $tree = $this->treeForContent($entity);
        if ($tree === null) {
            return AccessResult::forbidden('Cannot mutate genealogy row without a tree attach point.');
        }

        return $this->accountOwnsTree($account, $tree)
            ? AccessResult::allowed("Tree owner may {$operation} this record.")
            : AccessResult::forbidden('Only the tree owner may change this genealogy record.');
    }

    private function treeView(GenealogyTree $tree, AccountInterface $account): AccessResult
    {
        if ($this->accountOwnsTree($account, $tree)) {
            return AccessResult::allowed('Tree owner may view their tree.');
        }

        if ($this->workflowVisibility->isEntityPublic('genealogy_tree', EntityValues::toCastAwareMap($tree))) {
            return AccessResult::allowed('Published tree metadata is viewable.');
        }

        return AccessResult::forbidden('Tree is private to non-owners.');
    }

    private function accountOwnsTree(AccountInterface $account, GenealogyTree $tree): bool
    {
        $owner = $tree->get('owner_uid');

        return (string) $owner === (string) $account->id();
    }

    private function treeForContent(EntityInterface $entity): ?GenealogyTree
    {
        $etm = GenealogyBootstrap::entityTypeManager();
        if ($etm === null) {
            return null;
        }

        $treeId = $entity->get('tree_id');
        if ($treeId === null || $treeId === '' || $treeId === 0 || $treeId === '0') {
            return null;
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $loaded = $etm->getRepository('genealogy_tree')->find((string) $treeId);
        if (!$loaded instanceof GenealogyTree) {
            return null;
        }

        return $loaded;
    }

    private static function isTombstoned(EntityInterface $entity): bool
    {
        $v = $entity->get('deleted_at');

        return is_string($v) && trim($v) !== '';
    }
}
