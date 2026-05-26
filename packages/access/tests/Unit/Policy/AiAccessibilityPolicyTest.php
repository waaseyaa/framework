<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Policy\AiAccessibilityPolicy;
use Waaseyaa\Entity\EntityInterface;

/**
 * Unit tests for AiAccessibilityPolicy.
 *
 * Uses anonymous classes for EntityInterface and AccountInterface stubs
 * because PHPUnit createMock() cannot handle intersection types.
 */
#[CoversClass(AiAccessibilityPolicy::class)]
final class AiAccessibilityPolicyTest extends TestCase
{
    private AccountInterface $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal AccountInterface stub — policies under test don't interrogate the account.
        $this->account = new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
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

    // ── appliesTo() ───────────────────────────────────────────────────────────

    #[Test]
    public function appliesToReturnsTrueForMedia(): void
    {
        $policy = new AiAccessibilityPolicy();

        self::assertTrue($policy->appliesTo('media'));
    }

    #[Test]
    public function appliesToReturnsTrueForAttachment(): void
    {
        $policy = new AiAccessibilityPolicy();

        self::assertTrue($policy->appliesTo('attachment'));
    }

    #[Test]
    public function appliesToReturnsFalseForOtherEntityTypes(): void
    {
        $policy = new AiAccessibilityPolicy();

        self::assertFalse($policy->appliesTo('node'));
        self::assertFalse($policy->appliesTo('user'));
        self::assertFalse($policy->appliesTo(''));
    }

    // ── access(): 'no' + agent request → Forbidden ───────────────────────────

    #[Test]
    public function accessReturnsForbiddenWhenNoAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'no');

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isForbidden());
    }

    // ── access(): 'no' + non-agent request → Neutral ─────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenNoAndNonAgentRequest(): void
    {
        $request = $this->makeNonAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'no');

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── access(): 'no' + no request → Neutral ────────────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenNoAndNoRequest(): void
    {
        $policy = new AiAccessibilityPolicy(request: null);
        $entity = $this->makeEntity(aiAccessible: 'no');

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── access(): 'yes' → Neutral ────────────────────────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenYesAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'yes');

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── access(): 'inherit' → Neutral ────────────────────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenInheritAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'inherit');

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function accessReturnsNeutralWhenFieldMissing(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: null);

        $result = $policy->access($entity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── createAccess(): always Neutral ───────────────────────────────────────

    #[Test]
    public function createAccessReturnsNeutral(): void
    {
        $policy = new AiAccessibilityPolicy();

        $result = $policy->createAccess('media', 'image', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── fieldAccess(): mirrors access() ──────────────────────────────────────

    #[Test]
    public function fieldAccessReturnsForbiddenWhenNoAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'no');

        $result = $policy->fieldAccess($entity, 'name', 'view', $this->account);

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function fieldAccessReturnsNeutralWhenNoAndNonAgentRequest(): void
    {
        $request = $this->makeNonAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'no');

        $result = $policy->fieldAccess($entity, 'name', 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function fieldAccessReturnsNeutralWhenYesAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'yes');

        $result = $policy->fieldAccess($entity, 'name', 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function fieldAccessReturnsNeutralWhenInheritAndAgentRequest(): void
    {
        $request = $this->makeAgentRequest();
        $policy = new AiAccessibilityPolicy(request: $request);
        $entity = $this->makeEntity(aiAccessible: 'inherit');

        $result = $policy->fieldAccess($entity, 'filename', 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAgentRequest(): Request
    {
        $request = Request::create('/api/media/1');
        $request->attributes->set('_agent_run_id', 'run-uuid-abc123');

        return $request;
    }

    private function makeNonAgentRequest(): Request
    {
        return Request::create('/api/media/1');
    }

    private function makeEntity(?string $aiAccessible): EntityInterface
    {
        return new class($aiAccessible) implements EntityInterface {
            public function __construct(private readonly ?string $aiAccessibleValue) {}

            public function get(string $fieldName): mixed
            {
                if ($fieldName === 'ai_accessible') {
                    return $this->aiAccessibleValue;
                }

                return null;
            }

            public function set(string $fieldName, mixed $value): static
            {
                return $this;
            }

            public function id(): int|string|null
            {
                return 1;
            }

            public function uuid(): string
            {
                return 'test-uuid';
            }

            public function label(): string
            {
                return 'Test';
            }

            public function bundle(): string
            {
                return 'image';
            }

            public function getEntityTypeId(): string
            {
                return 'media';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function toArray(): array
            {
                return [];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
