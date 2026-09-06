<?php

declare(strict_types=1);

/**
 * Complete-set controls, batch loading, freeze checks, and deterministic replay
 * for delivery-agent events (#2902 / FW-DELIVERY-EVENT-BATCHES-01).
 *
 * Replay: v1 events keep JSONL line order; batch events use deterministic
 * topological order. Among events whose causes are already emitted, choose by
 * (normalized recorded_at, event_id).
 */

const DELIVERY_AGENT_BATCH_DIR = 'ops/observability/delivery-agent-batches-v1';
const DELIVERY_AGENT_BATCH_SCHEMA = 'ops/observability/delivery-agent-batch-v1.schema.json';
const DELIVERY_AGENT_V1_FREEZE = 'ops/observability/delivery-agent-v1-freeze.json';

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

/**
 * Refuse modification of an already-accepted authority blob (freeze manifest,
 * batch schema, etc.).
 *
 * Candidate mode must pass the blob from the candidate commit. A missing
 * candidate blob is a deletion; working-tree bytes must not be substituted.
 *
 * @return list<string>
 */
function delivery_agent_authority_blob_immutability_errors(
    string $label,
    ?string $acceptedBytes,
    ?string $proposedBytes,
): array {
    if ($acceptedBytes === null || $acceptedBytes === '') {
        return [];
    }
    if ($proposedBytes === null || $proposedBytes === '') {
        return [sprintf('accepted %s deleted', $label)];
    }
    if (!hash_equals($acceptedBytes, $proposedBytes)) {
        return [sprintf('the published %s is immutable', $label)];
    }

    return [];
}

/**
 * @return list<string>
 */
function delivery_agent_v1_freeze_errors(string $ledgerBytes, ?string $freezeJson): array
{
    if ($freezeJson === null || $freezeJson === '') {
        return [];
    }
    try {
        $freeze = json_decode($freezeJson, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return ['v1 freeze manifest is not valid JSON: ' . $exception->getMessage()];
    }
    if (!is_array($freeze) || ($freeze['schema_version'] ?? null) !== 'delivery-agent-v1-freeze/v1') {
        return ['v1 freeze manifest must use schema_version delivery-agent-v1-freeze/v1'];
    }
    $expected = $freeze['ledger_sha256'] ?? null;
    if (!is_string($expected) || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1) {
        return ['v1 freeze manifest is missing a 64-character ledger_sha256'];
    }
    $actual = hash('sha256', $ledgerBytes);
    if (!hash_equals($expected, $actual)) {
        return [sprintf(
            'frozen v1 ledger bytes must remain identical to the cutover freeze (expected %s, got %s)',
            $expected,
            $actual,
        )];
    }

    return [];
}

function delivery_agent_normalize_timestamp(string $value): string
{
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
}

/**
 * Deterministic replay: v1 line order, then topo-sorted batch events.
 *
 * @param list<array<string, mixed>> $v1Events
 * @param list<array<string, mixed>> $batchEvents
 * @return array{events: list<array<string, mixed>>, errors: list<string>}
 */
function delivery_agent_replay_events(array $v1Events, array $batchEvents): array
{
    $errors = [];
    /** @var array<string, true> $emitted */
    $emitted = [];
    /** @var list<array<string, mixed>> $ordered */
    $ordered = [];

    foreach ($v1Events as $event) {
        $id = $event['event_id'];
        if (!is_string($id)) {
            $errors[] = 'v1 event is missing event_id';
            continue;
        }
        $ordered[] = $event;
        $emitted[$id] = true;
    }

    /** @var array<string, array<string, mixed>> $pending */
    $pending = [];
    foreach ($batchEvents as $event) {
        $id = $event['event_id'] ?? null;
        if (!is_string($id)) {
            $errors[] = 'batch event is missing event_id';
            continue;
        }
        if (isset($pending[$id]) || isset($emitted[$id])) {
            $errors[] = sprintf('replay saw duplicate event_id %s', $id);
            continue;
        }
        $pending[$id] = $event;
    }

    while ($pending !== []) {
        $ready = [];
        foreach ($pending as $id => $event) {
            $causeId = $event['causation_event_id'] ?? null;
            if (is_string($causeId) && $causeId !== '' && !isset($emitted[$causeId])) {
                continue;
            }
            $ready[$id] = $event;
        }

        if ($ready === []) {
            $errors[] = 'batch replay could not make progress (cycle or unresolved causes among batch events)';
            break;
        }

        uasort($ready, static function (array $left, array $right): int {
            $leftKey = delivery_agent_normalize_timestamp((string) $left['recorded_at']) . "\0" . $left['event_id'];
            $rightKey = delivery_agent_normalize_timestamp((string) $right['recorded_at']) . "\0" . $right['event_id'];

            return $leftKey <=> $rightKey;
        });

        $nextId = array_key_first($ready);
        $next = $ready[$nextId];
        $ordered[] = $next;
        $emitted[$nextId] = true;
        unset($pending[$nextId]);
    }

    return ['events' => $ordered, 'errors' => $errors];
}

/**
 * @return array{batches: array<string, string>, errors: list<string>}
 */
function delivery_agent_load_batch_files_from_directory(string $absoluteBatchDir): array
{
    $batches = [];
    $errors = [];
    if (!is_dir($absoluteBatchDir)) {
        return ['batches' => [], 'errors' => []];
    }
    $entries = scandir($absoluteBatchDir);
    if ($entries === false) {
        return ['batches' => [], 'errors' => ['could not read batch directory']];
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'README.md') {
            continue;
        }
        if (!str_ends_with($entry, '.json')) {
            $errors[] = sprintf('unexpected non-batch entry in batch directory: %s', $entry);
            continue;
        }
        $batchId = substr($entry, 0, -5);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $batchId) !== 1) {
            $errors[] = sprintf('batch filename must be a UUID v4: %s', $entry);
            continue;
        }
        $path = DELIVERY_AGENT_BATCH_DIR . '/' . $entry;
        $absolute = $absoluteBatchDir . '/' . $entry;
        $bytes = file_get_contents($absolute);
        if ($bytes === false) {
            $errors[] = sprintf('could not read batch file %s', $path);
            continue;
        }
        $batches[$path] = $bytes;
    }
    ksort($batches);

    return ['batches' => $batches, 'errors' => $errors];
}

