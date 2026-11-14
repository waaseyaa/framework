<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\Window;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementRateLimiter;
use Waaseyaa\Engagement\RateLimit\EngagementWindowStorage;

#[CoversClass(EngagementRateLimiter::class)]
final class EngagementRateLimiterTest extends TestCase
{
    public function test_limits_admit_to_boundary_then_refuse_and_reset_after_expiry(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE engagement_rate_limits (limiter_key VARCHAR(64) PRIMARY KEY, state_json TEXT NOT NULL, expires_at REAL NOT NULL)');
        $dir = sys_get_temp_dir().'/engagement-lock-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            $limiter = new EngagementRateLimiter($database, 'test-install', $dir, ['like' => ['minute' => 2, 'hour' => 2]]);
            $limiter->consume('actor', 'like');
            $limiter->consume('actor', 'like');
            try { $limiter->consume('actor', 'like'); self::fail('Quota must refuse over-limit attempts.'); }
            catch (EngagementException $e) { self::assertSame('RATE_LIMITED', $e->errorCode); self::assertSame(429, $e->httpStatus); self::assertGreaterThan(0, $e->retryAfter); }
            self::assertSame(2, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_rate_limits'));
            $database->getConnection()->executeStatement('UPDATE engagement_rate_limits SET expires_at = 0');
            $limiter->consume('actor', 'like');
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dir);
        }
    }

    public function test_active_caller_transaction_is_rejected_without_quota_write(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE engagement_rate_limits (limiter_key VARCHAR(64) PRIMARY KEY, state_json TEXT NOT NULL, expires_at REAL NOT NULL)');
        $dir = sys_get_temp_dir().'/engagement-lock-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $transaction = $database->transaction();
        try {
            try { (new EngagementRateLimiter($database, 'test-install', $dir))->consume('actor', 'comment'); self::fail('Active transaction must refuse.'); }
            catch (EngagementException $e) { self::assertSame('TEMPORARILY_UNAVAILABLE', $e->errorCode); }
            self::assertSame(0, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_rate_limits'));
        } finally {
            $transaction->rollBack();
            foreach (glob($dir.'/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dir);
        }
    }

    public function test_parallel_processes_share_quota_state_under_nonblocking_flock(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the cross-process quota contract.');
        }
        $base = sys_get_temp_dir().'/engagement-process-'.bin2hex(random_bytes(6));
        mkdir($base, 0700);
        $dbPath = $base.'/quota.sqlite';
        $lockDir = $base.'/locks';
        mkdir($lockDir, 0700);
        $database = DBALDatabase::createSqlite($dbPath);
        $database->getConnection()->executeStatement('CREATE TABLE engagement_rate_limits (limiter_key VARCHAR(64) PRIMARY KEY, state_json TEXT NOT NULL, expires_at REAL NOT NULL)');
        unset($database);
        $children = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $pid = pcntl_fork();
                if ($pid === -1) { self::fail('Could not fork quota worker.'); }
                if ($pid === 0) {
                    $outcome = 'accepted';
                    try {
                        $childDb = DBALDatabase::createSqlite($dbPath);
                        (new EngagementRateLimiter($childDb, 'test-install', $lockDir, ['like' => ['minute' => 1, 'hour' => 1]]))->consume('same-actor', 'like');
                    } catch (EngagementException $e) {
                        $outcome = $e->errorCode;
                    } catch (\Throwable) {
                        $outcome = 'unexpected-failure';
                    }
                    file_put_contents($base.'/result-'.$i, $outcome, LOCK_EX);
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); self::assertTrue(pcntl_wifexited($status)); }
            $outcomes = [file_get_contents($base.'/result-0'), file_get_contents($base.'/result-1')];
            self::assertSame(1, count(array_filter($outcomes, static fn(?string $outcome): bool => $outcome === 'accepted')));
            $refusals = array_values(array_filter($outcomes, static fn(?string $outcome): bool => $outcome !== 'accepted'));
            self::assertCount(1, $refusals);
            self::assertContains($refusals[0], ['RATE_LIMITED', 'TEMPORARILY_UNAVAILABLE']);

            $database = DBALDatabase::createSqlite($dbPath);
            try {
                (new EngagementRateLimiter($database, 'test-install', $lockDir, ['like' => ['minute' => 1, 'hour' => 1]]))->consume('same-actor', 'like');
                self::fail('A committed process admission must be visible to subsequent workers.');
            } catch (EngagementException $failure) {
                self::assertSame('RATE_LIMITED', $failure->errorCode);
            }
        } finally {
            foreach (glob($base.'/*') ?: [] as $path) { if (is_file($path)) { @unlink($path); } }
            @rmdir($lockDir);
            @rmdir($base);
        }
    }

    public function test_locked_symfony_window_serialization_shape_round_trips_as_json_scalars(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE engagement_rate_limits (limiter_key VARCHAR(64) PRIMARY KEY, state_json TEXT NOT NULL, expires_at REAL NOT NULL)');
        $now = microtime(true);
        $window = new Window('source-fit-window', 60, 5, $now);
        $window->add(2, $now);
        $shape = $window->__serialize();
        self::assertSame(['source-fit-window', pack('NN', 2, 60)], array_keys($shape));
        self::assertSame(5, $shape[pack('NN', 2, 60)]);

        $storage = new EngagementWindowStorage($database);
        $storage->save($window);
        $row = $database->getConnection()->fetchAssociative('SELECT state_json FROM engagement_rate_limits WHERE limiter_key = ?', ['source-fit-window']);
        self::assertSame(['interval' => 60, 'size' => 5, 'hits' => 2, 'timer' => $now], json_decode($row['state_json'], true, 8, JSON_THROW_ON_ERROR));
        $restored = $storage->fetch('source-fit-window');
        self::assertInstanceOf(Window::class, $restored);
        self::assertSame(2, $restored->getHitCount());
    }
}
