<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\Follow;
use Waaseyaa\Engagement\Reaction;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Messaging\MessageThread;
use Waaseyaa\Messaging\ThreadMessage;
use Waaseyaa\Messaging\ThreadParticipant;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Note\Note;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\Tests\Support\ProtectedFieldRead;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserBlock;
use Waaseyaa\Workflows\Workflow;

/**
 * Ensures {@see \Waaseyaa\Entity\EntityBase::duplicate()} can reconstruct subclasses
 * whose public constructors accept the full parent arity (P3 / #1188 follow-up).
 */
#[CoversNothing]
final class ShippedEntityDuplicateReentryTest extends TestCase
{
    #[Test]
    public function duplicate_and_with_round_trip_core_content_entities(): void
    {
        $user = new User(['uid' => 1, 'name' => 'a', 'mail' => 'a@x.test']);
        $this->assertNotSame($user, $user->duplicate());
        $admin = AuthorizationPrincipalFactory::authenticated(permissions: ['administer users']);
        $this->assertSame('b', ProtectedFieldRead::run([new UserAccessPolicy()], $admin, $user->with('name', 'b')->getName(...)));
        $this->assertSame('a', ProtectedFieldRead::run([new UserAccessPolicy()], $admin, $user->getName(...)), 'with() must not mutate the original entity');

        $node = new Node(['type' => 'page', 'title' => 'T', 'uid' => 1]);
        $this->assertNotSame($node, $node->duplicate());
        $this->assertSame('U', $node->with('title', 'U')->getTitle());

        $note = new Note(['title' => 'N', 'body' => '']);
        $this->assertNotSame($note, $note->duplicate());

        $term = new Term(['vid' => 'tags', 'name' => 'tag1']);
        $this->assertNotSame($term, $term->duplicate());

        $media = new Media(['bundle' => 'file', 'name' => 'f']);
        $this->assertNotSame($media, $media->duplicate());

        $alias = new PathAlias(['path' => '/node/1', 'alias' => '/about']);
        $this->assertNotSame($alias, $alias->duplicate());

        $link = new MenuLink(['title' => 'Home', 'url' => '/', 'menu_name' => 'main']);
        $this->assertNotSame($link, $link->duplicate());

        $rel = new Relationship([
            'relationship_type' => 'mentions',
            'source_entity_type' => 'node',
            'source_entity_id' => 1,
            'target_entity_type' => 'node',
            'target_entity_id' => 2,
        ]);
        $this->assertNotSame($rel, $rel->duplicate());

        $reaction = new Reaction([
            'user_id' => 1,
            'target_type' => 'post',
            'target_id' => 1,
            'reaction_type' => 'like',
        ]);
        $this->assertNotSame($reaction, $reaction->duplicate());

        $comment = new Comment([
            'user_id' => 1,
            'target_type' => 'post',
            'target_id' => 1,
            'body' => 'Hello',
        ]);
        $this->assertNotSame($comment, $comment->duplicate());

        $follow = new Follow([
            'user_id' => 1,
            'target_type' => 'user',
            'target_id' => 2,
        ]);
        $this->assertNotSame($follow, $follow->duplicate());

        $block = new UserBlock(['blocker_id' => 1, 'blocked_id' => 2]);
        $this->assertNotSame($block, $block->duplicate());

        $thread = new MessageThread(['created_by' => 1]);
        $this->assertNotSame($thread, $thread->duplicate());

        $participant = new ThreadParticipant([
            'thread_id' => 1,
            'user_id' => 2,
            'thread_creator_id' => 1,
        ]);
        $this->assertNotSame($participant, $participant->duplicate());

        $message = new ThreadMessage([
            'thread_id' => 1,
            'sender_id' => 1,
            'body' => 'Hi',
        ]);
        $this->assertNotSame($message, $message->duplicate());
    }

    #[Test]
    public function duplicate_config_entities(): void
    {
        $nodeType = new NodeType([
            'type' => 'article',
            'name' => 'Article',
        ]);
        $this->assertNotSame($nodeType, $nodeType->duplicate());

        $voc = new Vocabulary([
            'vid' => 'tags',
            'name' => 'Tags',
        ]);
        $this->assertNotSame($voc, $voc->duplicate());

        $mediaType = new MediaType([
            'id' => 'image',
            'label' => 'Image',
            'source' => 'file',
        ]);
        $this->assertNotSame($mediaType, $mediaType->duplicate());

        $menu = new Menu([
            'id' => 'main',
            'label' => 'Main',
        ]);
        $this->assertNotSame($menu, $menu->duplicate());

        $workflow = new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
        ]);
        $this->assertNotSame($workflow, $workflow->duplicate());

        $pipeline = new Pipeline([
            'id' => 'default',
            'label' => 'Default',
        ]);
        $this->assertNotSame($pipeline, $pipeline->duplicate());
    }
}
