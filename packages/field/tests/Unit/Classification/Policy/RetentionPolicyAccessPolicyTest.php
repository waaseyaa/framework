<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Field\Classification\Policy\RetentionPolicyAccessPolicy;
use Waaseyaa\Field\Entity\RetentionPolicy;

#[CoversClass(RetentionPolicyAccessPolicy::class)]
final class RetentionPolicyAccessPolicyTest extends TestCase
{
    #[Test]
    public function protected_configuration_requires_an_exact_governance_principal_scope(): void
    {
        $scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new RetentionPolicyAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...)));
        $policy = new RetentionPolicy(['name' => 'Legal retention']);

        try {
            $viewer = new AuthorizationPrincipal(7, true, ['governance-viewer'], [], 'retention-policy-test');
            self::assertSame('Legal retention', $scope->run($viewer, fn(): mixed => $policy->get('name')));

            $this->expectException(FieldReadDenied::class);
            $outsider = new AuthorizationPrincipal(8, true, ['viewer'], [], 'retention-policy-test');
            $scope->run($outsider, fn(): mixed => $policy->get('name'));
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }
}
