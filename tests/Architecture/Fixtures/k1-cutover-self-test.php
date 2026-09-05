<?php

declare(strict_types=1);

function k1CutoverRunSelfTest(): int
{
    $receipt = [
        'operation' => 'verify',
        'outcome' => 'no_op',
        'source_commit_sha' => str_repeat('a', 40),
        'schema_sha256' => str_repeat('3', 64),
        'ledger_sha256' => str_repeat('1', 64),
        'batch_manifest_sha256' => str_repeat('b', 64),
        'batch_schema_sha256' => str_repeat('4', 64),
        'freeze_sha256' => str_repeat('5', 64),
        'replay_sha256' => str_repeat('2', 64),
        'event_count' => 15,
        'generation' => 4,
        'projector_version' => 2,
    ];
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_state (
        projection_id TEXT PRIMARY KEY, contract_version TEXT NOT NULL,
        source_commit_sha TEXT NOT NULL, schema_sha256 TEXT NOT NULL,
        ledger_sha256 TEXT NOT NULL, ledger_bytes INTEGER NOT NULL,
        event_count INTEGER NOT NULL, first_event_id TEXT NULL,
        last_event_id TEXT NULL, generation INTEGER NOT NULL,
        projector_version INTEGER NOT NULL, projected_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_identity_v2 (
        projection_id TEXT PRIMARY KEY, batch_manifest TEXT NOT NULL,
        batch_manifest_sha256 TEXT NOT NULL, event_schema_sha256 TEXT NOT NULL,
        batch_schema_sha256 TEXT NULL, freeze_sha256 TEXT NULL,
        replay_sha256 TEXT NOT NULL
    )');
    $state = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_state VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if ($state === false) {
        throw new RuntimeException('could not prepare self-test projection state');
    }
    $state->execute([
        K1_CUTOVER_PROJECTION_ID,
        'delivery-agent-event/v1',
        $receipt['source_commit_sha'],
        $receipt['schema_sha256'],
        $receipt['ledger_sha256'],
        128,
        $receipt['event_count'],
        null,
        null,
        $receipt['generation'],
        $receipt['projector_version'],
        '2026-09-05T14:00:00Z',
    ]);
    $identity = $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_identity_v2 VALUES (?, ?, ?, ?, ?, ?, ?)');
    if ($identity === false) {
        throw new RuntimeException('could not prepare self-test projection identity');
    }
    $identity->execute([
        K1_CUTOVER_PROJECTION_ID,
        '[]',
        $receipt['batch_manifest_sha256'],
        $receipt['schema_sha256'],
        $receipt['batch_schema_sha256'],
        $receipt['freeze_sha256'],
        $receipt['replay_sha256'],
    ]);
    $projection = k1CutoverIdentity($pdo);
    $grafana = [
        'Source commit' => $receipt['source_commit_sha'],
        'Projector version' => '2',
        'Generation' => '4',
        'Events' => '15',
        'Frozen v1 ledger SHA-256' => $receipt['ledger_sha256'],
        'Complete replay SHA-256' => $receipt['replay_sha256'],
        'Batch manifest SHA-256' => $receipt['batch_manifest_sha256'],
    ];

    $matching = k1CutoverCompare($grafana, $projection, $receipt);
    if (!$matching['ok']) {
        throw new RuntimeException('self-test failed: matching fixture did not pass');
    }

    $missingProjection = $projection;
    $missingProjection['freeze_sha256'] = null;
    $missing = k1CutoverCompare($grafana, $missingProjection, $receipt);
    if ($missing['ok'] || !k1CutoverSelfTestHas($missing, 'missing provenance: projection Freeze SHA-256')) {
        throw new RuntimeException('self-test failed: missing projection provenance was not refused');
    }

    $mismatchedGrafana = $grafana;
    $mismatchedGrafana['Generation'] = '99';
    $mismatched = k1CutoverCompare($mismatchedGrafana, $projection, $receipt);
    if ($mismatched['ok'] || !k1CutoverSelfTestHas($mismatched, 'mismatch: Grafana Generation')) {
        throw new RuntimeException('self-test failed: mismatched Grafana result was not refused');
    }

    fwrite(STDOUT, "matching fixture: PASS\nmissing provenance: FAIL (expected)\nmismatched Grafana result: FAIL (expected)\nk1 delivery cutover self-test: PASS\n");

    return 0;
}

/** @param array{ok: bool, lines: list<string>} $report */
function k1CutoverSelfTestHas(array $report, string $needle): bool
{
    return in_array($needle, $report['lines'], true);
}
