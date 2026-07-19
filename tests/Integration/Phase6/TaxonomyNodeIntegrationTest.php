<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeAuthorizationSnapshotReader;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\TermAccessPolicy;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\User\AnonymousUser;

/**
 * Integration tests for waaseyaa/taxonomy + waaseyaa/node + waaseyaa/access.
 *
 * Verifies that taxonomy terms can be used to categorize nodes,
 * that both entity types can be persisted and loaded via storage,
 * and that access policies work together correctly.
 */
#[CoversNothing]
final class TaxonomyNodeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', [
            'field_tags' => ['type' => 'entity_reference', 'read' => FieldReadLevel::Public],
            'field_category' => ['type' => 'entity_reference', 'read' => FieldReadLevel::Public],
        ]);
        ContentEntityBase::setFieldRegistry($registry);
        $this->accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new TermAccessPolicy(),
        ]);
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    private EntityAccessHandler $accessHandler;

    #[Test]
    public function vocabularyAndTermsCanBeCreated(): void
    {
        $vocab = new Vocabulary([
            'vid' => 'tags',
            'name' => 'Tags',
            'description' => 'Free-form tagging vocabulary.',
        ]);

        $this->assertSame('tags', $vocab->id());
        $this->assertSame('Tags', $vocab->getName());
        $this->assertSame('Free-form tagging vocabulary.', $vocab->getDescription());

        $term1 = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'PHP',
        ]);
        $term2 = new Term([
            'tid' => 2,
            'vid' => 'tags',
            'name' => 'Testing',
        ]);

        $this->assertSame('tags', $term1->getVocabularyId());
        $this->assertSame('PHP', $term1->getName());
        $this->assertSame('tags', $term2->getVocabularyId());
        $this->assertSame('Testing', $term2->getName());
    }

    #[Test]
    public function nodesCanReferenceTermIds(): void
    {
        // Create terms.
        $phpTerm = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'PHP',
        ]);
        $testingTerm = new Term([
            'tid' => 2,
            'vid' => 'tags',
            'name' => 'Testing',
        ]);

        // Create a node that references term IDs.
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'PHP Testing Best Practices',
            'uid' => 1,
            'status' => 1,
        ], fieldDefinitions: [
            'field_tags' => new FieldDefinition('field_tags', 'entity_reference', read: FieldReadLevel::Public),
        ]);
        // Store term references as a field value.
        $node->set('field_tags', [$phpTerm->id(), $testingTerm->id()]);

        $tagIds = $node->get('field_tags');
        $this->assertCount(2, $tagIds);
        $this->assertContains(1, $tagIds);
        $this->assertContains(2, $tagIds);
    }

    #[Test]
    public function termAndNodeAccessPoliciesWorkTogether(): void
    {
        $viewer = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'viewer',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $editor = AuthorizationPrincipalFactory::fromValues([
            'uid' => 11,
            'name' => 'editor',
            'permissions' => ['access content', 'edit terms in tags'],
            'roles' => ['editor'],
        ]);

        // Published node and term.
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Tagged Article',
            'uid' => 1,
            'status' => 1,
        ], fieldDefinitions: [
            'field_tags' => new FieldDefinition('field_tags', 'entity_reference', read: FieldReadLevel::Public),
        ]);

        $term = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'PHP',
        ]);

        // Both viewer and editor can view published nodes.
        $this->assertTrue($this->accessHandler->check($node, 'view', $viewer)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'view', $editor)->isAllowed());

        // Both can view terms with "access content".
        $this->assertTrue($this->accessHandler->check($term, 'view', $viewer)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'view', $editor)->isAllowed());

        // Only editor can update terms in the 'tags' vocabulary.
        $this->assertFalse($this->accessHandler->check($term, 'update', $viewer)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'update', $editor)->isAllowed());
    }

    #[Test]
    public function anonymousCanViewPublishedTermsAndNodes(): void
    {
        $anonymous = new AnonymousUser(['access content']);

        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Public Article',
            'uid' => 1,
            'status' => 1,
        ], fieldDefinitions: [
            'field_category' => new FieldDefinition('field_category', 'entity_reference', read: FieldReadLevel::Public),
        ]);
        $term = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'Public Tag',
        ]);

        $this->assertTrue($this->accessHandler->check($node, 'view', $anonymous)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'view', $anonymous)->isAllowed());
    }

    #[Test]
    public function anonymousWithoutPermissionCannotViewNodesOrTerms(): void
    {
        $anonymous = new AnonymousUser();

        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Article',
            'uid' => 1,
            'status' => 1,
        ]);
        $term = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'Tag',
        ]);

        $this->assertFalse($this->accessHandler->check($node, 'view', $anonymous)->isAllowed());
        $this->assertFalse($this->accessHandler->check($term, 'view', $anonymous)->isAllowed());
    }

    #[Test]
    public function deletingTermsDoesNotBreakNodeReferences(): void
    {
        // Create terms and a node referencing them.
        $term1 = new Term(['tid' => 1, 'vid' => 'tags', 'name' => 'PHP']);
        $term2 = new Term(['tid' => 2, 'vid' => 'tags', 'name' => 'Testing']);

        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Tagged Article',
            'uid' => 1,
            'status' => 1,
        ]);
        $node->set('field_tags', [$term1->id(), $term2->id()]);

        // Simulate term deletion by removing term2 from the available set.
        // Node still holds reference IDs -- loose coupling means the node is unaffected.
        $availableTerms = [1 => $term1]; // term2 is "deleted"

        // Node's tag references still exist.
        $tagIds = $node->get('field_tags');
        $this->assertCount(2, $tagIds);
        $this->assertContains(2, $tagIds);

        // But we can resolve only the existing terms.
        $resolvedTerms = array_filter(
            $tagIds,
            static fn(int $id): bool => isset($availableTerms[$id]),
        );
        $this->assertCount(1, $resolvedTerms);
        $this->assertContains(1, $resolvedTerms);

        // Node entity itself is fully functional despite orphaned references.
        $this->assertSame('Tagged Article', $node->getTitle());
        $this->assertTrue(new NodeAuthorizationSnapshotReader()->read($node)->published);
    }

    #[Test]
    public function termHierarchyWithNodes(): void
    {
        // Create a vocabulary with hierarchical terms.
        $vocab = new Vocabulary([
            'vid' => 'categories',
            'name' => 'Categories',
        ]);

        $parentTerm = new Term([
            'tid' => 1,
            'vid' => 'categories',
            'name' => 'Technology',
        ]);

        $childTerm = new Term([
            'tid' => 2,
            'vid' => 'categories',
            'name' => 'Programming',
            'parent_id' => 1,
        ]);

        $this->assertTrue($parentTerm->isRoot());
        $this->assertFalse($childTerm->isRoot());
        $this->assertSame(1, $childTerm->getParentId());

        // Create node categorized under the child term.
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Intro to PHP',
            'uid' => 1,
            'status' => 1,
        ]);
        $node->set('field_category', $childTerm->id());

        $this->assertSame(2, $node->get('field_category'));
    }

    #[Test]
    public function createAccessForTermsRequiresVocabularyPermission(): void
    {
        $termEditor = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'term_editor',
            'permissions' => ['create terms in tags'],
            'roles' => ['editor'],
        ]);

        $regularUser = AuthorizationPrincipalFactory::fromValues([
            'uid' => 6,
            'name' => 'regular',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $canCreate = $this->accessHandler->checkCreateAccess('taxonomy_term', 'tags', $termEditor);
        $this->assertTrue($canCreate->isAllowed(), 'Editor with vocabulary permission should create terms.');

        $cannotCreate = $this->accessHandler->checkCreateAccess('taxonomy_term', 'tags', $regularUser);
        $this->assertFalse($cannotCreate->isAllowed(), 'Regular user should not create terms.');
    }

    #[Test]
    public function taxonomyAdminBypassesAllChecks(): void
    {
        $admin = AuthorizationPrincipalFactory::fromValues([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer taxonomy'],
            'roles' => ['administrator'],
        ]);

        $term = new Term([
            'tid' => 1,
            'vid' => 'tags',
            'name' => 'PHP',
        ]);

        $this->assertTrue($this->accessHandler->check($term, 'view', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'update', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'delete', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->checkCreateAccess('taxonomy_term', 'tags', $admin)->isAllowed());
    }

    #[Test]
    public function nodeOwnerAccessControlWithTermReferences(): void
    {
        $author = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'author',
            'permissions' => ['access content', 'edit own article content', 'delete own article content'],
            'roles' => ['authenticated'],
        ]);

        $otherUser = AuthorizationPrincipalFactory::fromValues([
            'uid' => 20,
            'name' => 'other',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Node owned by author.
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'My Article',
            'uid' => 10,
            'status' => 1,
        ]);
        $node->set('field_tags', [1, 2]);

        // Author can edit and delete own content.
        $this->assertTrue($this->accessHandler->check($node, 'update', $author)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'delete', $author)->isAllowed());

        // Other user cannot edit or delete.
        $this->assertFalse($this->accessHandler->check($node, 'update', $otherUser)->isAllowed());
        $this->assertFalse($this->accessHandler->check($node, 'delete', $otherUser)->isAllowed());

        // Both can view published content.
        $this->assertTrue($this->accessHandler->check($node, 'view', $author)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'view', $otherUser)->isAllowed());
    }
}
