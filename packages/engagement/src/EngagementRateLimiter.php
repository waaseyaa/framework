<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\Policy\FixedWindowLimiter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\RateLimit\EngagementWindowStorage;

/** Actor-scoped fixed-window quotas backed by canonical database state and process locks. @api */
final class EngagementRateLimiter
{
    /** @var array<string,array<string,int>> */
    private const array DEFAULT_LIMITS = [
        'comment' => ['minute' => 5, 'hour' => 60], 'edit' => ['minute' => 10, 'hour' => 120],
        'like' => ['minute' => 60, 'hour' => 600], 'key' => ['minute' => 60],
    ];

    private readonly EngagementWindowStorage $storage;
    private readonly LockFactory $locks;
    private readonly DBALDatabase $database;
    /** @var array<string,array<string,int>> */
    private readonly array $limits;

    /** @param array<string,array<string,int>> $limits */
    public function __construct(
        DBALDatabase $database,
        private readonly string $installationId,
        string $lockDir,
        array $limits = [],
    ) {
        $this->database = $database;
        $this->storage = new EngagementWindowStorage($database);
        $this->locks = new LockFactory(new FlockStore($lockDir));
        $this->limits = array_replace_recursive(self::DEFAULT_LIMITS, $limits);
        if ($installationId === '' || $lockDir === '') {
            throw new \InvalidArgumentException('Engagement limiter requires installation and lock directory identities.');
        }
    }

    public function consume(string $actorId, string $group): void
    {
        if ($actorId === '' || !isset($this->limits[$group]) || $this->limits[$group] === []) {
            throw new \InvalidArgumentException('Engagement quota actor or group is invalid.');
        }
        if ($this->database->getConnection()->isTransactionActive()) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Quota admission cannot run inside an active transaction.');
        }
        try {
            foreach ($this->limits[$group] as $window => $maximum) {
                $seconds = match ($window) {
                    'minute' => 60, 'hour' => 3600, default => throw new \InvalidArgumentException('Unsupported engagement quota window.'),
                };
                if ($maximum < 1) {
                    throw new \InvalidArgumentException('Engagement quota limits must be positive.');
                }
                $id = hash('sha256', implode("\0", ['engagement-quota.v1', $this->installationId, $actorId, $group, $window]));
                $lock = $this->locks->createLock('engagement-quota-' . $id, 0.0);
                if (!$lock->acquire(false)) {
                    throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Quota admission is temporarily contended.');
                }
                try {
                    $limiter = new FixedWindowLimiter($id, $maximum, new \DateInterval('PT' . $seconds . 'S'), $this->storage, null);
                    // Commit canonical limiter state before releasing the external lock.
                    $rate = $this->database->transactional(static fn() => $limiter->consume());
                    if (!$rate->isAccepted()) {
                        $retry = max(1, (int) $rate->getRetryAfter()->format('U') - time());
                        throw new EngagementException('RATE_LIMITED', 429, 'Engagement quota exceeded.', $retry);
                    }
                } finally {
                    $lock->release();
                }
            }
        } catch (EngagementException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Quota storage is temporarily unavailable.', null, $failure);
        }
    }

}
