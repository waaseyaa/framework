<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;

/** Exact test principal scope for engagement value-object assertions. */
trait EngagementFieldReadTestTrait
{
    private AccountFieldReadScope $fieldReadScope;

    protected function setUp(): void
    {
        $this->fieldReadScope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->fieldReadScope,
            static fn(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $field): AccessResult => AccessResult::allowed(),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    private function readEngagement(callable $read): mixed
    {
        return $this->fieldReadScope->run(
            new AuthorizationPrincipal(1, true, [], [], 'engagement-unit-test'),
            $read,
        );
    }
}
