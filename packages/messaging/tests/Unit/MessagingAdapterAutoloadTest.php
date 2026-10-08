<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MessagingAdapterAutoloadTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function adapters_autoload_before_their_policy_and_factories_keep_the_boundary(): void
    {
        self::assertFalse(class_exists(\Waaseyaa\Messaging\MessagingAccessPolicy::class, false));
        self::assertTrue(class_exists(\Waaseyaa\Messaging\MessagingProtectedEntityReadPolicy::class));
        self::assertTrue(class_exists(\Waaseyaa\Messaging\MessagingProtectedFieldReadPolicy::class));
        $policy = new \Waaseyaa\Messaging\MessagingAccessPolicy();
        self::assertInstanceOf(\Waaseyaa\Access\ProtectedEntityReadPolicyInterface::class, $policy->protectedEntityReadPolicy());
        self::assertInstanceOf(\Waaseyaa\Access\ProtectedFieldReadPolicyInterface::class, $policy->protectedFieldReadPolicy());
    }
}
