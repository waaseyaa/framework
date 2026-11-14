<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$kernel = new HttpKernel(__DIR__);
$kernel->bootForCli();
$service = $kernel->buildHandlerContainer()->get(EngagementService::class);
$actor = new AuthorizationPrincipal(1, true, [], [], 'concurrency-fixture');
$directory = __DIR__ . '/storage/engagement-concurrency';
if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
    throw new RuntimeException('Cannot create isolated barrier directory.');
}
if (($argv[1] ?? '') === 'worker') {
    [$phase, $worker] = [$argv[2], $argv[3]];
    $data = json_decode(file_get_contents($directory . '/phase.json'), true, flags: JSON_THROW_ON_ERROR);
    $root = new RootReference('synthetic_post', $data['root']);
    file_put_contents($directory . '/ready-' . $worker, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($directory . '/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier deadline exceeded.');
        }
        usleep(10000);
    }
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        try {
            $value = match ($phase) {
                'like' => $service->setLiked($actor, $root, true),
                'comment' => $service->createComment($actor, $root, 'one logical concurrent comment', $data['key']),
                'edit' => $service->editComment($actor, $data['uuid'], 'concurrent edit ' . $worker, 1),
                'edit-remove' => $worker === '1'
                    ? $service->editComment($actor, $data['uuid'], 'edited before removal', 1)
                    : $service->removeComment($actor, $data['uuid']),
                default => throw new RuntimeException('Unknown concurrency phase.'),
            };
            echo json_encode(['result' => 'success', 'uuid' => is_object($value) && property_exists($value, 'uuid') ? $value->uuid : null], JSON_THROW_ON_ERROR), "\n";
            exit(0);
        } catch (EngagementException $failure) {
            if ($failure->httpStatus === 503 && $attempt < 19) {
                usleep(20000);
                continue;
            }
            echo json_encode(['result' => 'refused', 'code' => $failure->errorCode], JSON_THROW_ON_ERROR), "\n";
            exit(0);
        }
    }
    throw new RuntimeException('Contention retry bound exhausted.');
}

$entities = $kernel->getEntityTypeManager();
$rootEntity = $entities->getRepository('synthetic_post')->create([
    'title' => 'Isolated concurrency root', 'publisher_id' => 1, 'featured_subject_id' => 3, 'status' => true,
]);
$entities->getRepository('synthetic_post')->save($rootEntity);
$root = new RootReference('synthetic_post', (int) $rootEntity->id());
$results = [];
foreach (['like', 'comment', 'edit', 'edit-remove'] as $phase) {
    foreach (['ready-1', 'ready-2', 'go'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    $data = ['root' => $root->id];
    if ($phase === 'comment') {
        $data['key'] = $service->issueCommentKey($actor, $root);
    } elseif (in_array($phase, ['edit', 'edit-remove'], true)) {
        $comment = $service->createComment($actor, $root, 'initial race text', $service->issueCommentKey($actor, $root));
        $data['uuid'] = $comment->uuid;
    }
    file_put_contents($directory . '/phase.json', json_encode($data, JSON_THROW_ON_ERROR));
    chmod($directory . '/phase.json', 0o600);
    $children = [];
    try {
        foreach (['1', '2'] as $worker) {
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $phase, $worker], __DIR__);
            $process->setTimeout(45);
            $process->start();
            $children[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (!is_file($directory . '/ready-1') || !is_file($directory . '/ready-2')) {
            foreach ($children as $process) {
                $process->checkTimeout();
                if (!$process->isRunning()) {
                    throw new RuntimeException('Child failed before barrier: ' . $process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Children did not reach barrier.');
            }
            usleep(10000);
        }
        file_put_contents($directory . '/go', 'go');
        $outcomes = [];
        foreach ($children as $process) {
            if ($process->wait() !== 0) {
                throw new RuntimeException('Child failed: ' . $process->getErrorOutput());
            }
            $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $results[$phase] = $outcomes;
    } finally {
        foreach ($children as $process) {
            if ($process->isRunning()) {
                $process->stop(2);
            }
        }
    }
    if ($phase === 'like') {
        if (array_column($outcomes, 'result') !== ['success', 'success'] || $service->summaries($actor, [$root])[0]['likeCount'] !== 1) {
            throw new RuntimeException('Concurrent likes did not converge to one logical like.');
        }
    } elseif ($phase === 'comment') {
        if (array_column($outcomes, 'result') !== ['success', 'success'] || count(array_unique(array_column($outcomes, 'uuid'))) !== 1) {
            throw new RuntimeException('Concurrent retries did not converge to one comment.');
        }
    } elseif ($phase === 'edit') {
        $kinds = array_column($outcomes, 'result');
        sort($kinds);
        if ($kinds !== ['refused', 'success'] || !in_array('REVISION_CONFLICT', array_column($outcomes, 'code'), true)) {
            throw new RuntimeException('Concurrent edit conflict was lost.');
        }
    } else {
        if ($outcomes[1]['result'] !== 'success') {
            throw new RuntimeException('Removal failed during edit/remove race.');
        }
        try {
            $service->getComment($actor, $data['uuid']);
            throw new RuntimeException('Removal was resurrected.');
        } catch (EngagementException $failure) {
            if ($failure->errorCode !== 'TARGET_UNAVAILABLE') {
                throw $failure;
            }
        }
    }
}
unlink($directory . '/phase.json');
echo json_encode(['result' => 'pass', 'parallel_children_per_phase' => 2, 'phases' => $results], JSON_THROW_ON_ERROR), "\n";