/**
 * @param callable(string): array{exit: int, output: string} $gitShow
 * @param callable(string): array{exit: int, output: string} $gitLsTree
 * @return array{batches: array<string, string>, errors: list<string>}
 */
function delivery_agent_load_batch_files_from_commit(callable $gitShow, callable $gitLsTree): array
{
    $listed = $gitLsTree(DELIVERY_AGENT_BATCH_DIR);
    if ($listed['exit'] !== 0) {
        if (str_contains($listed['output'], 'exists on disk, but not in')
            || str_contains($listed['output'], 'does not exist in')
            || str_contains($listed['output'], 'Not a valid object')) {
            return ['batches' => [], 'errors' => []];
        }

        return ['batches' => [], 'errors' => ['could not list batch directory at commit']];
    }

    $batches = [];
    $errors = [];
    foreach (preg_split('/\R/', trim($listed['output'])) ?: [] as $path) {
        if ($path === '' || str_ends_with($path, '/README.md')) {
            continue;
        }
        if (!str_starts_with($path, DELIVERY_AGENT_BATCH_DIR . '/') || !str_ends_with($path, '.json')) {
            continue;
        }
        $entry = basename($path);
        $batchId = substr($entry, 0, -5);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $batchId) !== 1) {
            $errors[] = sprintf('batch filename must be a UUID v4: %s', $entry);
            continue;
        }
        $shown = $gitShow($path);
        if ($shown['exit'] !== 0) {
            $errors[] = sprintf('could not read batch file %s at commit', $path);
            continue;
        }
        $batches[$path] = $shown['output'];
    }
    ksort($batches);

    return ['batches' => $batches, 'errors' => $errors];
}

/**
 * @param array<string, string> $batchFiles
 * @return array{events: list<array<string, mixed>>, errors: list<string>}
 */
