<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\User\UserBlockService;

/**
 * Regression guard for #1527: UserBlockService::isBlocked() must call
 * accessCheck(false) — block-relationship existence is an integrity primitive
 * that cannot be gated by either party's view policy on the user_block entity
 * without breaking the safety semantics this service exists to enforce.
 *
 * Without accessCheck(false), SqlEntityQuery::execute() throws
 * MissingQueryAccountException under the fail-closed default introduced in
 * v0.1.0-alpha.181.
 */
#[CoversClass(UserBlockService::class)]
final class UserBlockServiceBindingTest extends TestCase
{
    #[Test]
    public function isBlockedCallsAccessCheckFalseForIntegrityLookup(): void
    {
        $query = new RecordingEntityQuery();

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->expects(self::once())->method('getRepository')->with('user_block')->willReturn($repository);

        $service = new UserBlockService($etm);
        $service->isBlocked(1, 2);

        self::assertContains(
            false,
            $query->accessChecks,
            'UserBlockService::isBlocked() must call accessCheck(false) for system-context integrity lookup (regression #1527).',
        );
    }
}
