<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Taxonomy\TermAccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Taxonomy\TermAccessPolicy
 */
class TermAccessPolicyTest extends TestCase
{
    private TermAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TermAccessPolicy();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createAccount(array $permissions = []): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')
            ->willReturnCallback(fn(string $permission) => \in_array($permission, $permissions, true));

        return $account;
    }

    private function createTermEntity(string $vocabularyId = 'tags', ?bool $published = null): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('taxonomy_term');
        $entity->method('bundle')->willReturn($vocabularyId);
        // NOTE: $published defaults to null (absent/undetermined status), NOT
        // "published". TermAccessPolicy::checkViewAccess() fails closed on a
        // missing status — absent is treated as unpublished. Callers that want
        // a published term for the assertion must pass published: true
        // explicitly (see testViewOfPublishedTermStillAllowedWithAccessContent).
        // The entity-side default (Term::isPublished()) is a separate, unrelated
        // product decision and stays published-by-default — this mock exists to
        // exercise the access-check fallback, not to mirror Term's constructor.
        $entity->method('get')->willReturnCallback(
            static fn(string $field): mixed => $field === 'status' ? $published : null,
        );

        return $entity;
    }

    // ---------------------------------------------------------------
    // appliesTo
    // ---------------------------------------------------------------

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testAppliesToTaxonomyTerm(): void
    {
        $this->assertTrue($this->policy->appliesTo('taxonomy_term'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo('taxonomy_vocabulary'));
    }

    // ---------------------------------------------------------------
    // View access
    // ---------------------------------------------------------------

    public function testViewAccessAllowedWithAccessContentPermission(): void
    {
        // Explicitly published — the previous version of this test relied on
        // the (now fail-closed) absent-status default to reach the published
        // branch. See testViewAccessDeniedWhenStatusAbsentFailsClosed for the
        // absent-status case.
        $entity = $this->createTermEntity('tags', published: true);
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testViewAccessNeutralWithoutPermission(): void
    {
        // Explicitly published; a reader with no permissions at all is neutral
        // regardless of the published/unpublished branch, so this is unaffected
        // by the fail-closed status change. Pinned to published: true for clarity.
        $entity = $this->createTermEntity('tags', published: true);
        $account = $this->createAccount([]);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isNeutral());
    }

    public function testViewAccessAllowedWithAdministerTaxonomyPermission(): void
    {
        $entity = $this->createTermEntity();
        $account = $this->createAccount(['administer taxonomy']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    // --- Unpublished-term view (security: must not leak unpublished terms) ---

    public function testViewOfUnpublishedTermIsDeniedToAccessContentOnly(): void
    {
        // An unpublished term must NOT be viewable by an anonymous/regular account
        // that holds only 'access content' — pre-fix this returned allowed (leak).
        $entity = $this->createTermEntity('tags', published: false);
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isNeutral(), 'unpublished term must not be viewable with only access content');
    }

    public function testViewOfUnpublishedTermAllowedForVocabularyEditor(): void
    {
        $entity = $this->createTermEntity('tags', published: false);
        $account = $this->createAccount(['access content', 'edit terms in tags']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed(), 'an editor of the vocabulary may view its unpublished terms');
    }

    public function testViewOfPublishedTermStillAllowedWithAccessContent(): void
    {
        $entity = $this->createTermEntity('tags', published: true);
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed(), 'published terms remain viewable with access content');
    }

    // --- Absent-status view access (security: policy-side fail-closed default) ---

    public function testViewAccessDeniedWhenStatusAbsentFailsClosed(): void
    {
        // Absent/undeterminable status must be treated as unpublished — a
        // regular reader with only 'access content' must NOT see it. Pre-fix
        // this returned allowed (the fail-open `?? true` default) and this
        // test would fail red against that code.
        $entity = $this->createTermEntity('tags', published: null);
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isNeutral(), 'a term with absent status must not be publicly viewable');
    }

    public function testViewAccessAllowedForAdminWhenStatusAbsent(): void
    {
        // 'administer taxonomy' bypasses checkViewAccess entirely (handled in
        // access()), so it must still see a term whose status is absent.
        $entity = $this->createTermEntity('tags', published: null);
        $account = $this->createAccount(['administer taxonomy']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed(), 'an admin may still view a term with absent status');
    }

    public function testViewAccessAllowedForVocabularyEditorWhenStatusAbsent(): void
    {
        // Absent status is treated as unpublished, and the unpublished branch
        // grants view access to an editor of the term's vocabulary.
        $entity = $this->createTermEntity('tags', published: null);
        $account = $this->createAccount(['access content', 'edit terms in tags']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed(), 'a vocabulary editor may view a term with absent status');
    }

    // ---------------------------------------------------------------
    // Update access
    // ---------------------------------------------------------------

    public function testUpdateAccessAllowedWithEditTermsPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['edit terms in tags']);

        $result = $this->policy->access($entity, 'update', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateAccessNeutralWithoutPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount([]);

        $result = $this->policy->access($entity, 'update', $account);

        $this->assertTrue($result->isNeutral());
    }

    public function testUpdateAccessAllowedWithAdministerTaxonomyPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['administer taxonomy']);

        $result = $this->policy->access($entity, 'update', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateAccessDeniedWithWrongVocabularyPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['edit terms in categories']);

        $result = $this->policy->access($entity, 'update', $account);

        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // Delete access
    // ---------------------------------------------------------------

    public function testDeleteAccessAllowedWithDeleteTermsPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['delete terms in tags']);

        $result = $this->policy->access($entity, 'delete', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteAccessNeutralWithoutPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount([]);

        $result = $this->policy->access($entity, 'delete', $account);

        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteAccessAllowedWithAdministerTaxonomyPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['administer taxonomy']);

        $result = $this->policy->access($entity, 'delete', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteAccessDeniedWithWrongVocabularyPermission(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['delete terms in categories']);

        $result = $this->policy->access($entity, 'delete', $account);

        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // Unknown operation
    // ---------------------------------------------------------------

    public function testUnknownOperationReturnsNeutral(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'archive', $account);

        $this->assertTrue($result->isNeutral());
    }

    public function testUnknownOperationAllowedWithAdministerTaxonomy(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['administer taxonomy']);

        // 'administer taxonomy' grants access for ANY operation.
        $result = $this->policy->access($entity, 'archive', $account);

        $this->assertTrue($result->isAllowed());
    }

    // ---------------------------------------------------------------
    // Create access
    // ---------------------------------------------------------------

    public function testCreateAccessAllowedWithCreateTermsPermission(): void
    {
        $account = $this->createAccount(['create terms in tags']);

        $result = $this->policy->createAccess('taxonomy_term', 'tags', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessNeutralWithoutPermission(): void
    {
        $account = $this->createAccount([]);

        $result = $this->policy->createAccess('taxonomy_term', 'tags', $account);

        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessAllowedWithAdministerTaxonomyPermission(): void
    {
        $account = $this->createAccount(['administer taxonomy']);

        $result = $this->policy->createAccess('taxonomy_term', 'tags', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessDeniedWithWrongVocabularyPermission(): void
    {
        $account = $this->createAccount(['create terms in categories']);

        $result = $this->policy->createAccess('taxonomy_term', 'tags', $account);

        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // Vocabulary-specific permissions
    // ---------------------------------------------------------------

    public function testPermissionsAreVocabularySpecific(): void
    {
        $tagsEntity = $this->createTermEntity('tags');
        $categoriesEntity = $this->createTermEntity('categories');

        // Account has edit permission only for 'tags' vocabulary.
        $account = $this->createAccount(['edit terms in tags']);

        $tagsResult = $this->policy->access($tagsEntity, 'update', $account);
        $categoriesResult = $this->policy->access($categoriesEntity, 'update', $account);

        $this->assertTrue($tagsResult->isAllowed());
        $this->assertTrue($categoriesResult->isNeutral());
    }

    public function testAdministerTaxonomyGrantsAccessToAllVocabularies(): void
    {
        $tagsEntity = $this->createTermEntity('tags');
        $categoriesEntity = $this->createTermEntity('categories');
        $account = $this->createAccount(['administer taxonomy']);

        $this->assertTrue($this->policy->access($tagsEntity, 'update', $account)->isAllowed());
        $this->assertTrue($this->policy->access($categoriesEntity, 'update', $account)->isAllowed());
        $this->assertTrue($this->policy->access($tagsEntity, 'delete', $account)->isAllowed());
        $this->assertTrue($this->policy->access($categoriesEntity, 'delete', $account)->isAllowed());
    }

    // ---------------------------------------------------------------
    // Access result reasons
    // ---------------------------------------------------------------

    public function testAccessResultContainsReason(): void
    {
        $entity = $this->createTermEntity('tags');
        $account = $this->createAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertNotEmpty($result->reason);
    }

    public function testCreateAccessResultContainsReason(): void
    {
        $account = $this->createAccount(['create terms in tags']);

        $result = $this->policy->createAccess('taxonomy_term', 'tags', $account);

        $this->assertNotEmpty($result->reason);
    }
}
