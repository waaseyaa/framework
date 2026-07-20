<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Media\Http\Router\MediaDownloadRouter;
use Waaseyaa\Media\Http\AuditedMediaDownloadSourceReader;
use Waaseyaa\Media\Http\MediaDownloadSourceReaderInterface;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaAccessPolicy;
use Waaseyaa\User\User;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;

#[CoversClass(MediaDownloadRouter::class)]
final class MediaDownloadRouterTest extends TestCase
{
    private string $filesRoot;
    private AccountFieldReadScope $fieldReadScope;
    private MediaDownloadSourceReaderInterface $sourceReader;

    protected function setUp(): void
    {
        $this->filesRoot = sys_get_temp_dir() . '/waaseyaa_media_dl_' . bin2hex(random_bytes(6));
        mkdir($this->filesRoot, 0o755, true);
        file_put_contents($this->filesRoot . '/teaching.txt', 'AANIIN');
        $this->fieldReadScope = new AccountFieldReadScope();
        $accessHandler = new EntityAccessHandler([new MediaAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->fieldReadScope,
            $accessHandler->checkProtectedFieldRead(...),
        ));
        $capabilities = new InMemoryCapabilityRegistry();
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('media-download-test');
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $this->sourceReader = new AuditedMediaDownloadSourceReader(
            new AuditedFieldRead($capabilities, $ledger),
            $capabilities,
            'test-classification',
            'test-policy',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->filesRoot . '/teaching.txt');
        @rmdir($this->filesRoot);
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function authorized_caller_streams_public_scheme_bytes(): void
    {
        $response = $this->router('public://teaching.txt', allowedAccountId: 7)
            ->handle($this->request(accountId: 7));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('AANIIN', $this->capture($response));
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('none', $response->headers->get('Accept-Ranges'));
        self::assertSame('6', $response->headers->get('Content-Length'));
    }

    #[Test]
    public function gated_document_uses_the_immutable_principal_for_member_and_administrator_access(): void
    {
        $router = $this->routerWithPolicy('public://teaching.txt', new MediaAccessPolicy());

        $member = new User([
            'uid' => 7,
            'name' => 'Band Member',
            'mail' => 'member@example.test',
            'roles' => ['band_member'],
            'permissions' => ['access media'],
            'status' => 1,
        ]);
        $memberPrincipal = new AuthorizationPrincipal(7, true, ['band_member'], ['access media'], 'member-v1');
        self::assertSame(200, $router->handle($this->requestFor($member, $memberPrincipal))->getStatusCode());
        $this->assertUserIdentityFieldsRemainSealed($member);

        $administrator = new User([
            'uid' => 8,
            'name' => 'Administrator',
            'mail' => 'admin@example.test',
            'roles' => ['administrator'],
            'permissions' => [],
            'status' => 1,
        ]);
        $administratorPrincipal = new AuthorizationPrincipal(8, true, ['administrator'], [], 'admin-v1');
        self::assertSame(200, $router->handle($this->requestFor($administrator, $administratorPrincipal))->getStatusCode());
        $this->assertUserIdentityFieldsRemainSealed($administrator);

        $anonymous = new AuthorizationPrincipal(0, false, [], [], 'anonymous-v1');
        self::assertSame(404, $router->handle($this->requestFor($anonymous, $anonymous))->getStatusCode());
    }

    #[Test]
    public function denied_and_missing_account_requests_fail_closed_as_404(): void
    {
        $router = $this->router('public://teaching.txt', allowedAccountId: 7);

        self::assertSame(404, $router->handle($this->request(accountId: 8))->getStatusCode());

        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
        self::assertSame(404, $router->handle($request)->getStatusCode());

        $request = $this->request(accountId: 7);
        $request->attributes->remove('_authorization_principal');
        self::assertSame(404, $router->handle($request)->getStatusCode());
    }

    #[Test]
    public function non_public_and_traversal_uris_are_not_served(): void
    {
        self::assertSame(404, $this->router('private://teaching.txt', 7)->handle($this->request(7))->getStatusCode());
        self::assertSame(404, $this->router('public://../outside.txt', 7)->handle($this->request(7))->getStatusCode());
    }

    private function router(string $sourceUri, int $allowedAccountId): MediaDownloadRouter
    {
        $policy = new class ($allowedAccountId) implements AccessPolicyInterface {
            public function __construct(private readonly int $allowedAccountId) {}
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $account->id() === $this->allowedAccountId
                    ? AccessResult::allowed()
                    : AccessResult::forbidden();
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'media';
            }
        };

        return $this->routerWithPolicy($sourceUri, $policy);
    }

    private function routerWithPolicy(string $sourceUri, AccessPolicyInterface $policy): MediaDownloadRouter
    {
        $media = new Media([
            'mid' => 10,
            'bundle' => 'document',
            'source_uri' => $sourceUri,
            'filename' => 'teaching.txt',
            'mime_type' => 'text/plain',
            'status' => 1,
            'uid' => 99,
        ]);
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn($media);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->with('media')->willReturn(new StorageBackedStubRepository($storage));

        return new MediaDownloadRouter($manager, new EntityAccessHandler([$policy]), $this->filesRoot, $this->sourceReader);
    }

    private function request(int $accountId): Request
    {
        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
        $account = new class ($accountId) implements AccountInterface {
            public function __construct(private readonly int $id) {}
            public function id(): int|string { return $this->id; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['authenticated']; }
        };
        $request->attributes->set('_account', $account);
        $request->attributes->set('_authorization_principal', new AuthorizationPrincipal(
            $account->id(),
            $account->isAuthenticated(),
            $account->getRoles(),
            [],
            'media-download-test',
        ));

        return $request;
    }

    private function requestFor(AccountInterface $account, AuthorizationPrincipalInterface $principal): Request
    {
        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_authorization_principal', $principal);

        return $request;
    }

    private function assertUserIdentityFieldsRemainSealed(User $user): void
    {
        foreach (['mail', 'roles'] as $field) {
            try {
                $user->get($field);
                self::fail("User.{$field} became readable outside an authorized field-read capability.");
            } catch (FieldReadDenied) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
