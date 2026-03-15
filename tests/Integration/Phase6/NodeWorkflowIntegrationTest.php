<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeType;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;
use Waaseyaa\Workflows\ContentModerationState;
use Waaseyaa\Workflows\ContentModerator;
use Waaseyaa\Workflows\Workflow;

/**
 * Integration tests for waaseyaa/node + waaseyaa/workflows + waaseyaa/access.
 *
 * Verifies that nodes can be created with types, put through workflow
 * moderation states, and access control changes based on moderation state.
 */
#[CoversNothing]
final class NodeWorkflowIntegrationTest extends TestCase
{
    private ContentModerator $moderator;
    private Workflow $editorialWorkflow;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        // Set up the editorial workflow with draft/review/published states.
        $this->editorialWorkflow = new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'states' => [
                'draft' => ['label' => 'Draft', 'weight' => 0],
                'review' => ['label' => 'In Review', 'weight' => 1],
                'published' => ['label' => 'Published', 'weight' => 2],
            ],
            'transitions' => [
                'submit_for_review' => [
                    'label' => 'Submit for Review',
                    'from' => ['draft'],
                    'to' => 'review',
                ],
                'publish' => [
                    'label' => 'Publish',
                    'from' => ['review'],
                    'to' => 'published',
                ],
                'send_back' => [
                    'label' => 'Send Back to Draft',
                    'from' => ['review'],
                    'to' => 'draft',
                ],
                'unpublish' => [
                    'label' => 'Unpublish',
                    'from' => ['published'],
                    'to' => 'draft',
                ],
            ],
        ]);

        $this->moderator = new ContentModerator();
        $this->moderator->addWorkflow($this->editorialWorkflow);

        // Set up access handler with the NodeAccessPolicy.
        $this->accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
        ]);
    }

    #[Test]
    public function nodeTypeDefinesContentType(): void
    {
        $articleType = new NodeType([
            'type' => 'article',
            'name' => 'Article',
            'description' => 'Content type for articles.',
            'new_revision' => true,
            'display_submitted' => true,
        ]);

        $this->assertSame('article', $articleType->id());
        $this->assertSame('Article', $articleType->getName());
        $this->assertSame('Content type for articles.', $articleType->getDescription());
        $this->assertTrue($articleType->isNewRevision());
        $this->assertTrue($articleType->getDisplaySubmitted());
    }

    #[Test]
    public function nodeCanBeCreatedWithTypeAndModeratedThroughWorkflow(): void
    {
        // Create a NodeType and a Node of that type.
        $articleType = new NodeType([
            'type' => 'article',
            'name' => 'Article',
        ]);
        $this->assertSame('article', $articleType->id());

        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Integration Test Article',
            'uid' => 10,
            'status' => 0, // Starts unpublished (draft).
        ]);

        $this->assertSame('article', $node->getType());
        $this->assertSame('Integration Test Article', $node->getTitle());
        $this->assertFalse($node->isPublished());

        // Create initial moderation state (draft).
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );
        $this->assertSame('draft', $moderationState->stateId);

        // Transition: draft -> review.
        $moderationState = $this->moderator->transition($moderationState, 'review');
        $this->assertSame('review', $moderationState->stateId);

        // Transition: review -> published.
        $moderationState = $this->moderator->transition($moderationState, 'published');
        $this->assertSame('published', $moderationState->stateId);

        // Update the node to published.
        $node->setPublished(true);
        $this->assertTrue($node->isPublished());
    }

    #[Test]
    public function invalidTransitionIsRejected(): void
    {
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        // Direct draft -> published is not allowed (must go through review).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "draft" to "published" is not allowed');
        $this->moderator->transition($moderationState, 'published');
    }

    #[Test]
    public function availableTransitionsFromDraft(): void
    {
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        $transitions = $this->moderator->getAvailableTransitions($moderationState);

        $this->assertCount(1, $transitions);
        $this->assertArrayHasKey('submit_for_review', $transitions);
    }

    #[Test]
    public function availableTransitionsFromReview(): void
    {
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'review',
        );

        $transitions = $this->moderator->getAvailableTransitions($moderationState);

        $this->assertCount(2, $transitions);
        $this->assertArrayHasKey('publish', $transitions);
        $this->assertArrayHasKey('send_back', $transitions);
    }

    #[Test]
    public function publishedNodeIsViewableWithAccessContentPermission(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Published Article',
            'uid' => 10,
            'status' => 1, // Published.
        ]);

        $viewer = new User([
            'uid' => 20,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $result = $this->accessHandler->check($node, 'view', $viewer);
        $this->assertTrue($result->isAllowed(), 'User with "access content" should view published nodes.');
    }

    #[Test]
    public function unpublishedNodeIsNotViewableByNonOwner(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Draft Article',
            'uid' => 10,
            'status' => 0, // Unpublished.
        ]);

        $nonOwner = new User([
            'uid' => 20,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $result = $this->accessHandler->check($node, 'view', $nonOwner);
        $this->assertFalse($result->isAllowed(), 'Non-owner should not view unpublished nodes.');
    }

    #[Test]
    public function unpublishedNodeIsViewableByOwnerWithPermission(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Draft Article',
            'uid' => 10,
            'status' => 0, // Unpublished.
        ]);

        $owner = new User([
            'uid' => 10,
            'name' => 'author',
            'permissions' => ['access content', 'view own unpublished content'],
            'roles' => ['authenticated'],
        ]);

        $result = $this->accessHandler->check($node, 'view', $owner);
        $this->assertTrue($result->isAllowed(), 'Owner with "view own unpublished content" should view their drafts.');
    }

    #[Test]
    public function anonymousCannotViewUnpublishedNode(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Draft Article',
            'uid' => 10,
            'status' => 0,
        ]);

        $anonymous = new AnonymousUser();

        $result = $this->accessHandler->check($node, 'view', $anonymous);
        $this->assertFalse($result->isAllowed(), 'Anonymous should not view unpublished nodes.');
    }

    #[Test]
    public function fullModerationLifecycleWithAccessAtEachState(): void
    {
        $author = new User([
            'uid' => 10,
            'name' => 'author',
            'permissions' => ['access content', 'view own unpublished content'],
            'roles' => ['authenticated'],
        ]);

        $reader = new User([
            'uid' => 20,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Step 1: Create node in draft state.
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Lifecycle Article',
            'uid' => 10,
            'status' => 0,
        ]);

        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        // In draft: author can view, reader cannot.
        $this->assertTrue(
            $this->accessHandler->check($node, 'view', $author)->isAllowed(),
            'Author should view own draft.',
        );
        $this->assertFalse(
            $this->accessHandler->check($node, 'view', $reader)->isAllowed(),
            'Reader should not view draft.',
        );

        // Step 2: Submit for review (draft -> review). Node stays unpublished.
        $moderationState = $this->moderator->transition($moderationState, 'review');
        $this->assertSame('review', $moderationState->stateId);

        // Still unpublished, same access rules apply.
        $this->assertTrue(
            $this->accessHandler->check($node, 'view', $author)->isAllowed(),
            'Author should still view own unpublished in review.',
        );
        $this->assertFalse(
            $this->accessHandler->check($node, 'view', $reader)->isAllowed(),
            'Reader should not view unpublished in review.',
        );

        // Step 3: Publish (review -> published).
        $moderationState = $this->moderator->transition($moderationState, 'published');
        $this->assertSame('published', $moderationState->stateId);
        $node->setPublished(true);

        // Now published: both can view.
        $this->assertTrue(
            $this->accessHandler->check($node, 'view', $author)->isAllowed(),
            'Author should view published node.',
        );
        $this->assertTrue(
            $this->accessHandler->check($node, 'view', $reader)->isAllowed(),
            'Reader should view published node.',
        );

        // Step 4: Unpublish (published -> draft).
        $moderationState = $this->moderator->transition($moderationState, 'draft');
        $this->assertSame('draft', $moderationState->stateId);
        $node->setPublished(false);

        // Back to draft: reader can no longer view.
        $this->assertTrue(
            $this->accessHandler->check($node, 'view', $author)->isAllowed(),
            'Author should view own unpublished draft after unpublish.',
        );
        $this->assertFalse(
            $this->accessHandler->check($node, 'view', $reader)->isAllowed(),
            'Reader should not view unpublished after unpublish.',
        );
    }

    #[Test]
    public function adminBypassesAllAccessChecks(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Any Article',
            'uid' => 99,
            'status' => 0,
        ]);

        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);

        // Admin can view, update, delete any node regardless of status.
        $this->assertTrue($this->accessHandler->check($node, 'view', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'update', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'delete', $admin)->isAllowed());
    }

    #[Test]
    public function sendBackTransitionReturnsNodeToReviewableState(): void
    {
        // Start in review.
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'review',
        );

        // Send back to draft.
        $moderationState = $this->moderator->transition($moderationState, 'draft');
        $this->assertSame('draft', $moderationState->stateId);

        // Can submit for review again.
        $moderationState = $this->moderator->transition($moderationState, 'review');
        $this->assertSame('review', $moderationState->stateId);
    }

    #[Test]
    public function workflowStatesAreAccessible(): void
    {
        $this->assertTrue($this->editorialWorkflow->hasState('draft'));
        $this->assertTrue($this->editorialWorkflow->hasState('review'));
        $this->assertTrue($this->editorialWorkflow->hasState('published'));
        $this->assertFalse($this->editorialWorkflow->hasState('archived'));

        $draft = $this->editorialWorkflow->getState('draft');
        $this->assertNotNull($draft);
        $this->assertSame('Draft', $draft->label);
    }

    #[Test]
    public function createAccessRequiresPermission(): void
    {
        $editor = new User([
            'uid' => 5,
            'name' => 'editor',
            'permissions' => ['create article content'],
            'roles' => ['editor'],
        ]);

        $reader = new User([
            'uid' => 6,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $canCreate = $this->accessHandler->checkCreateAccess('node', 'article', $editor);
        $this->assertTrue($canCreate->isAllowed(), 'Editor with "create article content" should create articles.');

        $cannotCreate = $this->accessHandler->checkCreateAccess('node', 'article', $reader);
        $this->assertFalse($cannotCreate->isAllowed(), 'Reader without create permission should not create articles.');
    }
}
