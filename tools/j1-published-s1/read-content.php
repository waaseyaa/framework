<?php

declare(strict_types=1);

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
if ((int) $pragmas['foreign_keys'][0]['foreign_keys'] !== 1
    || $pragmas['journal_mode'][0]['journal_mode'] !== 'wal'
    || (int) $pragmas['busy_timeout'][0]['timeout'] !== 5000
    || count($pragmas['database_list']) !== 1) {
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
