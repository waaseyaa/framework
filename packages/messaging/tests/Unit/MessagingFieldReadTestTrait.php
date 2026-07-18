<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;

/** Exact test-only principal scope for messaging value-object assertions. */
trait MessagingFieldReadTestTrait
{
    private AccountFieldReadScope $fieldReadScope;

    protected function setUp(): void
    {
        $this->fieldReadScope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->fieldReadScope,
            static fn(
                AuthorizationPrincipalInterface $principal,
                EntityStructure $structure,
                PolicySubjectViewInterface $subject,
                string $field,
            ): AccessResult => AccessResult::allowed(),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    private function readMessaging(callable $read): mixed
    {
        return $this->fieldReadScope->run(
            new AuthorizationPrincipal(7, true, [], [], 'messaging-unit-test'),
            $read,
        );
    }
}
