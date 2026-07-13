<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityType;

/**
 * CW-v1 option-1 PR-3 (working-copy awareness, #1920): `entity.update`'s
 * MUTATION TARGET is the working copy, not the `find()` (served/base-row)
 * entity — mirroring the JSON:API PATCH / GraphQL update / FieldAutoSave
 * surfaces. Under default-revision discipline `find()` returns the PUBLISHED
 * revision; applying agent edits to it would fork a new tip from published
 * content and silently displace an in-flight human draft (lost-update).
 */
#[CoversClass(EntityUpdateTool::class)]
final class EntityUpdateToolWorkingCopyTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);
    }

    private function account(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    #[Test]
    public function update_mutates_the_working_copy_not_the_served_entity_when_a_draft_tip_exists(): void
    {
        // Served (base-row) entity: the published revision, rev 1.
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Published title', 'revision_id' => 1]));
        // Diverged working copy: an in-flight human draft, rev 2.
        $this->repo->seedWorkingCopy(new ToolTestEntity(['id' => '1', 'title' => 'Draft title', 'revision_id' => 2]));

        $tool = new EntityUpdateTool($this->etm);
        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'Agent edit']],
            $this->account(),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $saved = $this->repo->saved[0];
        $this->assertSame(2, $saved->get('revision_id'), 'the WORKING COPY (draft tip) is the mutation target, so the draft is continued, not displaced');
        $this->assertSame('Agent edit', $saved->get('title'));
    }

    #[Test]
    public function update_falls_back_to_the_served_entity_when_no_working_copy_diverges(): void
    {
        // No diverged working copy seeded: loadWorkingCopy() ≡ find(),
        // pinning behavior-identity for every undisciplined entity.
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original', 'revision_id' => 1]));

        $tool = new EntityUpdateTool($this->etm);
        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'Agent edit']],
            $this->account(),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $this->assertSame(1, $this->repo->saved[0]->get('revision_id'));
        $this->assertSame('Agent edit', $this->repo->saved[0]->get('title'));
    }
}
