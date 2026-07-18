<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Field\FieldReadMetadataResolver;
use Waaseyaa\User\User;

final class ConsumerFieldReadActivationFixtureTest extends TestCase
{
    public function test_nameless_user_without_context_does_not_yield_mail_when_activation_is_exercised(): void
    {
        $user = new User(['mail' => 'elder@example.test']);
        $renderedEmail = null;
        $guard = new FieldReadGuard(
            new AccountFieldReadScope(),
            static fn() => AccessResult::forbidden(),
            activationEnabled: true,
        );

        try {
            $definition = EntityType::fromClass(User::class)->getFieldDefinitions()['mail'];
            $rule = new FieldReadMetadataResolver()->compile($definition);
            $guard->assertCompiled($user, $rule);
            $renderedEmail = $user->get('mail');
        } catch (FieldReadDenied) {
        }

        self::assertNull($renderedEmail);
    }
}
