<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Fence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Fence\DatabaseFenceGuard;
use Waaseyaa\Scheduler\Fence\StaleFenceException;

final class DatabaseFenceGuardTest extends TestCase
{
    private DBALDatabase $database;
    private DatabaseFenceGuard $guard;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_effect_fences (
                resource_key VARCHAR(512) NOT NULL,
                fence_domain VARCHAR(255) NOT NULL,
                accepted_fence INTEGER NOT NULL,
                effect_id VARCHAR(255) NOT NULL,
                PRIMARY KEY (resource_key, fence_domain)
            )
            SQL);
        $this->guard = new DatabaseFenceGuard($this->database);
    }

    #[Test]
    public function higherFenceWinsAndStaleOwnerCannotCommit(): void
    {
        $effects = [];
        self::assertTrue($this->guard->execute('entity:node:1', 'retention', 4, 'first', function () use (&$effects): void {
            $effects[] = 'first';
        }));
        self::assertTrue($this->guard->execute('entity:node:1', 'retention', 7, 'second', function () use (&$effects): void {
            $effects[] = 'second';
        }));

        try {
            $this->guard->execute('entity:node:1', 'retention', 4, 'late', function () use (&$effects): void {
                $effects[] = 'late';
            });
            self::fail('A stale fence committed.');
        } catch (StaleFenceException) {
        }

        self::assertSame(['first', 'second'], $effects);
    }

    #[Test]
    public function exactReplayIsNoOpButDistinctEqualFenceIsRejected(): void
    {
        $runs = 0;
        self::assertTrue($this->guard->execute('resource', 'domain', 2, 'effect-a', function () use (&$runs): void {
            ++$runs;
        }));
        self::assertFalse($this->guard->execute('resource', 'domain', 2, 'effect-a', function () use (&$runs): void {
            ++$runs;
        }));
        self::assertSame(1, $runs);

        $this->expectException(StaleFenceException::class);
        $this->guard->execute('resource', 'domain', 2, 'effect-b', static function (): void {});
    }

    #[Test]
    public function failedEffectRollsBackTheFenceClaim(): void
    {
        try {
            $this->guard->execute('resource', 'domain', 9, 'failed', static function (): void {
                throw new \RuntimeException('effect failed');
            });
            self::fail('Expected effect failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('effect failed', $error->getMessage());
        }

        $ran = false;
        self::assertTrue($this->guard->execute('resource', 'domain', 8, 'retry', function () use (&$ran): void {
            $ran = true;
        }));
        self::assertTrue($ran);
    }

    #[Test]
    public function higherFenceRecoveryDoesNotReplayTheSameOccurrenceEffect(): void
    {
        $runs = 0;
        self::assertTrue($this->guard->execute('resource', 'domain', 3, 'occurrence:effect', function () use (&$runs): void {
            ++$runs;
        }));
        self::assertFalse($this->guard->execute('resource', 'domain', 8, 'occurrence:effect', function () use (&$runs): void {
            ++$runs;
        }));
        self::assertSame(1, $runs);

        $this->expectException(StaleFenceException::class);
        $this->guard->execute('resource', 'domain', 7, 'late-different-effect', static function (): void {});
    }
}
