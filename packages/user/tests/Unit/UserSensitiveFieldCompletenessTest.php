<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * Deny-list COMPLETENESS guard for the `user` entity (audit C-11 residual).
 *
 * The mass-assignment root (C-11) is closed at the right layer — `UserAccessPolicy`
 * (FieldAccessPolicyInterface) forbids the credential + privilege fields, so a
 * non-admin can't set them via the generic write path (B-1). But that protection
 * is a DENY-LIST: it enumerates the fields to forbid. The standing residual risk
 * is completeness — a NEW sensitive field added to `User` without being added to
 * the policy's forbidden set would be silently writable/readable.
 *
 * This test derives the sensitive fields from `User`'s own declared surface by
 * name pattern (not a hand-copied list), so it FAILS LOUDLY the moment a new
 * credential-shaped field (`*secret*`, `*token*`, `*_hash`, `two_factor_*`,
 * `pass`, `*recovery*`, `*private_key*`, `*api_key*`) is added to `User` without
 * a corresponding forbid rule in `UserAccessPolicy`. The patterns are deliberately
 * conservative — they match the credential class only, NOT ambiguous fields like
 * `status`/`roles`/`permissions` (which are legitimately view-open and handled by
 * the separate privileged-EDIT deny-list).
 *
 * Scope note: `user` is the framework's credential-bearing entity. A framework-WIDE
 * version of this guard (every registered entity type's sensitive fields) is not
 * cleanly expressible as a unit test because real entity types are registered by
 * application wiring (`public/index.php`), not an auto-enumerable framework
 * registry — see CL-13/Stream-4 notes; that broader guard would need a full kernel
 * boot. This pins the highest-risk entity now.
 */
#[CoversClass(UserAccessPolicy::class)]
final class UserSensitiveFieldCompletenessTest extends TestCase
{
    /**
     * Field names that are NEVER legitimately viewable by a non-admin — the
     * credential class. Conservative on purpose (avoids `status`/`roles`/etc.).
     */
    private const string SENSITIVE_PATTERN = '/(secret|passwd|password|^pass$|token|_hash$|hash$|two_factor|recovery|private_key|api_key|credential)/i';

    #[Test]
    public function everySensitiveUserFieldIsForbiddenToANonAdmin(): void
    {
        $policy = new UserAccessPolicy();
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $nonAdmin = $this->createAccount(10, ['access user profiles']);

        $sensitive = $this->sensitiveUserFieldNames();

        // Sanity: the derivation must find the known credential fields, so the
        // test can't silently pass by matching nothing.
        self::assertContains('two_factor_secret', $sensitive, 'derivation must include the declared credential fields');
        self::assertContains('pass', $sensitive, 'derivation must include the password credential key');

        foreach ($sensitive as $field) {
            self::assertTrue(
                $policy->fieldAccess($user, $field, 'view', $nonAdmin)->isForbidden(),
                sprintf(
                    "User field '%s' looks credential-sensitive but UserAccessPolicy does NOT forbid view for a non-admin. "
                    . "Add it to the policy's forbidden set (CREDENTIAL_FIELDS) — a sensitive field must never fall through to the open-by-default field gate (audit C-11 deny-list completeness).",
                    $field,
                ),
            );
        }
    }

    /**
     * Credential-shaped field names on the `user` entity: every `#[Field]`-declared
     * property whose name matches the sensitive pattern, plus the password key
     * (`pass`), which is stored in the `_data` blob rather than as a `#[Field]`
     * property.
     *
     * @return list<string>
     */
    private function sensitiveUserFieldNames(): array
    {
        $names = ['pass'];

        $reflection = new \ReflectionClass(User::class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Field::class) === []) {
                continue;
            }
            $names[] = $property->getName();
        }

        $sensitive = array_values(array_unique(array_filter(
            $names,
            fn(string $name): bool => preg_match(self::SENSITIVE_PATTERN, $name) === 1,
        )));

        return $sensitive;
    }

    /**
     * @param list<string> $permissions
     */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }
}
