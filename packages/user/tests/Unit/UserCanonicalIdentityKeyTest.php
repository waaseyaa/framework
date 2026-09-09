<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityValueContainer;
use Waaseyaa\Entity\Hydration\HydrationContext;
use Waaseyaa\User\User;

#[CoversClass(User::class)]
final class UserCanonicalIdentityKeyTest extends TestCase
{
    #[Test]
    public function constructionDerivesCanonicalKeysAndIgnoresForgedValues(): void
    {
        $user = new User([
            'name' => 'Community.Owner',
            'mail' => 'Owner@Example.TEST',
            'identity_name_key' => 'forged-name',
            'identity_mail_key' => 'forged-mail',
        ]);

        $values = self::rawValues($user);
        self::assertSame('community.owner', $values['identity_name_key']);
        self::assertSame('owner@example.test', $values['identity_mail_key']);
    }

    #[Test]
    public function allRenameSettersKeepCanonicalKeysSynchronized(): void
    {
        $user = new User(['name' => 'first', 'mail' => 'first@example.test']);
        $user->setName('Second.Owner');
        $user->setEmail('Second@Example.TEST');

        $values = self::rawValues($user);
        self::assertSame('second.owner', $values['identity_name_key']);
        self::assertSame('second@example.test', $values['identity_mail_key']);
    }

    #[Test]
    public function directIdentityKeyMutationIsRefused(): void
    {
        $user = new User(['name' => 'owner']);

        $this->expectException(\InvalidArgumentException::class);
        $user->set('identity_name_key', 'forged');
    }

    #[Test]
    public function storageHydrationPreservesHistoricalNullBindings(): void
    {
        $user = User::fromStorage([
            'uid' => 41,
            'name' => 'historical-owner',
            'mail' => 'historical@example.test',
            'identity_name_key' => null,
            'identity_mail_key' => null,
        ], new HydrationContext('user', ['id' => 'uid', 'label' => 'name']));

        $values = self::rawValues($user);
        self::assertNull($values['identity_name_key']);
        self::assertNull($values['identity_mail_key']);
    }

    /** @return array<string, mixed> */
    private static function rawValues(User $user): array
    {
        $property = new \ReflectionProperty(EntityBase::class, 'valueContainer');
        $container = $property->getValue($user);
        self::assertInstanceOf(EntityValueContainer::class, $container);

        return $container->rawValues();
    }
}
