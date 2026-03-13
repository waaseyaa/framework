<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface;

/**
 * API controller for codified context session data.
 */
final class CodifiedContextController
{
    public function __construct(private readonly ?CodifiedContextStoreInterface $store = null) {}

    /**
     * GET /api/telescope/codified-context/sessions
     *
     * Groups cc_session entries by session_id, merges start/end, enriches with latest drift scores.
     *
     * @param array<string, mixed> $query
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function listSessions(array $query = []): array
    {
        if ($this->store === null) {
            return ['data' => []];
        }

        $sessionEntries = $this->store->queryByEventType('cc_session', limit: 500);

        $sessions = [];
        foreach ($sessionEntries as $entry) {
            $sessionId = $entry->sessionId;
            if (!isset($sessions[$sessionId])) {
                $sessions[$sessionId] = [
                    'session_id' => $sessionId,
                    'started_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                    'ended_at' => null,
                    'drift_score' => null,
                ];
            }

            $data = $entry->data;
            $phase = $data['phase'] ?? null;
            if ($phase === 'end') {
                $sessions[$sessionId]['ended_at'] = $entry->createdAt->format('Y-m-d H:i:s.u');
            }
            if (isset($data['drift_score'])) {
                $sessions[$sessionId]['drift_score'] = $data['drift_score'];
            }
        }

        // Enrich with latest validation drift scores.
        foreach (array_keys($sessions) as $sessionId) {
            $validations = $this->store->queryBySession($sessionId, limit: 1);
            foreach ($validations as $entry) {
                if ($entry->type === 'cc_validation' && isset($entry->data['drift_score'])) {
                    $sessions[$sessionId]['drift_score'] = $entry->data['drift_score'];
                }
            }
        }

        return ['data' => array_values($sessions)];
    }

    /**
     * GET /api/telescope/codified-context/sessions/{sessionId}
     *
     * @return array{data: array<string, mixed>|null}
     */
    public function getSession(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => null];
        }

        $entries = $this->store->queryBySession($sessionId, limit: 1);
        if ($entries === []) {
            return ['data' => null];
        }

        $entry = $entries[0];

        return [
            'data' => [
                'session_id' => $entry->sessionId,
                'type' => $entry->type,
                'created_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                'data' => $entry->data,
            ],
        ];
    }

    /**
     * GET /api/telescope/codified-context/sessions/{sessionId}/events
     *
     * Returns only cc_event type entries for the session.
     *
     * @return array{data: array<int, array<string, mixed>>}
     */
    public function getSessionEvents(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => []];
        }

        $allEntries = $this->store->queryBySession($sessionId, limit: 500);
        $events = [];

        foreach ($allEntries as $entry) {
            if ($entry->type === 'cc_event') {
                $events[] = [
                    'id' => $entry->id,
                    'session_id' => $entry->sessionId,
                    'type' => $entry->type,
                    'created_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                    'data' => $entry->data,
                ];
            }
        }

        return ['data' => $events];
    }

    /**
     * GET /api/telescope/codified-context/sessions/{sessionId}/validation
     *
     * Returns latest cc_validation entry for the session.
     *
     * @return array{data: array<string, mixed>|null}
     */
    public function getSessionValidation(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => null];
        }

        $allEntries = $this->store->queryBySession($sessionId, limit: 100);

        foreach ($allEntries as $entry) {
            if ($entry->type === 'cc_validation') {
                return [
                    'data' => [
                        'id' => $entry->id,
                        'session_id' => $entry->sessionId,
                        'created_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                        'report' => $entry->data,
                    ],
                ];
            }
        }

        return ['data' => null];
    }
}
