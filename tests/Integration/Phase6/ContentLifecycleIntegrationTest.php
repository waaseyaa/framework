<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Media\File;
use Waaseyaa\Media\InMemoryFileRepository;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Menu\MenuTreeBuilder;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Path\InMemoryPathAliasManager;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathProcessor;
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\State\MemoryState;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\TermAccessPolicy;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\User\User;
use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Validation\Constraint\SafeMarkup;
use Waaseyaa\Workflows\ContentModerationState;
use Waaseyaa\Workflows\ContentModerator;
use Waaseyaa\Workflows\Workflow;

/**
 * Full lifecycle integration test spanning most Layer 3 packages.
 *
 * Exercises: waaseyaa/node, waaseyaa/taxonomy, waaseyaa/media, waaseyaa/path,
 * waaseyaa/menu, waaseyaa/workflows, waaseyaa/access, waaseyaa/validation,
 * waaseyaa/queue, and waaseyaa/state working together end-to-end.
 */
#[CoversNothing]
final class ContentLifecycleIntegrationTest extends TestCase
{
    private EntityAccessHandler $accessHandler;
    private ContentModerator $moderator;
    private InMemoryPathAliasManager $aliasManager;
    private PathProcessor $pathProcessor;
    private MenuTreeBuilder $menuTreeBuilder;
    private InMemoryFileRepository $fileRepository;
    private EntityValidator $entityValidator;
    private InMemoryQueue $auditQueue;
    private MemoryState $state;

