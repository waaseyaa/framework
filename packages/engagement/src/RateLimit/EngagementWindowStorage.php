<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\RateLimit;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Waaseyaa\Database\DBALDatabase;

/** Persists only the documented fixed-window scalars, never serialized PHP objects. */
final readonly class EngagementWindowStorage implements StorageInterface
{
    public function __construct(private DBALDatabase $database) {}

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT state_json, expires_at FROM engagement_rate_limits WHERE limiter_key = ?',
            [$limiterStateId],
        );
        if ($row === false || (float) $row['expires_at'] <= microtime(true)) {
            if ($row !== false) {
                $this->delete($limiterStateId);
            }
            return null;
        }
        $state = json_decode((string) $row['state_json'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_int($state['interval'] ?? null) || !is_int($state['size'] ?? null)
            || !is_int($state['hits'] ?? null) || !(is_int($state['timer'] ?? null) || is_float($state['timer'] ?? null))
            || $state['interval'] < 1 || $state['interval'] > 4_294_967_295
            || $state['size'] < 1 || $state['size'] > 4_294_967_295
            || $state['hits'] < 0 || $state['hits'] > 4_294_967_295
            || !is_finite((float) $state['timer']) || $state['timer'] < 0) {
            throw new \RuntimeException('Engagement quota state is malformed.');
        }
        $window = new Window($limiterStateId, $state['interval'], $state['size'], $state['timer']);
        $window->add($state['hits'], $state['timer']);
        return $window;
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        if (!$limiterState instanceof Window) {
            throw new \InvalidArgumentException('Engagement quota storage accepts only Symfony fixed-window state.');
        }
        // Symfony 7.4's internal Window persists exactly [id => timer, pack(hits, interval) => size].
        $serialized = $limiterState->__serialize();
        if (count($serialized) !== 2) {
            throw new \RuntimeException('Symfony fixed-window state shape changed.');
        }
        $key = array_key_first($serialized);
        $packed = array_key_last($serialized);
        $rawTimer = $serialized[$key];
        $rawSize = $serialized[$packed];
        if ($key !== $limiterState->getId() || !(is_float($rawTimer) || is_int($rawTimer))
            || !is_finite((float) $rawTimer) || $rawTimer < 0 || !is_string($packed) || strlen($packed) !== 8
            || !is_int($rawSize) || $rawSize < 1 || $rawSize > 4_294_967_295) {
            throw new \RuntimeException('Symfony fixed-window state fields changed or are outside supported ranges.');
        }
        $timer = (float) $rawTimer;
        $unpacked = unpack('Na/Nb', $packed);
        if (!is_array($unpacked) || $unpacked['a'] > 4_294_967_295 || $unpacked['b'] < 1 || $unpacked['b'] > 4_294_967_295) {
            throw new \RuntimeException('Symfony fixed-window state could not be decoded.');
        }
        $hits = (int) $unpacked['a'];
        $interval = (int) $unpacked['b'];
        $size = (int) $serialized[$packed];
        $json = json_encode(['interval' => $interval, 'size' => $size, 'hits' => $hits, 'timer' => $timer], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $expires = $timer + $interval * max(1, (int) ceil($hits / $size));
        $key = $limiterState->getId();
        $changed = $this->database->update('engagement_rate_limits')
            ->fields(['state_json' => $json, 'expires_at' => $expires])
            ->condition('limiter_key', $key)->execute();
        if ($changed === 0) {
            try {
                $this->database->insert('engagement_rate_limits')
                    ->values(['limiter_key' => $key, 'state_json' => $json, 'expires_at' => $expires])->execute();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $race) {
                if ($this->database->getConnection()->isTransactionActive()) {
                    throw $race;
                }
                $this->database->update('engagement_rate_limits')
                    ->fields(['state_json' => $json, 'expires_at' => $expires])
                    ->condition('limiter_key', $key)->execute();
            }
        }
    }

    public function delete(string $limiterStateId): void
    {
        $this->database->getConnection()->executeStatement('DELETE FROM engagement_rate_limits WHERE limiter_key = ?', [$limiterStateId]);
    }
}
