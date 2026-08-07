<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Field\Classification\ClassificationClearanceCheckerInterface;
use Waaseyaa\Field\FieldServiceProvider;

#[CoversClass(FieldServiceProvider::class)]
final class FieldServiceProviderClearanceConfigTest extends TestCase
{
    #[Test]
    public function honours_the_classification_role_clearance_override(): void
    {
        // `special-role` is not in DEFAULT_ROLE_CLEARANCE; only the config
        // override grants it a clearance level. Pre-fix the provider built the
        // checker with no argument, so the override was ignored and the role
        // resolved to 0.
        $provider = new FieldServiceProvider();
        $provider->setKernelContext('', [
            'classification' => ['role_clearance' => ['special-role' => 7]],
        ], []);
        $provider->register();

        $checker = $provider->resolve(ClassificationClearanceCheckerInterface::class);
        self::assertInstanceOf(ClassificationClearanceCheckerInterface::class, $checker);

        $account = $this->createStub(AccountInterface::class);
        $account->method('getRoles')->willReturn(['special-role']);

        self::assertSame(7, $checker->clearanceLevelFor($account));
    }
}