function delivery_agent_parse_batch_files(array $batchFiles, object $batchSchema, object $eventSchema): array
{
    $events = [];
    $errors = [];
    $validator = new Opis\JsonSchema\Validator();

    foreach ($batchFiles as $path => $bytes) {
        $entry = basename($path);
        $expectedId = substr($entry, 0, -5);
        try {
            $document = json_decode($bytes, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = sprintf('batch %s is not valid JSON: %s', $path, $exception->getMessage());
            continue;
        }
        if (!is_object($document) || !is_array($decoded) || array_is_list($decoded)) {
            $errors[] = sprintf('batch %s must be a JSON object', $path);
            continue;
        }
        if (!$validator->validate($document, $batchSchema)->isValid()) {
            $errors[] = sprintf('batch %s does not conform to %s', $path, DELIVERY_AGENT_BATCH_SCHEMA);
            continue;
        }
        if (($decoded['batch_id'] ?? null) !== $expectedId) {
            $errors[] = sprintf('batch %s batch_id must match filename', $path);
            continue;
        }
        foreach ($decoded['events'] as $index => $event) {
            if (!is_array($event) || array_is_list($event)) {
                $errors[] = sprintf('batch %s events[%d] must be an object', $path, $index);
                continue;
            }
            try {
                $eventDocument = json_decode(
                    json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                $errors[] = sprintf('batch %s events[%d] is not JSON-encodable: %s', $path, $index, $exception->getMessage());
                continue;
            }
            if (!$validator->validate($eventDocument, $eventSchema)->isValid()) {
                $errors[] = sprintf('batch %s events[%d] does not conform to the closed v1 event schema', $path, $index);
                continue;
            }
            $events[] = $event;
        }
    }

    return ['events' => $events, 'errors' => $errors];
}

/**
 * @param list<array<string, mixed>> $v1Events
 * @return list<array<string, mixed>>
 */
function delivery_agent_parse_v1_ledger_events(string $contents): array
{
    $events = [];
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($event)) {
            $events[] = $event;
        }
    }

    return $events;
}

/**
 * Trustworthy elapsed_ms for FUTURE substantive_review_issued and
 * repair_completed events (#2902 batch path only; the frozen v1 ledger is
 * immutable and never passed through this rule). The declared elapsed_ms
 * must be computed, not asserted: an explicit causation_event_id must name
 * the authoritative start event for that event type (review_started for
 * substantive_review_issued, repair_started for repair_completed), sharing
 * repository and pull_request; a review's start must additionally share
 * head_sha (repair may legitimately cross head SHAs). Both the start and end
 * events must carry an explicit occurred_at — recorded_at is custody time,
 * never a stand-in for occurrence. The computed duration must be
 * non-negative and match the declared value exactly.
 *
 * @param array<string, mixed> $event
 * @param array<string, array<string, mixed>> $eventsById
 * @return list<string>
 */
function delivery_agent_elapsed_ms_errors(array $event, array $eventsById): array
{
    $elapsedMs = $event['elapsed_ms'] ?? null;
    if ($elapsedMs === null) {
        return [];
    }
    $requiredStart = match ($event['event_type'] ?? null) {
        'substantive_review_issued' => 'review_started',
        'repair_completed' => 'repair_started',
        default => null,
    };
    if ($requiredStart === null) {
        return [];
    }
    $id = (string) ($event['event_id'] ?? '(unknown)');
    $causeId = $event['causation_event_id'] ?? null;
    if (!is_string($causeId) || $causeId === '') {
        return ["event {$id}: elapsed_ms requires a causation_event_id naming its {$requiredStart} start event"];
    }
    $cause = $eventsById[$causeId] ?? null;
    if (!is_array($cause)) {
        return ["event {$id}: elapsed_ms causation_event_id {$causeId} does not name a known event"];
    }

    $errors = [];
    if (($cause['event_type'] ?? null) !== $requiredStart) {
        $errors[] = sprintf(
            'event %s: elapsed_ms must be caused by a %s event, not %s',
            $id,
            $requiredStart,
            (string) ($cause['event_type'] ?? 'null'),
        );
    }
    if (($cause['repository'] ?? null) !== ($event['repository'] ?? null) || ($cause['pull_request'] ?? null) !== ($event['pull_request'] ?? null)) {
        $errors[] = "event {$id}: elapsed_ms start event repository or pull_request differs from the completion event";
    }
    if (($event['event_type'] ?? null) === 'substantive_review_issued' && ($cause['head_sha'] ?? null) !== ($event['head_sha'] ?? null)) {
        $errors[] = "event {$id}: elapsed_ms start event head_sha differs from the completion event";
    }

    $startOccurred = $cause['occurred_at'] ?? null;
    $endOccurred = $event['occurred_at'] ?? null;
    if (!is_string($startOccurred) || !is_string($endOccurred)) {
        $errors[] = "event {$id}: elapsed_ms requires explicit occurred_at on both itself and its {$requiredStart} start event";

        return $errors;
    }

    $start = new DateTimeImmutable($startOccurred);
    $end = new DateTimeImmutable($endOccurred);
    $deltaMs = (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000);
    if ($deltaMs < 0) {
        $errors[] = "event {$id}: elapsed_ms start event occurs after the completion event";

        return $errors;
    }
    if ($deltaMs !== $elapsedMs) {
        $errors[] = sprintf(
            'event %s: elapsed_ms %d does not match the computed duration %d between its start and end timestamps',
            $id,
            $elapsedMs,
            $deltaMs,
        );
    }

    return $errors;
}
