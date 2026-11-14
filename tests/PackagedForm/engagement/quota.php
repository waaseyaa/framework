<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Foundation\Kernel\HttpKernel;

if (getenv('ENGAGEMENT_QUOTA_PROFILE') !== 'defaults') {
    throw new RuntimeException('Default quota proof requires its isolated installation identity.');
}
$kernel = new HttpKernel(__DIR__);
$kernel->bootForCli();
$service = $kernel->buildHandlerContainer()->get(EngagementService::class);
$state = json_decode(file_get_contents(__DIR__ . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
$actor = new AuthorizationPrincipal(1, true, [], [], 'quota-fixture');
$root = new RootReference('synthetic_post', $state['roots'][0]['id']);
for ($attempt = 0; $attempt < 60; ++$attempt) {
    $service->setLiked($actor, $root, true);
}
try {
    $service->setLiked($actor, $root, true);
    throw new RuntimeException('Default fixed-window budget failed to reject attempt 61.');
} catch (EngagementException $failure) {
    if ($failure->errorCode !== 'RATE_LIMITED' || $failure->httpStatus !== 429 || $failure->retryAfter < 1) {
        throw $failure;
    }
    echo json_encode(['result' => 'pass', 'default_like_attempts_admitted' => 60, 'next_status' => 429, 'retry_after' => $failure->retryAfter], JSON_THROW_ON_ERROR), "\n";
}
