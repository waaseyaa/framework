<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Support;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Entity\EntityReadRuntime;

/** HTTP-test adapter that establishes the same account scope as request middleware. */
final class AccountScopedJsonApiController
{
    private readonly AccountFieldReadScope $scope;
    private readonly AuthorizationPrincipalInterface $principal;
    private readonly FieldReadGuard $guard;

    public function __construct(
        private readonly JsonApiController $controller,
        EntityAccessHandler $accessHandler,
        AccountInterface $account,
    ) {
        $this->scope = new AccountFieldReadScope();
        $this->principal = $account instanceof AuthorizationPrincipalInterface
            ? $account
            : new AccountPrincipalFactory()->fromAccount($account);
        $this->guard = new FieldReadGuard($this->scope, $accessHandler->checkProtectedFieldRead(...));
    }

    /** @param array<string, mixed> $query */
    public function show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument
    {
        return $this->run(fn(): JsonApiDocument => $this->controller->show($entityTypeId, $id, $query));
    }

    /** @param array<string, mixed> $document */
    public function store(string $entityTypeId, array $document): JsonApiDocument
    {
        return $this->run(fn(): JsonApiDocument => $this->controller->store($entityTypeId, $document));
    }

    /** @param array<string, mixed> $document */
    public function update(string $entityTypeId, int|string $id, array $document): JsonApiDocument
    {
        return $this->run(fn(): JsonApiDocument => $this->controller->update($entityTypeId, $id, $document));
    }

    private function run(callable $callback): JsonApiDocument
    {
        $prior = EntityReadRuntime::guard();
        EntityReadRuntime::installGuard($this->guard);
        try {
            return $this->scope->run($this->principal, $callback);
        } finally {
            EntityReadRuntime::installGuard($prior);
        }
    }
}
