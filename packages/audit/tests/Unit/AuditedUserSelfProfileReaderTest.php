<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\User\UserSelfProfileReaderInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\AuditedUserSelfProfileReader;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\User\User;

final class AuditedUserSelfProfileReaderTest extends TestCase
{
    #[Test]
    public function authenticated_principal_can_read_only_its_own_profile_identity(): void
    {
        $descriptors = [];
        $ledger = new class($descriptors) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$descriptors) {}

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->descriptors[] = $descriptor;

                return new PrivilegedReadReceipt('read-'.count($this->descriptors));
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $registry = new InMemoryCapabilityRegistry();
        $reader = new AuditedUserSelfProfileReader(new AuditedFieldRead($registry, $ledger), $registry);
        self::assertInstanceOf(UserSelfProfileReaderInterface::class, $reader);

        $user = new User(['uid' => 42, 'name' => 'Member', 'mail' => 'member@example.test', 'status' => 1]);
        $principal = new AuthorizationPrincipal(42, true, ['editor'], [], 'claims-v1', 'nation-a', 'community-a');

        $profile = $reader->read($user, $principal);

        self::assertSame('Member', $profile->name);
        self::assertSame('member@example.test', $profile->mail);
        self::assertCount(1, $descriptors);
        self::assertSame(['name', 'mail'], $descriptors[0]->fields);
        self::assertSame(42, $descriptors[0]->actorId);
        self::assertSame('nation-a', $descriptors[0]->tenantId);
        self::assertSame('community-a', $descriptors[0]->communityId);
    }

    #[Test]
    public function mismatched_or_anonymous_principal_is_rejected_before_any_read(): void
    {
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                TestCase::fail('A denied self-profile read must not reserve authority.');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $registry = new InMemoryCapabilityRegistry();
        $reader = new AuditedUserSelfProfileReader(new AuditedFieldRead($registry, $ledger), $registry);
        $user = new User(['uid' => 42, 'name' => 'Member', 'mail' => 'member@example.test', 'status' => 1]);

        foreach ([
            new AuthorizationPrincipal(7, true, [], [], 'other-v1'),
            new AuthorizationPrincipal(42, false, [], [], 'anonymous-v1'),
        ] as $principal) {
            try {
                $reader->read($user, $principal);
                self::fail('A non-self principal must be rejected.');
            } catch (\LogicException $exception) {
                self::assertSame('Self-profile identity may only be read by its authenticated account.', $exception->getMessage());
            }
        }
    }
}
