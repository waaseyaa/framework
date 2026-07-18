<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\GenealogyBootstrap;
use Waaseyaa\User\DevAdminAccount;

#[PolicyAttribute(entityType: ['genealogy_person', 'genealogy_family', 'genealogy_event', 'genealogy_tree'])]
final class GenealogyContentAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private const array ENTITY_TYPES = ['genealogy_person', 'genealogy_family', 'genealogy_event', 'genealogy_tree'];

    /**
     * Shared forbidden reason for the living-person privacy guard (genealogy m-a).
     * Kept phrased around "Living persons" so the genealogy_person path reads
     * naturally and existing assertions on that substring hold.
     */
    private const string LIVING_PRIVACY_REASON = 'Living persons are not visible without an explicit grant.';

    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $policySubjectAuthority;

    public function __construct(private readonly ?GenealogyInternalFieldReaderInterface $internalReader = null)
    {
        $this->policySubjectAuthority = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::ENTITY_TYPES, true);
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new GenealogyProtectedEntityReadPolicy($this);
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new GenealogyProtectedFieldReadPolicy($this);
    }

    /** @internal V2 decision over compiled inputs only. */
    public function protectedViewAccess(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
    ): AccessResult {
        return $this->viewAccess($structure->entityTypeId, $subject, $principal);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $subject = $this->policySubject($entity);
        if ($subject === null) {
            return AccessResult::forbidden('Genealogy access requires compiled policy inputs.');
        }
        if ($this->internalReader?->isTombstoned($entity) === true) {
            return match ($operation) {
                'view' => AccessResult::forbidden('Soft-deleted genealogy entity is not visible.'),
                default => AccessResult::neutral('Tombstone blocks default mutation grants.'),
            };
        }

        return match ($operation) {
            'view' => $this->viewAccess($entity->getEntityTypeId(), $subject, $account),
            'update', 'delete' => $this->mutateAccess($entity, $subject, $operation, $account),
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

    private function viewAccess(string $entityTypeId, PolicySubjectViewInterface $subject, AccountInterface $account): AccessResult
    {
        if ($account instanceof DevAdminAccount) {
            return AccessResult::allowed('Dev fallback account may view genealogy content under the built-in server.');
        }

        if (!$account->isAuthenticated()) {
            return $this->anonymousPublishedViewAccess($entityTypeId, $subject);
        }

        if ($entityTypeId === 'genealogy_tree') {
            return $this->treeView($subject, $account);
        }

        $tree = $this->treeForContent($subject);
        if ($tree === null) {
            return AccessResult::forbidden('Genealogy row is not attached to a viewable tree.');
        }

        $treeSubject = $this->policySubject($tree);
        if ($treeSubject === null) {
            return AccessResult::forbidden('Genealogy tree requires compiled policy inputs.');
        }
        if (!$this->accountOwnsTree($account, $treeSubject)) {
            $published = $this->isPublished($subject) && $this->isPublished($treeSubject);
            if (!$published) {
                return AccessResult::forbidden('Genealogy content is private outside the owning account.');
            }
        }

        if (!$this->accountOwnsTree($account, $treeSubject) && $this->concealsForLivingPrivacy($entityTypeId, $subject)) {
            return AccessResult::forbidden(self::LIVING_PRIVACY_REASON);
        }

        if ($this->accountOwnsTree($account, $treeSubject)) {
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
    private function anonymousPublishedViewAccess(string $entityTypeId, PolicySubjectViewInterface $subject): AccessResult
    {
        if ($entityTypeId === 'genealogy_tree') {
            return $this->isPublished($subject)
                ? AccessResult::allowed('Published tree metadata is viewable.')
                : AccessResult::forbidden('Tree is not published.');
        }

        $tree = $this->treeForContent($subject);
        if ($tree === null) {
            return AccessResult::forbidden('Genealogy row is not attached to a viewable tree.');
        }

        $treeSubject = $this->policySubject($tree);
        $entityPublic = $this->isPublished($subject);
        $treePublic = $treeSubject !== null && $this->isPublished($treeSubject);
        if (!$entityPublic || !$treePublic) {
            return AccessResult::forbidden('Genealogy content is not published for anonymous viewing.');
        }

        if ($this->concealsForLivingPrivacy($entityTypeId, $subject)) {
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
    private function concealsForLivingPrivacy(string $entityTypeId, PolicySubjectViewInterface $subject): bool
    {
        return match ($entityTypeId) {
            'genealogy_person' => $this->subjectIsLiving($subject),
            'genealogy_family', 'genealogy_event' => true,
            default => false,
        };
    }

    private function mutateAccess(EntityInterface $entity, PolicySubjectViewInterface $subject, string $operation, AccountInterface $account): AccessResult
    {
        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Genealogy mutations require authentication.');
        }

        if ($entity instanceof GenealogyTree) {
            return $this->accountOwnsTree($account, $subject)
                ? AccessResult::allowed("Tree owner may {$operation} this tree.")
                : AccessResult::forbidden('Only the tree owner may change this tree.');
        }

        $tree = $this->treeForContent($subject);
        if ($tree === null) {
            return AccessResult::forbidden('Cannot mutate genealogy row without a tree attach point.');
        }

        $treeSubject = $this->policySubject($tree);

        return $treeSubject !== null && $this->accountOwnsTree($account, $treeSubject)
            ? AccessResult::allowed("Tree owner may {$operation} this record.")
            : AccessResult::forbidden('Only the tree owner may change this genealogy record.');
    }

    private function treeView(PolicySubjectViewInterface $subject, AccountInterface $account): AccessResult
    {
        if ($this->accountOwnsTree($account, $subject)) {
            return AccessResult::allowed('Tree owner may view their tree.');
        }

        if ($this->isPublished($subject)) {
            return AccessResult::allowed('Published tree metadata is viewable.');
        }

        return AccessResult::forbidden('Tree is private to non-owners.');
    }

    private function accountOwnsTree(AccountInterface $account, PolicySubjectViewInterface $subject): bool
    {
        $owner = $subject->get('owner_uid');

        return (string) $owner === (string) $account->id();
    }

    private function treeForContent(PolicySubjectViewInterface $subject): ?GenealogyTree
    {
        $etm = GenealogyBootstrap::entityTypeManager();
        if ($etm === null) {
            return null;
        }

        $treeId = $subject->get('tree_id');
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

    private function policySubject(EntityInterface $entity): ?PolicySubjectViewInterface
    {
        return $entity instanceof EntityBase ? ($this->policySubjectAuthority)($entity) : null;
    }

    private function isPublished(PolicySubjectViewInterface $subject): bool
    {
        return in_array($subject->get('status'), [true, 1, '1'], true);
    }

    private function subjectIsLiving(PolicySubjectViewInterface $subject): bool
    {
        if (in_array('is_living', $subject->fields(), true)) {
            return in_array($subject->get('is_living'), [true, 1, '1'], true);
        }

        return !in_array('death_date', $subject->fields(), true)
            || trim((string) $subject->get('death_date')) === '';
    }
}

/** Immutable-principal genealogy entity visibility over exact compiled inputs. @api */
final readonly class GenealogyProtectedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(private GenealogyContentAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return $operation === 'view'
            ? $this->policy->protectedViewAccess($principal, $structure, $subject)
            : AccessResult::neutral();
    }
}

/** Releases Protected genealogy fields only when the containing entity is viewable. @api */
final readonly class GenealogyProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function __construct(private GenealogyContentAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        return $this->policy->protectedViewAccess($principal, $structure, $subject);
    }
}
