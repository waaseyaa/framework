<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lease;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DBALDatabase;

/**
 * Durable renewable lease authority. Schema installation belongs to DB-02.
 *
 * @api
 */
final class DatabaseLease
{
    private const string LEASE_TABLE = 'waaseyaa_scheduler_leases';
    private const string FENCE_TABLE = 'waaseyaa_scheduler_fence_sequence';

    public function __construct(private readonly DBALDatabase $database) {}

    public function acquire(string $domain, int $ttlMs): ?LeaseHandle
    {
        $this->assertInput($domain, $ttlMs);

        return $this->database->getConnection()->transactional(function () use ($domain, $ttlMs): ?LeaseHandle {
            $now = $this->databaseNowMs();
            $row = $this->leaseRow($domain);
            if ($row === null) {
                try {
                    $this->database->insert(self::LEASE_TABLE)->values([
                        'lease_domain' => $domain,
                        'owner_token' => null,
                        'expires_at_ms' => 0,
                        'fencing_token' => 0,
                        'renewal_generation' => 0,
                        'renewal_nonce' => null,
                    ])->execute();
                } catch (UniqueConstraintViolationException) {
                    // Another transaction created the stable domain row.
                }
                $row = $this->leaseRow($domain);
                if ($row === null) {
                    throw new \RuntimeException("Lease authority row '{$domain}' was not created.");
                }
            }
            if ($row['owner_token'] !== null && (int) $row['expires_at_ms'] > $now) {
                return null;
            }

            $fence = $this->nextFence();
            $owner = bin2hex(random_bytes(32));
            $nonce = bin2hex(random_bytes(32));
            $generation = (int) $row['renewal_generation'] + 1;
            $expires = $now + $ttlMs;
            $affected = $this->database->update(self::LEASE_TABLE)->fields([
                'owner_token' => $owner,
                'expires_at_ms' => $expires,
                'fencing_token' => $fence,
                'renewal_generation' => $generation,
                'renewal_nonce' => $nonce,
            ])->condition('lease_domain', $domain)
                ->condition('fencing_token', (int) $row['fencing_token'])
                ->condition('renewal_generation', (int) $row['renewal_generation'])
                ->execute();
            if ($affected !== 1) {
                throw new \RuntimeException("Ambiguous lease acquisition for '{$domain}'.");
            }

            return new LeaseHandle($domain, $owner, $fence, $generation, $nonce, $expires);
        });
    }

    public function renew(LeaseHandle $handle, int $ttlMs): LeaseHandle
    {
        $this->assertInput($handle->domain, $ttlMs);

        return $this->database->getConnection()->transactional(function () use ($handle, $ttlMs): LeaseHandle {
            $now = $this->databaseNowMs();
            if ($handle->expiresAtMs <= $now) {
                throw new LeaseLostException("Lease '{$handle->domain}' has expired.");
            }
            $generation = $handle->renewalGeneration + 1;
            $nonce = bin2hex(random_bytes(32));
            $expires = max($now + $ttlMs, $handle->expiresAtMs + 1);
            $affected = $this->database->update(self::LEASE_TABLE)->fields([
                'expires_at_ms' => $expires,
                'renewal_generation' => $generation,
                'renewal_nonce' => $nonce,
            ])->condition('lease_domain', $handle->domain)
                ->condition('owner_token', $handle->ownerToken)
                ->condition('fencing_token', $handle->fence)
                ->condition('renewal_generation', $handle->renewalGeneration)
                ->condition('renewal_nonce', $handle->renewalNonce)
                ->condition('expires_at_ms', $now, '>')
                ->execute();
            if ($affected !== 1) {
                throw new LeaseLostException("Lease '{$handle->domain}' is no longer owned by this worker.");
            }

            return new LeaseHandle(
                $handle->domain,
                $handle->ownerToken,
                $handle->fence,
                $generation,
                $nonce,
                $expires,
            );
        });
    }

    public function release(LeaseHandle $handle): void
    {
        $this->database->update(self::LEASE_TABLE)->fields([
            'owner_token' => null,
            'expires_at_ms' => 0,
            'renewal_nonce' => null,
        ])->condition('lease_domain', $handle->domain)
            ->condition('owner_token', $handle->ownerToken)
            ->condition('fencing_token', $handle->fence)
            ->condition('renewal_generation', $handle->renewalGeneration)
            ->condition('renewal_nonce', $handle->renewalNonce)
            ->execute();
    }

    private function databaseNowMs(): int
    {
        return (int) $this->database->getConnection()->fetchOne(
            "SELECT CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)",
        );
    }

    /** @return array<string, mixed>|null */
    private function leaseRow(string $domain): ?array
    {
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT owner_token, expires_at_ms, fencing_token, renewal_generation, renewal_nonce'
            . ' FROM ' . self::LEASE_TABLE . ' WHERE lease_domain = ?',
            [$domain],
        );

        return $row === false ? null : $row;
    }

    private function nextFence(): int
    {
        $current = $this->database->getConnection()->fetchOne(
            'SELECT next_fence FROM ' . self::FENCE_TABLE . ' WHERE singleton_id = 1',
        );
        if ($current === false) {
            throw new \RuntimeException('Scheduler fence sequence is not initialized.');
        }
        $fence = (int) $current;
        if ($fence < 1 || $fence === PHP_INT_MAX) {
            throw new \OverflowException('Scheduler fencing sequence is invalid or exhausted.');
        }
        $affected = $this->database->update(self::FENCE_TABLE)
            ->fields(['next_fence' => $fence + 1])
            ->condition('singleton_id', 1)
            ->condition('next_fence', $fence)
            ->execute();
        if ($affected !== 1) {
            throw new \RuntimeException('Scheduler fencing sequence update was ambiguous.');
        }

        return $fence;
    }

    private function assertInput(string $domain, int $ttlMs): void
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('Lease domain must not be empty.');
        }
        if ($ttlMs < 2 || $ttlMs > 86_400_000) {
            throw new \InvalidArgumentException('Lease TTL must be between 2ms and 24 hours.');
        }
    }
}
