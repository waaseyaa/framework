<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Storage;

use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\TelescopeEntry;

/**
 * JSONL file-based storage backend for codified context entries.
 *
 * Each entry is stored as a JSON line in $directory/telescope_cc.jsonl.
 */
final class JsonlCodifiedContextStore implements CodifiedContextStoreInterface
{
    private string $filePath;

    public function __construct(string $directory)
    {
        $this->filePath = rtrim($directory, '/') . '/telescope_cc.jsonl';
    }

    public function store(string $type, array $data): void
    {
        $id = bin2hex(random_bytes(16));
        $sessionId = $data['session_id'] ?? '';
        $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $line = json_encode([
            'id' => $id,
            'type' => $type,
            'session_id' => $sessionId,
            'data' => $data,
            'created_at' => $createdAt,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function query(string $type, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->readAllRows();
        $filtered = array_filter($rows, fn(array $row) => $row['type'] === $type);
        $filtered = array_values($filtered);

        // Most recent first.
        usort($filtered, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        $sliced = array_slice($filtered, $offset, $limit);

        return array_map(fn(array $row) => new TelescopeEntry(
            type: $row['type'],
            data: $row['data'],
            id: $row['id'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        ), $sliced);
    }

    public function queryBySession(string $sessionId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->readAllRows();
        $filtered = array_filter($rows, fn(array $row) => $row['session_id'] === $sessionId);
        $filtered = array_values($filtered);

        usort($filtered, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        $sliced = array_slice($filtered, $offset, $limit);

        return array_map(fn(array $row) => CodifiedContextEntry::fromArray($row), $sliced);
    }

    public function queryByEventType(string $eventType, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->readAllRows();
        $filtered = array_filter(
            $rows,
            fn(array $row) => ($row['data']['event_type'] ?? null) === $eventType,
        );
        $filtered = array_values($filtered);

        usort($filtered, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        $sliced = array_slice($filtered, $offset, $limit);

        return array_map(fn(array $row) => CodifiedContextEntry::fromArray($row), $sliced);
    }

    public function queryByTimeRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array
    {
        $fromStr = $from->format('Y-m-d H:i:s.u');
        $toStr = $to->format('Y-m-d H:i:s.u');

        $rows = $this->readAllRows();
        $filtered = array_filter(
            $rows,
            fn(array $row) => $row['created_at'] >= $fromStr && $row['created_at'] <= $toStr,
        );
        $filtered = array_values($filtered);

        usort($filtered, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        $sliced = array_slice($filtered, 0, $limit);

        return array_map(fn(array $row) => CodifiedContextEntry::fromArray($row), $sliced);
    }

    public function queryByDriftSeverity(string $severity, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->readAllRows();
        $filtered = array_filter(
            $rows,
            fn(array $row) => ($row['data']['severity'] ?? null) === $severity,
        );
        $filtered = array_values($filtered);

        usort($filtered, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        $sliced = array_slice($filtered, $offset, $limit);

        return array_map(fn(array $row) => CodifiedContextEntry::fromArray($row), $sliced);
    }

    public function prune(\DateTimeInterface $before): int
    {
        $beforeStr = $before->format('Y-m-d H:i:s.u');
        $rows = $this->readAllRows();

        $kept = array_filter($rows, fn(array $row) => $row['created_at'] >= $beforeStr);
        $pruned = count($rows) - count($kept);

        if ($pruned > 0) {
            $this->writeAllRows(array_values($kept));
        }

        return $pruned;
    }

    public function clear(): void
    {
        file_put_contents($this->filePath, '', LOCK_EX);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readAllRows(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false || $content === '') {
            return [];
        }

        $rows = [];
        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            try {
                $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Skip malformed lines.
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeAllRows(array $rows): void
    {
        $lines = array_map(
            fn(array $row) => json_encode($row, JSON_THROW_ON_ERROR),
            $rows,
        );
        file_put_contents($this->filePath, implode("\n", $lines) . (count($lines) > 0 ? "\n" : ''), LOCK_EX);
    }
}
