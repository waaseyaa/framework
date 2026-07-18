<?php

declare(strict_types=1);

use Waaseyaa\Entity\EntityBase;

require dirname(__DIR__).'/vendor/autoload.php';

$iterations = max(1, (int) ($argv[1] ?? 1_000_000));
$entity = new class (['id' => 1, 'title' => 'Tansi']) extends EntityBase {
    public function __construct(array $values)
    {
        parent::__construct($values, 'benchmark', ['id' => 'id', 'label' => 'title']);
    }
};

$started = hrtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    $entity->get('title');
}
$elapsed = hrtime(true) - $started;

echo json_encode([
    'scenario' => 'unbooted_public_baseline',
    'iterations' => $iterations,
    'nanoseconds_per_read' => $elapsed / $iterations,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'planned_activation_scenarios' => [
        'booted_class_definition_public',
        'booted_bundle_definition_public',
        'translation_and_revision_public',
        'config_and_audit_read_model_public',
        'principal_creation',
        'protected_cold',
        'protected_warm',
        'strict_audited_read',
        'fifty_field_projection',
    ],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