    protected function setUp(): void
    {
        // Access handler with both policies.
        $this->accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
            new TermAccessPolicy(),
        ]);

        // Workflow moderator.
        $workflow = new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'In Review'],
                'published' => ['label' => 'Published'],
            ],
            'transitions' => [
                'submit' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['review'], 'to' => 'published'],
                'unpublish' => ['label' => 'Unpublish', 'from' => ['published'], 'to' => 'draft'],
            ],
        ]);
        $this->moderator = new ContentModerator();
        $this->moderator->addWorkflow($workflow);

        // Path system.
        $this->aliasManager = new InMemoryPathAliasManager();
        $this->pathProcessor = new PathProcessor($this->aliasManager);

        // Menu system.
        $this->menuTreeBuilder = new MenuTreeBuilder();

        // File repository.
        $this->fileRepository = new InMemoryFileRepository();

        // Validation.
        $validator = Validation::createValidatorBuilder()->getValidator();
        $this->entityValidator = new EntityValidator($validator);

        // Audit trail.
        $this->auditQueue = new InMemoryQueue();
        $this->state = new MemoryState();
    }

    #[Test]
    public function fullContentLifecycleAcrossAllPackages(): void
    {
        $this->state->set('total_operations', 0);

        // ---- Step 1: Create configuration entities ----
        $articleType = new NodeType([
            'type' => 'article',
            'name' => 'Article',
            'description' => 'Articles with rich content.',
        ]);
        $this->assertSame('article', $articleType->id());

        $tagsVocab = new Vocabulary([
            'vid' => 'tags',
            'name' => 'Tags',
            'description' => 'Free-form tags.',
        ]);
        $this->assertSame('tags', $tagsVocab->id());

        $imageType = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'image',
        ]);
        $this->assertSame('image', $imageType->id());

        $mainMenu = new Menu([
            'id' => 'main',
            'label' => 'Main Navigation',
            'locked' => true,
        ]);
        $this->assertSame('main', $mainMenu->id());

        $workflow = $this->moderator->getWorkflow('editorial');
        $this->assertNotNull($workflow);

        // ---- Step 2: Create taxonomy terms ----
        $phpTerm = new Term(['tid' => 1, 'vid' => 'tags', 'name' => 'PHP']);
        $testingTerm = new Term(['tid' => 2, 'vid' => 'tags', 'name' => 'Testing']);

        $this->assertSame('PHP', $phpTerm->getName());
        $this->assertSame('Testing', $testingTerm->getName());

        // ---- Step 3: Create a media entity with a file ----
        $file = new File(
            uri: 'public://images/hero.jpg',
            filename: 'hero.jpg',
            mimeType: 'image/jpeg',
            size: 250000,
            ownerId: 1,
        );
        $this->fileRepository->save($file);

        $heroImage = new Media([
            'mid' => 1,
            'bundle' => 'image',
            'name' => 'Hero Image',
            'uid' => 1,
            'status' => true,
        ]);
        $heroImage->set('file_uri', $file->uri);

        $this->assertSame('Hero Image', $heroImage->getName());
        $loadedFile = $this->fileRepository->load($heroImage->get('file_uri'));
        $this->assertNotNull($loadedFile);
        $this->assertTrue($loadedFile->isImage());

        // ---- Step 4: Validate and create a node ----
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Integration Testing Guide',
            'uid' => 1,
            'status' => 0, // Start unpublished.
        ]);
        $node->set('field_tags', [$phpTerm->id(), $testingTerm->id()]);
        $node->set('field_image', $heroImage->id());
        $node->set('body', 'A comprehensive guide to integration testing.');

        // Validate.
        $violations = $this->entityValidator->validate($node, [
            'title' => [new NotEmpty()],
            'body' => [new SafeMarkup()],
        ]);
        $this->assertCount(0, $violations, 'Valid node should pass validation.');

        // Log creation in audit trail.
        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'created', [
            'title' => $node->getTitle(),
        ]));
        $this->incrementOperationCount();

        // ---- Step 5: Apply workflow moderation ----
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );
        $this->assertSame('draft', $moderationState->stateId);

        // Submit for review.
        $moderationState = $this->moderator->transition($moderationState, 'review');
        $this->assertSame('review', $moderationState->stateId);
        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'moderated', [
            'from' => 'draft',
            'to' => 'review',
        ]));
        $this->incrementOperationCount();

        // Publish.
        $moderationState = $this->moderator->transition($moderationState, 'published');
        $this->assertSame('published', $moderationState->stateId);
        $node->setPublished(true);
        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'moderated', [
            'from' => 'review',
            'to' => 'published',
        ]));
        $this->incrementOperationCount();

        // ---- Step 6: Create path alias for the node ----
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/1',
            'alias' => '/integration-testing-guide',
            'status' => true,
        ]);
        $this->aliasManager->addAlias($alias);

        $this->assertSame('/node/1', $this->pathProcessor->processInbound('/integration-testing-guide'));
        $this->assertSame('/integration-testing-guide', $this->pathProcessor->processOutbound('/node/1'));

        // ---- Step 7: Create menu link for the node ----
        $menuLinks = [
            new MenuLink([
                'id' => 1,
                'menu_name' => 'main',
                'title' => 'Home',
                'url' => '/home',
                'weight' => 0,
            ]),
            new MenuLink([
                'id' => 2,
                'menu_name' => 'main',
                'title' => 'Integration Testing Guide',
                'url' => '/integration-testing-guide',
                'weight' => 1,
            ]),
        ];

        $tree = $this->menuTreeBuilder->buildTree($menuLinks);
        $this->assertCount(2, $tree);
        $this->assertSame('Home', $tree[0]->link->getTitle());
        $this->assertSame('Integration Testing Guide', $tree[1]->link->getTitle());

        // Resolve menu link URL through path processor.
        $resolvedPath = $this->pathProcessor->processInbound($tree[1]->link->getUrl());
        $this->assertSame('/node/1', $resolvedPath);

        // ---- Step 8: Verify access control ----
        $author = new User([
            'uid' => 1,
            'name' => 'author',
            'permissions' => ['access content', 'view own unpublished content'],
            'roles' => ['authenticated'],
        ]);

        $reader = new User([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Published node is viewable by all with "access content".
        $this->assertTrue($this->accessHandler->check($node, 'view', $author)->isAllowed());
        $this->assertTrue($this->accessHandler->check($node, 'view', $reader)->isAllowed());

        // Terms are also viewable.
        $this->assertTrue($this->accessHandler->check($phpTerm, 'view', $reader)->isAllowed());

        // ---- Step 9: Verify audit trail ----
        $messages = $this->auditQueue->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame('created', $messages[0]->operation);
        $this->assertSame('moderated', $messages[1]->operation);
        $this->assertSame('moderated', $messages[2]->operation);

        // ---- Step 10: Verify state tracking ----
        $this->assertSame(3, $this->state->get('total_operations'));
    }

    #[Test]
    public function validationRejectsInvalidContent(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => '',
            'uid' => 1,
            'status' => 1,
        ]);
        $node->set('body', '<script>alert("xss")</script>');

        $violations = $this->entityValidator->validate($node, [
            'title' => [new NotEmpty()],
            'body' => [new SafeMarkup()],
        ]);

        $this->assertCount(2, $violations);

        $paths = [];
        for ($i = 0; $i < $violations->count(); $i++) {
            $paths[] = $violations->get($i)->getPropertyPath();
        }
        $this->assertContains('title', $paths);
        $this->assertContains('body', $paths);
    }

    #[Test]
    public function accessControlAcrossAllEntityTypes(): void
    {
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes', 'administer taxonomy'],
            'roles' => ['administrator'],
        ]);

        $regularUser = new User([
            'uid' => 10,
            'name' => 'regular',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Node access.
        $publishedNode = new Node(['nid' => 1, 'type' => 'article', 'title' => 'Public', 'uid' => 5, 'status' => 1]);
        $unpublishedNode = new Node(['nid' => 2, 'type' => 'article', 'title' => 'Draft', 'uid' => 5, 'status' => 0]);

        $this->assertTrue($this->accessHandler->check($publishedNode, 'view', $regularUser)->isAllowed());
        $this->assertFalse($this->accessHandler->check($unpublishedNode, 'view', $regularUser)->isAllowed());
        $this->assertTrue($this->accessHandler->check($unpublishedNode, 'view', $admin)->isAllowed());

        // Term access.
        $term = new Term(['tid' => 1, 'vid' => 'tags', 'name' => 'PHP']);

        $this->assertTrue($this->accessHandler->check($term, 'view', $regularUser)->isAllowed());
        $this->assertFalse($this->accessHandler->check($term, 'update', $regularUser)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'update', $admin)->isAllowed());

        // Admin has full access to all entity operations.
        $this->assertTrue($this->accessHandler->check($publishedNode, 'delete', $admin)->isAllowed());
        $this->assertTrue($this->accessHandler->check($term, 'delete', $admin)->isAllowed());
    }

    #[Test]
    public function workflowRejectsInvalidTransition(): void
    {
        $moderationState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        // Cannot jump from draft to published directly.
        $this->expectException(\InvalidArgumentException::class);
        $this->moderator->transition($moderationState, 'published');
    }

    #[Test]
    public function multipleNodesWithSharedTaxonomyAndMedia(): void
    {
        // Shared resources.
        $term = new Term(['tid' => 1, 'vid' => 'tags', 'name' => 'PHP']);
        $file = new File(
            uri: 'public://images/shared.jpg',
            filename: 'shared.jpg',
            mimeType: 'image/jpeg',
            size: 100000,
            ownerId: 1,
        );
        $this->fileRepository->save($file);
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'name' => 'Shared Image', 'uid' => 1]);
        $media->set('file_uri', $file->uri);

        // Two nodes sharing the same term and media.
        $node1 = new Node(['nid' => 1, 'type' => 'article', 'title' => 'Article One', 'uid' => 1, 'status' => 1]);
        $node1->set('field_tags', [$term->id()]);
        $node1->set('field_image', $media->id());

        $node2 = new Node(['nid' => 2, 'type' => 'article', 'title' => 'Article Two', 'uid' => 1, 'status' => 1]);
        $node2->set('field_tags', [$term->id()]);
        $node2->set('field_image', $media->id());

        // Both nodes reference the same term.
        $this->assertSame($node1->get('field_tags'), $node2->get('field_tags'));

        // Both nodes reference the same media.
        $this->assertSame($node1->get('field_image'), $node2->get('field_image'));

        // The shared file is accessible.
        $sharedFile = $this->fileRepository->load($media->get('file_uri'));
        $this->assertNotNull($sharedFile);
        $this->assertSame('shared.jpg', $sharedFile->filename);

        // Create path aliases for both.
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 1, 'path' => '/node/1', 'alias' => '/article-one', 'status' => true,
        ]));
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 2, 'path' => '/node/2', 'alias' => '/article-two', 'status' => true,
        ]));

        $this->assertSame('/node/1', $this->pathProcessor->processInbound('/article-one'));
        $this->assertSame('/node/2', $this->pathProcessor->processInbound('/article-two'));
    }

    #[Test]
    public function menuWithPathAliasesAndAccessControl(): void
    {
        $reader = new User([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Create nodes.
        $publicNode = new Node(['nid' => 1, 'type' => 'page', 'title' => 'About', 'uid' => 1, 'status' => 1]);
        $draftNode = new Node(['nid' => 2, 'type' => 'page', 'title' => 'Coming Soon', 'uid' => 1, 'status' => 0]);

        // Set up aliases.
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 1, 'path' => '/node/1', 'alias' => '/about', 'status' => true,
        ]));
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 2, 'path' => '/node/2', 'alias' => '/coming-soon', 'status' => true,
        ]));

        // Build menu with both nodes.
        $links = [
            new MenuLink(['id' => 1, 'menu_name' => 'main', 'title' => 'About', 'url' => '/about', 'weight' => 0]),
            new MenuLink(['id' => 2, 'menu_name' => 'main', 'title' => 'Coming Soon', 'url' => '/coming-soon', 'weight' => 1]),
        ];

        $tree = $this->menuTreeBuilder->buildTree($links);
        $this->assertCount(2, $tree);

        // Both menu links are in the tree.
        // But access control determines visibility:
        $this->assertTrue($this->accessHandler->check($publicNode, 'view', $reader)->isAllowed());
        $this->assertFalse($this->accessHandler->check($draftNode, 'view', $reader)->isAllowed());
    }

    #[Test]
    public function auditTrailTracksAllOperations(): void
    {
        $this->state->set('audit.node_creates', 0);
        $this->state->set('audit.node_updates', 0);
        $this->state->set('audit.node_deletes', 0);

        // Simulate node operations.
        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'created'));
        $this->state->set('audit.node_creates', $this->state->get('audit.node_creates', 0) + 1);

        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'updated'));
        $this->state->set('audit.node_updates', $this->state->get('audit.node_updates', 0) + 1);

        $this->auditQueue->dispatch(new EntityMessage('node', 2, 'created'));
        $this->state->set('audit.node_creates', $this->state->get('audit.node_creates', 0) + 1);

        $this->auditQueue->dispatch(new EntityMessage('node', 1, 'deleted'));
        $this->state->set('audit.node_deletes', $this->state->get('audit.node_deletes', 0) + 1);

        // Verify audit trail.
        $messages = $this->auditQueue->getMessages();
        $this->assertCount(4, $messages);

        // Verify state tracking.
        $this->assertSame(2, $this->state->get('audit.node_creates'));
        $this->assertSame(1, $this->state->get('audit.node_updates'));
        $this->assertSame(1, $this->state->get('audit.node_deletes'));

        // Verify bulk state retrieval.
        $stats = $this->state->getMultiple([
            'audit.node_creates',
            'audit.node_updates',
            'audit.node_deletes',
        ]);
        $this->assertSame(2, $stats['audit.node_creates']);
        $this->assertSame(1, $stats['audit.node_updates']);
        $this->assertSame(1, $stats['audit.node_deletes']);
    }

    #[Test]
    public function workflowConfigExport(): void
    {
        $workflow = $this->moderator->getWorkflow('editorial');
        $this->assertNotNull($workflow);

        $config = $workflow->toConfig();

        $this->assertSame('editorial', $config['id']);
        $this->assertSame('Editorial', $config['label']);
        $this->assertArrayHasKey('states', $config);
        $this->assertArrayHasKey('transitions', $config);
        $this->assertCount(3, $config['states']);
        $this->assertCount(3, $config['transitions']);
    }

    private function incrementOperationCount(): void
    {
        $current = $this->state->get('total_operations', 0);
        $this->state->set('total_operations', $current + 1);
    }
}
