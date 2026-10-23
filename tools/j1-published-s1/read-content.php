<?php

declare(strict_types=1);

function j1DatabaseTopologyMatches(array $pragmas, string $root): bool
{
    $rows = $pragmas['database_list'] ?? null;
    if (!is_array($rows)) {
        return false;
    }
    $path = null;
    $temp = false;
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['name'], $row['file']) || !is_string($row['file'])) {
            return false;
        }
        if ($row['name'] === 'main' && $path === null) {
            $path = $row['file'];
        } elseif ($row['name'] === 'temp' && $row['file'] === '' && !$temp) {
            $temp = true;
        } else {
            return false;
        }
    }

    return $path !== null
        && (int) ($pragmas['foreign_keys'][0]['foreign_keys'] ?? 0) === 1
        && ($pragmas['journal_mode'][0]['journal_mode'] ?? '') === 'wal'
        && (int) ($pragmas['busy_timeout'][0]['timeout'] ?? 0) === 5000
        && is_file($root . '/storage/waaseyaa.sqlite')
        && realpath($path) === realpath($root . '/storage/waaseyaa.sqlite')
        && !is_link($root . '/storage') && !is_link($path);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

require getcwd() . '/vendor/autoload.php';
$kernel = new Waaseyaa\Foundation\Kernel\ConsoleKernel(getcwd());
$kernel->bootForCli();
$repository = $kernel->getEntityTypeManager()->getRepository('node');
$entity = $repository->find($argv[1] ?? '');
if ($entity === null || $entity->get('title') !== 'J1-canonical-preservation-witness') {
    throw new RuntimeException('Canonical preservation witness not found.');
}
$database = $kernel->getDatabase();
$pragmas = [];
foreach (['foreign_keys', 'journal_mode', 'busy_timeout', 'database_list'] as $pragma) {
    $pragmas[$pragma] = iterator_to_array($database->query('PRAGMA ' . $pragma));
}
if (!j1DatabaseTopologyMatches($pragmas, getcwd())) {
    throw new RuntimeException('Effective database topology differs from S1.');
}
echo json_encode([
    'id' => $entity->id(),
    'title' => $entity->get('title'),
    'body' => $entity->get('body'),
    'summary' => $entity->get('summary'),
    'activation' => iterator_to_array($database->query('SELECT * FROM waaseyaa_config_activation_v2 ORDER BY authority_id')),
    'pragmas' => $pragmas,
], JSON_THROW_ON_ERROR), PHP_EOL;
