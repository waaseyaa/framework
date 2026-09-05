<?php

declare(strict_types=1);

/**
 * Ordering-independent complete-set controls for delivery-agent events (#2902).
 *
 * These rules apply to the union of frozen v1 events and immutable batches.
 * They deliberately do not define replay/total order — that remains under
 * design review. Callers pass already-decoded event maps keyed by event_id
 * or a list of events; batch immutability compares path → content hashes.
 */

/**
 * @param list<array<string, mixed>> $events
 * @return list<string>
 */
function delivery_agent_event_set_errors(array $events): array
{
    $errors = [];
    /** @var array<string, array<string, mixed>> $byId */
    $byId = [];
    /** @var array<string, int> $firstIndex */
    $firstIndex = [];

    foreach ($events as $index => $event) {
        if (!is_array($event) || !isset($event['event_id']) || !is_string($event['event_id'])) {
            $errors[] = sprintf('event[%d] is missing a string event_id', $index);
            continue;
        }
        $id = $event['event_id'];
        if (isset($byId[$id])) {
            $prior = json_encode($byId[$id], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $current = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($prior === $current) {
                $errors[] = sprintf(
                    'event[%d] duplicates event_id %s first seen at event[%d]',
                    $index,
                    $id,
                    $firstIndex[$id],
                );
            } else {
                $errors[] = sprintf(
                    'event[%d] conflicts with event_id %s first seen at event[%d]',
                    $index,
                    $id,
                    $firstIndex[$id],
                );
            }
            continue;
        }
        $byId[$id] = $event;
        $firstIndex[$id] = $index;
    }

    /** @var array<string, list<string>> $adjudicationsByFinding */
    $adjudicationsByFinding = [];

    foreach ($byId as $id => $event) {
        $causeId = $event['causation_event_id'] ?? null;
        if (is_string($causeId) && $causeId !== '') {
            if (!isset($byId[$causeId])) {
                $errors[] = sprintf('event %s references missing cause %s', $id, $causeId);
            }
        }

        if (($event['event_type'] ?? null) === 'verification_finding_adjudicated' && is_string($causeId) && $causeId !== '') {
            $adjudicationsByFinding[$causeId][] = $id;
        }
    }

    foreach ($adjudicationsByFinding as $findingId => $adjudicationIds) {
        if (count($adjudicationIds) > 1) {
            $errors[] = sprintf(
                'finding %s has conflicting adjudications: %s',
                $findingId,
                implode(', ', $adjudicationIds),
            );
        }
    }

    foreach (delivery_agent_event_set_cycle_errors($byId) as $cycleError) {
        $errors[] = $cycleError;
    }

    return $errors;
}

/**
 * @param array<string, array<string, mixed>> $byId
 * @return list<string>
 */
function delivery_agent_event_set_cycle_errors(array $byId): array
{
    $errors = [];
    /** @var array<string, int> $state 0=unseen 1=active 2=done */
    $state = [];
    /** @var list<string> $stack */
    $stack = [];

    $visit = static function (string $id) use (&$visit, &$errors, &$state, &$stack, $byId): void {
        $state[$id] = 1;
        $stack[] = $id;
        $causeId = $byId[$id]['causation_event_id'] ?? null;
        if (is_string($causeId) && $causeId !== '' && isset($byId[$causeId])) {
            $causeState = $state[$causeId] ?? 0;
            if ($causeState === 1) {
                $cycleStart = array_search($causeId, $stack, true);
                $cycle = $cycleStart === false ? [$causeId, $id] : array_slice($stack, $cycleStart);
                $cycle[] = $causeId;
                $errors[] = 'causal cycle: ' . implode(' -> ', $cycle);
            } elseif ($causeState === 0) {
                $visit($causeId);
            }
        }
        array_pop($stack);
        $state[$id] = 2;
    };

    foreach (array_keys($byId) as $id) {
        if (($state[$id] ?? 0) === 0) {
            $visit($id);
        }
    }

    return $errors;
}

/**
 * Refuse modification or deletion of already-accepted batch file contents.
 *
 * @param array<string, string> $acceptedBatches path => raw file bytes
 * @param array<string, string> $proposedBatches path => raw file bytes
 * @return list<string>
 */
function delivery_agent_batch_immutability_errors(array $acceptedBatches, array $proposedBatches): array
{
    $errors = [];
    foreach ($acceptedBatches as $path => $acceptedBytes) {
        if (!array_key_exists($path, $proposedBatches)) {
            $errors[] = sprintf('accepted batch deleted: %s', $path);
            continue;
        }
        if (!hash_equals($acceptedBytes, $proposedBatches[$path])) {
            $errors[] = sprintf('accepted batch modified: %s', $path);
        }
    }

    return $errors;
}
