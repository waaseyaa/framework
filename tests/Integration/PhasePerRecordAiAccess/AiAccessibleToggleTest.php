<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhasePerRecordAiAccess;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Policy\AiAccessibilityPolicy;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Media\Media;

/**
 * Integration tests for the per-file AI accessibility toggle (WP03, M-A5).
 *
 * Validates the full policy behaviour for both entity types without
 * spinning up a full kernel or database — the policy depends only on
 * entity field values and the Symfony Request, both of which can be
 * instantiated directly.
 *
 * Refs: FR-008, FR-009, FR-010, FR-011, C-004, gap-matrix-A5.
 */
#[CoversNothing]
final class AiAccessibleToggleTest extends TestCase
{
    private AccountInterface $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }
        };
    }

    // ── Media entity: getAiAccessible() / setAiAccessible() ──────────────────

    #[Test]
    public function mediaAiAccessibleDefaultsToInherit(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image']);

        self::assertSame('inherit', $media->getAiAccessible());
    }

    #[Test]
    public function mediaAiAccessibleRoundTrip(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image']);

        $media->setAiAccessible('no');
        self::assertSame('no', $media->getAiAccessible());

        $media->setAiAccessible('yes');
        self::assertSame('yes', $media->getAiAccessible());

        $media->setAiAccessible('inherit');
        self::assertSame('inherit', $media->getAiAccessible());
    }

    // ── Attachment entity: getAiAccessible() / setAiAccessible() ─────────────

    #[Test]
    public function attachmentAiAccessibleDefaultsToInherit(): void
    {
        $attachment = new Attachment(['id' => 1, 'filename' => 'file.pdf']);

        self::assertSame('inherit', $attachment->getAiAccessible());
    }

    #[Test]
    public function attachmentAiAccessibleRoundTrip(): void
    {
        $attachment = new Attachment(['id' => 1, 'filename' => 'file.pdf']);

        $attachment->setAiAccessible('no');
        self::assertSame('no', $attachment->getAiAccessible());

        $attachment->setAiAccessible('yes');
        self::assertSame('yes', $attachment->getAiAccessible());
    }

    // ── Policy: agent request + 'no' → Forbidden ─────────────────────────────

    #[Test]
    public function policyForbidsAgentAccessToMediaSetToNo(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'ai_accessible' => 'no']);

        $result = $policy->access($media, 'view', $this->account);

        self::assertTrue($result->isForbidden(), 'Agent must be forbidden from media with ai_accessible=no');
    }

    #[Test]
    public function policyForbidsAgentAccessToAttachmentSetToNo(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $attachment = new Attachment(['id' => 1, 'filename' => 'secret.pdf', 'ai_accessible' => 'no']);

        $result = $policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isForbidden(), 'Agent must be forbidden from attachment with ai_accessible=no');
    }

    // ── Policy: agent request + 'inherit' → Neutral (C-004 fallback) ─────────

    #[Test]
    public function policyReturnsNeutralForInheritPreservingDefault(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $media = new Media(['mid' => 1, 'bundle' => 'image']); // ai_accessible defaults to 'inherit'

        $result = $policy->access($media, 'view', $this->account);

        self::assertTrue(
            $result->isNeutral(),
            'Until M-A4 ships, inherit must be neutral (access-preserving default, C-004)',
        );
    }

    // ── Policy: non-agent request + 'no' → Neutral ───────────────────────────

    #[Test]
    public function policyDoesNotBlockNonAgentRequestEvenWhenSetToNo(): void
    {
        $request = Request::create('/api/media/1'); // no _agent_run_id attribute
        $policy = new AiAccessibilityPolicy(request: $request);
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'ai_accessible' => 'no']);

        $result = $policy->access($media, 'view', $this->account);

        self::assertTrue(
            $result->isNeutral(),
            'Non-agent human requests must not be blocked by AiAccessibilityPolicy',
        );
    }

    // ── Policy: field-level access mirrors entity-level ───────────────────────

    #[Test]
    public function policyForbidsFieldAccessForAgentWhenSetToNo(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'ai_accessible' => 'no']);

        $result = $policy->fieldAccess($media, 'name', 'view', $this->account);

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function policyAllowsFieldAccessWhenSetToYes(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'ai_accessible' => 'yes']);

        $result = $policy->fieldAccess($media, 'name', 'view', $this->account);

        self::assertTrue($result->isNeutral(), 'Field should be accessible (neutral = accessible, open-by-default)');
    }

    // ── AiAccessibleField: JSON Schema output ─────────────────────────────────

    #[Test]
    public function aiAccessibleFieldJsonSchemaIsCorrect(): void
    {
        $schema = \Waaseyaa\Field\FieldType\AiAccessibleField::jsonSchema();

        self::assertSame('string', $schema['type']);
        self::assertSame(['yes', 'no', 'inherit'], $schema['enum']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAgentRequest(): Request
    {
        $request = Request::create('/api/media/1');
        $request->attributes->set('_agent_run_id', 'integration-test-run-id');

        return $request;
    }
}
