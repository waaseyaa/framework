<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\User\Http\AuthController;

/**
 * Regression guard for #1525: AuthController::findUserByName() must call
 * accessCheck(false) on both the name-lookup and mail-fallback queries —
 * pre-authentication identity resolution has no request-scoped account;
 * the status=1 condition continues to exclude blocked users.
 *
 * Without accessCheck(false), SqlEntityQuery::execute() throws
 * MissingQueryAccountException under the fail-closed default introduced in
 * v0.1.0-alpha.181, causing every POST /api/auth/login to return HTTP 500.
 */
#[CoversClass(AuthController::class)]
final class AuthControllerFindUserByNameBindingTest extends TestCase
{
    #[Test]
    public function findUserByNameCallsAccessCheckFalseForBothQueries(): void
    {
        $query = new RecordingEntityQuery();

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn(null);

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $controller = new AuthController();
        // Pass an unknown name so both the name-query and mail-fallback fire.
        $controller->findUserByName($storage, $repository, 'unknown-user-that-will-not-match');

        self::assertSame(
            [false, false],
            $query->accessChecks,
            'AuthController::findUserByName() must call accessCheck(false) on both the name lookup and the mail fallback (regression #1525).',
        );
    }
}
