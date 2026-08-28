<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldReadDefinitionInterface;
use Waaseyaa\User\User;

final class UserFieldReadClassificationTest extends TestCase
{
    public function test_every_identity_field_has_an_explicit_reviewed_level(): void
    {
        $definitions = EntityType::fromClass(User::class)->getFieldDefinitions();

        $expected = [
            'uid' => FieldReadLevel::Public,
            'uuid' => FieldReadLevel::Public,
            'name' => FieldReadLevel::Protected,
            'mail' => FieldReadLevel::Internal,
            'pass' => FieldReadLevel::Internal,
            // #2544: a credential imported from another system, pending one-time
            // upgrade. It is a password equivalent while it exists, so it
            // carries `pass`'s classification exactly.
            'legacy_pass' => FieldReadLevel::Internal,
            'roles' => FieldReadLevel::Internal,
            'permissions' => FieldReadLevel::Internal,
            'email_verified' => FieldReadLevel::Internal,
            'status' => FieldReadLevel::Protected,
            'created' => FieldReadLevel::Internal,
            'two_factor_secret' => FieldReadLevel::Internal,
            'two_factor_recovery_codes_hash' => FieldReadLevel::Internal,
            'two_factor_last_used_step' => FieldReadLevel::Internal,
        ];

        self::assertEqualsCanonicalizing(array_keys($expected), array_keys($definitions));
        foreach ($expected as $field => $level) {
            self::assertInstanceOf(FieldReadDefinitionInterface::class, $definitions[$field]);
            self::assertSame($level, $definitions[$field]->getReadLevel(), $field);
        }

        self::assertTrue(
            $definitions['status']->getSetting('authorizationInput'),
            'User.status must be an explicit non-recursive authorization input.',
        );
    }
}
