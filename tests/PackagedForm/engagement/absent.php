<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$kernel = new Waaseyaa\Foundation\Kernel\HttpKernel(__DIR__);
$kernel->bootForCli();
if (class_exists('Waaseyaa\\Engagement\\EngagementServiceProvider') || class_exists('PHPUnit\\Framework\\TestCase')) {
    throw new RuntimeException('Optional engagement or development dependencies leaked into minimal no-dev installation.');
}
$names = array_column($kernel->getRouteSnapshot()->routes, 'name');
foreach ($names as $name) {
    if (str_starts_with($name, 'api.engagement.')) {
        throw new RuntimeException('Absent engagement registered a route.');
    }
}
echo json_encode(['profile' => 'api-without-engagement', 'kernel_boot' => true, 'engagement_absent' => true, 'route_count' => count($names)], JSON_THROW_ON_ERROR), "\n";
