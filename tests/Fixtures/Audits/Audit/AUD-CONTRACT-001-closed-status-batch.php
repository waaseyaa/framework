<?php

declare(strict_types=1);
/**
 * AUD-CONTRACT-001 / #3184: source API discriminator, not latency or installed qualification.
 * Run: php <this-file> <framework-root>
 * Exit 0 confirms the recorded missing closed batch seam at the audit base.
 * A future changed public interface deliberately stops for acceptance re-review.
 */
$repo = $argv[1] ?? dirname(__DIR__, 4);
require $repo . '/vendor/autoload.php';
$port = new ReflectionClass(Waaseyaa\Access\User\UserInternalFieldReaderInterface::class);
$expected = ['credentials', 'twoFactor', 'mailDelivery', 'verification', 'sessionIdentity', 'maintenanceAuthorization'];
$actual = array_map(fn(ReflectionMethod $m): string => $m->getName(), $port->getMethods());
sort($actual);
sort($expected);
if ($actual !== $expected) {
    throw new RuntimeException('The closed port changed; re-evaluate its batch/status semantics against #3184.');
}
foreach ($port->getMethods() as $method) {
    $parameters = $method->getParameters();
    if (count($parameters) !== 1 || (string) $parameters[0]->getType() !== Waaseyaa\Entity\EntityInterface::class) {
        throw new RuntimeException('Closed operation shape changed; re-evaluate acceptance.');
    }
}
$implementation = new ReflectionClass(Waaseyaa\Audit\Bootstrap\AuditedUserInternalFieldReader::class);
if (!$implementation->implementsInterface($port->getName())) {
    throw new RuntimeException('Production implementation no longer implements the inspected port.');
}
$batch = new ReflectionMethod(Waaseyaa\Audit\AuditedFieldRead::class, 'readEntityMany');
if (!$batch->isPublic() || (string) $batch->getParameters()[2]->getType() !== 'array') {
    throw new RuntimeException('Generic public batch capability control failed.');
}
echo json_encode([
    'base' => '3161864a5d1af57621e4819e9720f12e12ccdae7',
    'closed_operations' => $actual,
    'all_closed_operations_take_one_entity' => true,
    'generic_batch_api_present' => true,
    'result' => 'Closed status-only batch seam remains absent; generic batch control exists.',
    'limits' => 'API shape only. No performance, receipt durability, installed consumer or current FETDER state is inferred.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
