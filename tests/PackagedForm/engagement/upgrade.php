<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Engagement\EngagementMutationScope;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$state = json_decode(file_get_contents(__DIR__ . '/legacy-state.json'), true, flags: JSON_THROW_ON_ERROR);
$kernel = new HttpKernel(__DIR__);
$kernel->bootForCli();
$container = $kernel->buildHandlerContainer();
$entities = $kernel->getEntityTypeManager();
$scope = $container->get(EngagementMutationScope::class);
$db = $kernel->getDatabase();
$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$db->transactional(function () use ($state, $entities, $scope, $expect): void {
    $scope->runMigration(function () use ($state, $entities, $expect): void {
        $comments = $entities->getRepository('comment');
        $visible = $comments->find($state['comments']['valid_published_id']);
        $expect($visible->get('body') === 'legacy top' && $visible->get('lifecycle') === 'active', 'Valid legacy comment was not migrated intact.');
        $expect($visible->get('parent_comment_uuid') === null, 'Legacy comment acquired a reply parent.');
        $expect($visible->mutationToken()?->aggregateVersion >= 1, 'Legacy aggregate version was not initialized.');
        $hidden = $comments->find($state['comments']['unpublished_id']);
        $expect($hidden->get('body') === 'legacy hidden' && $hidden->get('lifecycle') === 'unpublished', 'Upgrade erased or published restricted text.');
        $invalid = $comments->find($state['comments']['invalid_target_id']);
        $expect($invalid->get('lifecycle') === 'quarantined', 'Invalid legacy target was not quarantined.');
        $reactions = $entities->getRepository('reaction');
        $first = $reactions->find($state['reactions']['duplicate_like_ids'][0]);
        $second = $reactions->find($state['reactions']['duplicate_like_ids'][1]);
        $expect($first !== null && $second === null, 'Upgrade did not retain the earliest duplicate like.');
        $other = $reactions->find($state['reactions']['non_like_id']);
        $expect($other !== null && $other->get('reaction_type') === 'heart', 'Upgrade altered a non-like reaction.');
    });
});
$service = $container->get(EngagementService::class);
$principal = new AuthorizationPrincipal(2, true, [], [], 'legacy-actor');
$root = new RootReference('synthetic_post', $state['roots'][0]['id']);
$summary = $service->summaries($principal, [$root])[0];
$expect($summary['likeCount'] === 1 && $summary['commentCount'] === 1, 'Upgraded visibility-aware counts are wrong.');
$key = $service->issueCommentKey($principal, $root);
$comment = $service->createComment($principal, $root, 'written after upgrade', $key);
$expect($comment->revision === 1, 'Day-one upgraded comment write failed.');
$expect($service->createComment($principal, $root, 'written after upgrade', $key)->uuid === $comment->uuid, 'Upgraded retry created a duplicate.');
echo json_encode(['result' => 'pass', 'legacy_upgrade_assertions' => $assertions], JSON_THROW_ON_ERROR), "\n";
