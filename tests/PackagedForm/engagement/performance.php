<?php

declare(strict_types=1);

/**
 * Bounded installed-consumer performance characterization. The disposable
 * consumer fixture supplies the explicit high-quota profile for this workload.
 */
require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;

function performanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function performancePercentile(array $samples, float $percentile): float
{
    sort($samples, SORT_NUMERIC);
    $index = max(0, (int) ceil($percentile * count($samples)) - 1);
    return (float) $samples[$index];
}

try {
    if (!in_array(getenv('ENGAGEMENT_LAYOUT'), ['sql-blob', 'sql-column'], true)) {
        throw new RuntimeException('Runner must select an explicit engagement storage layout.');
    }
    $state = json_decode((string) file_get_contents(__DIR__ . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
    performanceAssert(is_array($state) && count($state['roots'] ?? []) >= 2, 'Fixture state is missing its seeded roots.');

    $kernel = new HttpKernel(__DIR__);
    $kernel->bootForCli();
    $resolver = $kernel->getHttpServiceResolver();
    $service = $resolver->resolve(EngagementService::class);
    $entities = $kernel->getEntityTypeManager();
    $database = $resolver->resolve(DatabaseInterface::class);
    performanceAssert($service instanceof EngagementService, 'Installed engagement service did not resolve.');
    performanceAssert($entities instanceof EntityTypeManagerInterface, 'Installed entity manager did not resolve.');
    performanceAssert($database instanceof DatabaseInterface, 'Installed database did not resolve.');

    $actor = new AuthorizationPrincipal((int) $state['users']['alice'], true, [], [], 'engagement-performance');
    // Keep the measured set independent of journey/upgrade data. Every root
    // below is created in this probe and receives exactly 20 new comments.
    $roots = [];
    $repository = $entities->getRepository((string) $state['roots'][0]['type']);
    while (count($roots) < 50) {
        $number = count($roots) + 1;
        $entity = $repository->create([
            'title' => 'Engagement perf root ' . $number,
            'status' => true,
            'publisher_id' => (int) $state['users']['alice'],
            'featured_subject_id' => (int) $state['users']['featured'],
        ]);
        $repository->save($entity);
        performanceAssert($entity->id() !== null && $entity->uuid() !== '', 'Canonical repository did not persist a performance root.');
        $roots[] = new RootReference((string) $state['roots'][0]['type'], (int) $entity->id());
    }

    $commentsCreated = 0;
    foreach ($roots as $rootIndex => $root) {
        for ($item = 0; $item < 20; $item++) {
            $submissionKey = $service->issueCommentKey($actor, $root);
            $created = $service->createComment($actor, $root, sprintf('perf-%02d-%02d', $rootIndex, $item), $submissionKey);
            performanceAssert(!$created->replayed && $created->uuid !== '', 'Canonical service did not create a performance comment.');
            $commentsCreated++;
        }
    }
    performanceAssert(count($roots) === 50 && $commentsCreated === 1000, 'Performance fixture cardinality differs from the deterministic 50-root/1000-comment plan.');

    $expectedByRoot = [];
    foreach ($roots as $root) {
        $record = $entities->getRepository($root->type)->find($root->id);
        performanceAssert($record !== null && $record->uuid() !== '', 'A performance root could not be reloaded.');
        $count = (int) $database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_comment_index WHERE root_type = ? AND root_uuid = ?',
            [$root->type, $record->uuid()],
        );
        performanceAssert($count === 20, 'Canonical comment index must contain exactly 20 comments for each generated root.');
        $expectedByRoot[$root->type . ':' . $root->id] = $count;
    }

    // One unreported warm-up, followed by ten reported bounded samples.
    $warmup = $service->summaries($actor, $roots);
    performanceAssert(count($warmup) === 50, 'Warm-up summary must return all 50 roots.');
    $iterations = 10;
    $samplesMs = [];
    $expectedComments = 1000;
    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $started = hrtime(true);
        $summaries = $service->summaries($actor, $roots);
        $samplesMs[] = (hrtime(true) - $started) / 1_000_000;
        performanceAssert(count($summaries) === 50, 'Summary result count must exactly match the 50 requested roots.');
        $actual = 0;
        foreach ($summaries as $dto) {
            $rootKey = $dto['root']->type . ':' . $dto['root']->id;
            performanceAssert(isset($expectedByRoot[$rootKey]), 'Summary returned a root outside the requested 50-root set.');
            performanceAssert($dto['commentCount'] === $expectedByRoot[$rootKey], 'Summary DTO count differs from its canonical indexed comment count.');
            performanceAssert($dto['topLevelCount'] === $expectedByRoot[$rootKey], 'Top-level DTO count differs from canonical root comment count.');
            $actual += $dto['commentCount'];
        }
        performanceAssert($actual === $expectedComments, 'Grouped summary count must exactly match the 1000 canonical comments.');
    }

    $targets = [];
    foreach ($roots as $root) {
        $record = $entities->getRepository($root->type)->find($root->id);
        performanceAssert($record !== null && $record->uuid() !== '', 'A performance root could not be reloaded.');
        $targets[] = [$root->type, $record->uuid()];
    }
    $where = implode(' OR ', array_fill(0, count($targets), '(root_type = ? AND root_uuid = ?)'));
    $bindings = [];
    foreach ($targets as [$type, $uuid]) {
        $bindings[] = $type;
        $bindings[] = $uuid;
    }
    $connection = $database->getConnection();
    $queryPlans = [
        'like_identity_grouping' => $connection->fetchAllAssociative(
            "EXPLAIN QUERY PLAN SELECT root_type, root_uuid, actor_id FROM engagement_like_identity WHERE reaction_type = 'like' AND (" . $where . ')',
            $bindings,
        ),
        'comment_summary_grouping' => $connection->fetchAllAssociative(
            'EXPLAIN QUERY PLAN SELECT root_type, root_uuid, author_id, parent_uuid, lifecycle FROM engagement_comment_index WHERE ' . $where,
            $bindings,
        ),
    ];
    $firstRoot = $targets[0];
    $commentPageSql = 'SELECT * FROM engagement_comment_index WHERE root_type = ? AND root_uuid = ? AND parent_uuid IS NULL AND comment_id <= (SELECT COALESCE(MAX(comment_id), 0) FROM engagement_comment_index) AND (created_at > ? OR (created_at = ? AND comment_id > ?)) ORDER BY created_at, comment_id LIMIT 200';
    $queryPlans['comment_page'] = $connection->fetchAllAssociative(
        'EXPLAIN QUERY PLAN ' . $commentPageSql,
        [$firstRoot[0], $firstRoot[1], -1, -1, 0],
    );
    $groupingSql = [
        'like_identity_grouping' => "SELECT root_type, root_uuid, actor_id FROM engagement_like_identity WHERE reaction_type = 'like' AND (" . $where . ')',
        'comment_summary_grouping' => 'SELECT root_type, root_uuid, author_id, parent_uuid, lifecycle FROM engagement_comment_index WHERE ' . $where,
    ];

    echo json_encode([
        'result' => 'pass',
        'fixture' => ['root_count' => 50, 'comment_count' => $commentsCreated, 'roots' => 'canonical repository', 'comments' => 'EngagementService'],
        'summary' => ['iterations' => $iterations, 'p50_ms' => performancePercentile($samplesMs, 0.50), 'p95_ms' => performancePercentile($samplesMs, 0.95), 'max_ms' => max($samplesMs), 'exact_comment_count_per_iteration' => $expectedComments],
        'sql' => [...$groupingSql, 'comment_page' => $commentPageSql],
        'query_plans' => $queryPlans,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, 'engagement performance probe failed: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
