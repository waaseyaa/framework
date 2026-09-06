<?php

declare(strict_types=1);

$snapshotPath = getenv('BOARD_SYNC_STUB_SNAPSHOT');
if (!is_string($snapshotPath) || !is_file($snapshotPath)) {
    fwrite(STDERR, "BOARD_SYNC_STUB_SNAPSHOT is required\n");
    exit(2);
}
$snapshot = json_decode((string) file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR);
$arguments = array_slice($argv, 1);
$command = implode(' ', array_slice($arguments, 0, 2));

if ($command === 'project view') {
    echo json_encode($snapshot['project'] + [
        'fields' => ['totalCount' => count($snapshot['fields'])],
        'items' => ['totalCount' => count($snapshot['items'])],
    ], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($command === 'project field-list') {
    $fields = $snapshot['fields'];
    if (getenv('BOARD_SYNC_STUB_TRUNCATE_FIELDS') === '1') {
        array_pop($fields);
    }
    echo json_encode(['fields' => $fields, 'totalCount' => count($snapshot['fields'])], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($command === 'project item-list') {
    $items = array_map(static function (array $item): array {
        return [
            'id' => $item['id'],
            'content' => [
                'type' => 'Issue',
                'number' => $item['issue_number'],
                'repository' => $item['repository'],
            ],
            'status' => $item['status'],
            'priority' => $item['priority'],
            'readiness' => $item['readiness'],
            'roadmap Stage' => $item['roadmap_stage'],
            'milestone' => $item['milestone'] === null ? null : ['title' => $item['milestone']],
        ];
    }, $snapshot['items']);
    if (getenv('BOARD_SYNC_STUB_TRUNCATE_ITEMS') === '1') {
        array_pop($items);
    }
    echo json_encode(['items' => $items, 'totalCount' => count($snapshot['items'])], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($command === 'issue list') {
    if (getenv('BOARD_SYNC_STUB_ISSUE_BOUND') === '1') {
        $issues = [];
        for ($number = 1; $number <= 1000; ++$number) {
            $issues[] = ['id' => "ISSUE_{$number}", 'number' => $number, 'state' => 'OPEN', 'title' => "Issue {$number}", 'url' => "https://example.test/issues/{$number}", 'labels' => [], 'milestone' => null];
        }
        echo json_encode($issues, JSON_THROW_ON_ERROR);
        exit(0);
    }
    $issues = array_map(static fn(array $issue): array => [
        'id' => $issue['id'],
        'number' => $issue['number'],
        'state' => $issue['state'],
        'title' => $issue['title'],
        'url' => $issue['url'],
        'labels' => array_map(static fn(string $name): array => ['name' => $name], $issue['labels']),
        'milestone' => $issue['milestone'] === null ? null : ['title' => $issue['milestone']],
    ], array_values(array_filter($snapshot['issues'], static fn(array $issue): bool => $issue['state'] === 'OPEN')));
    echo json_encode($issues, JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'project item-edit' || $command === 'project item-add') {
    $log = getenv('BOARD_SYNC_STUB_LOG');
    if (!is_string($log) || $log === '') {
        fwrite(STDERR, "BOARD_SYNC_STUB_LOG is required for mutations\n");
        exit(2);
    }
    $existing = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    $ordinal = count($existing) + 1;
    file_put_contents($log, json_encode(['ordinal' => $ordinal, 'arguments' => $arguments], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    if ((int) (getenv('BOARD_SYNC_STUB_FAIL_MUTATION_AT') ?: 0) === $ordinal) {
        fwrite(STDERR, "stub mutation failure at {$ordinal}\n");
        exit(17);
    }
    echo $command === 'project item-add' ? '{"id":"ITEM_added"}' : '{}';
    exit(0);
}

fwrite(STDERR, 'unexpected gh command: ' . implode(' ', $arguments) . "\n");
exit(2);
