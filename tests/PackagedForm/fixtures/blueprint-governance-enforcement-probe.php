<?php

declare(strict_types=1);

/**
 * Packaged runtime probe for #2848 (FW-GOVERNANCE-PACKAGED-ACCEPTANCE-01).
 *
 * Boots the real installed kernel from the packaged consumer and exercises
 * canonical runtime services (RoleRepository, PermissionHandlerInterface,
 * EntityAccessHandler, TransitionService) against generated
 * blueprint-governance registrations. Never constructs a generated provider
 * or policy by hand; every observation goes through the booted kernel's own
 * container resolution, so discovery authority (literal root composer.json)
 * is the thing under test, exactly like
 * tests/PackagedForm/fixtures/recipe-provider-activation-probe.php.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;

function marker(string $name, bool $value): void
{
    fwrite(STDOUT, 'governance-probe ' . $name . ': ' . ($value ? 'yes' : 'no') . "\n");
}

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    fwrite(STDOUT, "governance-probe kernel boot OK\n");

    $resolver = $kernel->getHttpServiceResolver();
    $entityTypeManager = $kernel->getEntityTypeManager();
    $accessHandler = $kernel->getAccessHandler();

    $roles = $kernel->roleRepository();
    $editorRole = $roles->get('editor');
    $viewerRole = $roles->get('viewer');
    marker('role-editor-registered', $editorRole !== null);
    marker('role-viewer-registered', $viewerRole !== null);

    $catalogue = $kernel->permissionCatalogue();
    marker('permission-catalogue-edit-article', $catalogue->hasPermission('edit article'));
    marker('permission-catalogue-publish', $catalogue->hasPermission('use editorial transition publish'));

    $articleType = $entityTypeManager->getDefinition('article');
    marker('article-revisionable', $articleType->isRevisionable());

    if ($editorRole === null || $viewerRole === null) {
        // Registration is already gone (rival run). Nothing further to
        // exercise: role/permission catalogue absence IS the observation.
        exit(0);
    }

    $mode = $argv[1] ?? 'positive';
    $editorUid = (int) ($argv[2] ?? 0);
    $viewerUid = (int) ($argv[3] ?? 0);
    if ($mode !== 'positive' || $editorUid === 0 || $viewerUid === 0) {
        fwrite(STDOUT, "governance-probe skipped enforcement checks (no accounts supplied)\n");
        exit(0);
    }

    $userRepository = $entityTypeManager->getRepository('user');
    $editorAccount = $userRepository->find($editorUid);
    $viewerAccount = $userRepository->find($viewerUid);
    if (!$editorAccount instanceof \Waaseyaa\Access\AccountInterface || !$viewerAccount instanceof \Waaseyaa\Access\AccountInterface) {
        fwrite(STDERR, "::error::governance-probe could not load the seeded accounts\n");
        exit(1);
    }

    $personRepository = $entityTypeManager->getRepository('person');
    $person = $personRepository->create(['name' => 'Jane']);
    $person->enforceIsNew();
    $personRepository->save($person, validate: false);

    $articleRepository = $entityTypeManager->getRepository('article');
    $article = $articleRepository->create([
        'title' => 'Welcome',
        'stage' => 'draft',
        'author' => $person->id(),
        'workflow_state' => 'draft',
    ]);
    $article->enforceIsNew();
    $articleRepository->save($article, validate: false);

    // Default deny: person has no declared policy at all.
    $personDenied = !$accessHandler->check($person, 'view', $viewerAccount)->isAllowed()
        && !$accessHandler->check($person, 'view', $editorAccount)->isAllowed();
    marker('default-deny-person', $personDenied);

    // One denied, one allowed principal on the same entity/operation.
    $viewerDeniedUpdate = !$accessHandler->check($article, 'update', $viewerAccount)->isAllowed();
    $editorAllowedUpdate = $accessHandler->check($article, 'update', $editorAccount)->isAllowed();
    marker('entity-access-viewer-denied', $viewerDeniedUpdate);
    marker('entity-access-editor-allowed', $editorAllowedUpdate);

    // One denied, one allowed workflow transition on the revisionable binding.
    $transitionService = $resolver->resolve(TransitionService::class);
    if (!$transitionService instanceof TransitionService) {
        fwrite(STDERR, "::error::governance-probe could not resolve TransitionService\n");
        exit(1);
    }

    $viewerTransitionDenied = false;
    try {
        $transitionService->transition($article, 'publish', $viewerAccount);
    } catch (TransitionDeniedException $exception) {
        $viewerTransitionDenied = $exception->reason === TransitionDeniedException::REASON_PERMISSION;
    }
    marker('workflow-transition-viewer-denied', $viewerTransitionDenied);

    $editorTransitionAllowed = false;
    try {
        $transitionService->transition($article, 'publish', $editorAccount);
        $reloaded = $articleRepository->find($article->id());
        $editorTransitionAllowed = $reloaded !== null;
    } catch (TransitionDeniedException) {
        $editorTransitionAllowed = false;
    }
    marker('workflow-transition-editor-allowed', $editorTransitionAllowed);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::governance-probe FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
