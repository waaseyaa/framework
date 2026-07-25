<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Maintenance;

/**
 * Read/write access to the canonical maintenance flag.
 *
 * The flag is a single JSON state file — deliberately NOT a database row or a
 * config store that depends on the database — so maintenance mode survives a
 * database that is mid-swap (#2122). Writes are atomic (write-to-temp then
 * rename) per the atomic-file-writes gotcha; {@see deactivate()} unlinks it.
 *
 * Read semantics are FAIL-CLOSED: an absent flag reads as "up", but a flag that
 * exists yet is unreadable, non-JSON, or missing the `active` key reads as "in
 * maintenance" (ambiguous). Only a present, valid `active:false` reads as off.
 *
 * @api
 */
final class MaintenanceState
{
    private const int DEFAULT_RETRY_AFTER = 120;

    public function __construct(
        private readonly string $flagPath,
    ) {}

    public function flagPath(): string
    {
        return $this->flagPath;
    }

    public function read(): MaintenanceStatus
    {
        if (!is_file($this->flagPath)) {
            return MaintenanceStatus::inactive();
        }

        $raw = @file_get_contents($this->flagPath);
        if ($raw === false) {
            // Present but unreadable → fail closed.
            return $this->ambiguous();
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->ambiguous();
        }

        if (!is_array($data) || !array_key_exists('active', $data)) {
            // Partial / wrong-shaped payload → fail closed.
            return $this->ambiguous();
        }

        if ($data['active'] === false) {
            return MaintenanceStatus::inactive();
        }

        if ($data['active'] !== true) {
            // Present but wrong-typed `active` (e.g. 1, "true", null) is a
            // corrupt/ambiguous flag — fail CLOSED, never open. Only a strict
            // boolean `false` (or an absent flag) reads as "up".
            return $this->ambiguous();
        }

        $retryAfter = isset($data['retry_after']) && is_int($data['retry_after']) && $data['retry_after'] > 0
            ? $data['retry_after']
            : self::DEFAULT_RETRY_AFTER;
        $message = isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
            ? $data['message']
            : null;
        $since = isset($data['since']) && is_string($data['since']) && $data['since'] !== ''
            ? $data['since']
            : null;

        return new MaintenanceStatus(
            active: true,
            ambiguous: false,
            retryAfter: $retryAfter,
            message: $message,
            since: $since,
        );
    }

    /**
     * Enable maintenance mode. Idempotent — overwrites any existing flag.
     *
     * @throws \RuntimeException when the flag directory or file cannot be written.
     */
    public function activate(int $retryAfter, ?string $message): void
    {
        $dir = \dirname($this->flagPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create maintenance flag directory "%s".', $dir));
        }

        $payload = [
            'active' => true,
            'retry_after' => $retryAfter,
            'since' => gmdate('c'),
        ];
        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Atomic write-then-rename so a reader never observes a partial file
        // (which would otherwise fail closed and needlessly 503).
        $tmp = $this->flagPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write maintenance flag temp file "%s".', $tmp));
        }
        if (!@rename($tmp, $this->flagPath)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Unable to move maintenance flag into place at "%s".', $this->flagPath));
        }
    }

    /**
     * Disable maintenance mode. Idempotent — a missing flag is a no-op.
     *
     * @throws \RuntimeException when an existing flag cannot be removed.
     */
    public function deactivate(): void
    {
        if (is_file($this->flagPath) && !@unlink($this->flagPath)) {
            throw new \RuntimeException(sprintf('Unable to remove maintenance flag "%s".', $this->flagPath));
        }
    }

    private function ambiguous(): MaintenanceStatus
    {
        return new MaintenanceStatus(active: true, ambiguous: true, retryAfter: self::DEFAULT_RETRY_AFTER);
    }
}
