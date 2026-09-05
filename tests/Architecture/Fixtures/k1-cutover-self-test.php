<?php

declare(strict_types=1);

function k1CutoverRunSelfTest(): int
{
    $directory = sys_get_temp_dir() . '/waaseyaa-k1-cutover-self-test-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0o700)) {
        throw new RuntimeException('could not create disposable self-test directory');
    }
    try {
        $matching = k1CutoverSelfTestCase($directory . '/matching', populated: true, mismatch: false);
        $missing = k1CutoverSelfTestCase($directory . '/missing', populated: false, mismatch: false);
        $mismatched = k1CutoverSelfTestCase($directory . '/mismatched', populated: true, mismatch: true);
        if (!$matching['ok']) {
            throw new RuntimeException('self-test failed: matching fixture did not pass');
        }
        if ($missing['ok'] || !k1CutoverSelfTestHas($missing, 'missing')) {
            throw new RuntimeException('self-test failed: missing provenance was not refused');
        }
        if ($mismatched['ok'] || !k1CutoverSelfTestHas($mismatched, 'source') || !k1CutoverSelfTestHas($mismatched, 'projector') || !k1CutoverSelfTestHas($mismatched, 'generation') || !k1CutoverSelfTestHas($mismatched, 'event')) {
            throw new RuntimeException('self-test failed: mismatched identity fields were not reported');
        }
        fwrite(STDOUT, "matching fixture: PASS\nmissing provenance: FAIL (expected)\nmismatched hashes: FAIL (expected)\nk1 delivery cutover self-test: PASS\n");

        return 0;
    } finally {
        k1CutoverRemoveDirectory($directory);
    }
}

/** @param array{ok: bool, lines: list<string>} $report */
function k1CutoverSelfTestHas(array $report, string $needle): bool
{
    return str_contains(strtolower(implode("\n", $report['lines'])), $needle);
}

/** @return array{ok: bool, lines: list<string>} */
function k1CutoverSelfTestCase(string $directory, bool $populated, bool $mismatch): array
{
    if (!mkdir($directory, 0o700)) {
        throw new RuntimeException('could not create disposable fixture directory');
    }
    $database = $directory . '/projection.sqlite';
    $dashboard = $directory . '/dashboard.json';
    $pdo = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_state (projection_id TEXT PRIMARY KEY, contract_version TEXT NOT NULL, source_commit_sha TEXT NOT NULL, schema_sha256 TEXT NOT NULL, ledger_sha256 TEXT NOT NULL, ledger_bytes INTEGER NOT NULL, event_count INTEGER NOT NULL, first_event_id TEXT NULL, last_event_id TEXT NULL, generation INTEGER NOT NULL, projector_version INTEGER NOT NULL, projected_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE waaseyaa_delivery_projection_identity_v2 (projection_id TEXT PRIMARY KEY, batch_manifest TEXT NOT NULL, batch_manifest_sha256 TEXT NOT NULL, event_schema_sha256 TEXT NOT NULL, batch_schema_sha256 TEXT NULL, freeze_sha256 TEXT NULL, replay_sha256 TEXT NOT NULL)');
    if ($populated) {
        $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_state (projection_id, contract_version, source_commit_sha, schema_sha256, ledger_sha256, ledger_bytes, event_count, first_event_id, last_event_id, generation, projector_version, projected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            K1_CUTOVER_PROJECTION_ID,
            'delivery-agent-event/v1',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            str_repeat('a', 64),
            str_repeat('1', 64),
            128,
            15,
            null,
            null,
            4,
            2,
            '2026-09-05T14:00:00Z',
        ]);
        $pdo->prepare('INSERT INTO waaseyaa_delivery_projection_identity_v2 (projection_id, batch_manifest, batch_manifest_sha256, event_schema_sha256, batch_schema_sha256, freeze_sha256, replay_sha256) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            K1_CUTOVER_PROJECTION_ID,
            '[]',
            str_repeat('b', 64),
            str_repeat('a', 64),
            null,
            null,
            str_repeat('2', 64),
        ]);
    }
    $sql = 'SELECT COALESCE(s.source_commit_sha, \'unknown\') AS `Source commit`, COALESCE(s.projector_version, \'unknown\') AS `Projector version`, COALESCE(s.generation, \'unknown\') AS `Generation`, COALESCE(s.event_count, \'unknown\') AS `Events`, COALESCE(s.projected_at, \'unknown\') AS `Projected (UTC)`, COALESCE(s.ledger_sha256, \'unknown\') AS `Frozen v1 ledger SHA-256`, COALESCE(i.replay_sha256, \'unknown\') AS `Complete replay SHA-256`, COALESCE(i.batch_manifest_sha256, \'unknown\') AS `Batch manifest SHA-256` FROM (SELECT \'delivery-agent-events/v1\' AS projection_id) AS p LEFT JOIN waaseyaa_delivery_projection_state AS s ON s.projection_id = p.projection_id LEFT JOIN waaseyaa_delivery_projection_identity_v2 AS i ON i.projection_id = p.projection_id';
    if ($mismatch) {
        $sql = str_replace(
            [
                'COALESCE(s.source_commit_sha, \'unknown\')',
                'COALESCE(s.projector_version, \'unknown\')',
                'COALESCE(s.generation, \'unknown\')',
                'COALESCE(s.event_count, \'unknown\')',
                'COALESCE(s.ledger_sha256, \'unknown\')',
                'COALESCE(i.replay_sha256, \'unknown\')',
                'COALESCE(i.batch_manifest_sha256, \'unknown\')',
            ],
            [
                '\'ffffffffffffffffffffffffffffffffffffffff\'',
                '\'9\'',
                '\'99\'',
                '\'1\'',
                '\'' . str_repeat('c', 64) . '\'',
                '\'' . str_repeat('d', 64) . '\'',
                '\'' . str_repeat('e', 64) . '\'',
            ],
            $sql,
        );
    }
    file_put_contents($dashboard, json_encode([
        'dashboard' => [
            'panels' => [
                ['id' => 8, 'targets' => [['rawSql' => $sql]]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    return k1CutoverVerify($pdo, $dashboard);
}

function k1CutoverRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            k1CutoverRemoveDirectory($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($directory);
}
