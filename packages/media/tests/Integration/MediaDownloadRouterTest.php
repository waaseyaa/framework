<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
use Waaseyaa\Entity\EntityType;
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
        file_put_contents($this->filesRoot . '/minutes.pdf', "%PDF-1.4\nAANIIN MINUTES\n%%EOF\n");
        file_put_contents($this->filesRoot . '/disguised.pdf', '<!doctype html><script>alert(1)</script>');
        file_put_contents($this->filesRoot . '/active.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        file_put_contents($this->filesRoot . '/quoted"minutes.pdf', "%PDF-1.4\nQUOTED\n%%EOF\n");
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
        @unlink($this->filesRoot . '/minutes.pdf');
        @unlink($this->filesRoot . '/disguised.pdf');
        @unlink($this->filesRoot . '/active.svg');
        @unlink($this->filesRoot . '/quoted"minutes.pdf');
        @rmdir($this->filesRoot);
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function authorized_document_navigation_returns_complete_inline_bytes(): void
    {
        $request = $this->request(accountId: 7);
        $request->headers->set('Range', 'bytes=0-');
        $request->headers->set('User-Agent', 'Mozilla/5.0 Edg/131.0.0.0');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8');
        $request->headers->set('Sec-Fetch-Dest', 'document');
        $request->headers->set('Sec-Fetch-Mode', 'navigate');
        $response = $this->router('public://teaching.txt', allowedAccountId: 7)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Response::class, $response::class);
        self::assertSame('AANIIN', $this->capture($response));
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename="teaching.txt"', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('none', $response->headers->get('Accept-Ranges'));
        self::assertSame('6', $response->headers->get('Content-Length'));
        self::assertFalse($request->headers->has('Range'));
    }

    #[Test]
    public function non_navigation_request_remains_an_attachment(): void
    {
        $response = $this->router('public://teaching.txt', allowedAccountId: 7)->handle($this->request(accountId: 7));

        self::assertSame(Response::class, $response::class);
        self::assertSame('attachment; filename="teaching.txt"', $response->headers->get('Content-Disposition'));
        self::assertSame('6', $response->headers->get('Content-Length'));
        self::assertSame('AANIIN', $response->getContent());
    }

    #[Test]
    public function authorized_iframe_view_returns_only_sniffed_pdf_inline_with_same_origin_frame_policy(): void
    {
        $request = $this->viewRequest(accountId: 7);
        $request->headers->set('Sec-Fetch-Dest', 'iframe');
        $request->headers->set('Sec-Fetch-Mode', 'navigate');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        $response = $this->router('public://minutes.pdf', allowedAccountId: 7)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename="minutes.pdf"', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('none', $response->headers->get('Accept-Ranges'));
        self::assertSame((string) strlen("%PDF-1.4\nAANIIN MINUTES\n%%EOF\n"), $response->headers->get('Content-Length'));
        self::assertSame("%PDF-1.4\nAANIIN MINUTES\n%%EOF\n", $response->getContent());
    }

    #[Test]
    public function view_route_never_promotes_scriptable_content_from_filename_metadata_or_headers(): void
    {
        foreach (['public://disguised.pdf', 'public://active.svg'] as $sourceUri) {
            foreach (['iframe', 'document'] as $destination) {
                $request = $this->viewRequest(accountId: 7);
                $request->headers->set('Sec-Fetch-Dest', $destination);
                $request->headers->set('Sec-Fetch-Mode', 'navigate');
                $request->headers->set('Accept', 'application/pdf');
                $request->headers->set('Content-Type', 'application/pdf');

                $response = $this->router(
                    $sourceUri,
                    allowedAccountId: 7,
                    storedMimeType: 'application/pdf',
                    storedFilename: 'trusted.pdf',
                )->handle($request);

                self::assertSame(200, $response->getStatusCode());
                self::assertStringStartsWith('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
                self::assertNotSame('application/pdf', $response->headers->get('Content-Type'));
                self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            }
        }
    }

    #[Test]
    public function view_route_sanitizes_disposition_and_denies_cross_origin_framing(): void
    {
        $request = $this->viewRequest(7);
        $request->headers->set('Sec-Fetch-Dest', 'iframe');
        $request->headers->set('Sec-Fetch-Mode', 'navigate');
        $request->headers->set('Sec-Fetch-Site', 'cross-site');
        $response = $this->router('public://quoted"minutes.pdf', 7)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('inline; filename="quoted_minutes.pdf"', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function symlink_escape_is_indistinguishable_from_missing_bytes(): void
    {
        $outside = sys_get_temp_dir() . '/waaseyaa_media_outside_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($outside, "%PDF-1.4\nOUTSIDE\n%%EOF\n");
        $link = $this->filesRoot . '/escape.pdf';
        try {
            if (!symlink($outside, $link)) {
                self::markTestSkipped('Symlinks are unavailable on this host.');
            }
            $escaped = $this->router('public://escape.pdf', 7)->handle($this->viewRequest(7));
            $missing = $this->router('public://missing.pdf', 7)->handle($this->viewRequest(7));

            self::assertSame($this->responseFingerprint($missing), $this->responseFingerprint($escaped));
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    #[Test]
    public function view_route_keeps_denied_missing_malformed_and_absent_bytes_indistinguishable(): void
    {
        $router = $this->router('public://minutes.pdf', allowedAccountId: 7);
        $missing = $this->viewRequest(accountId: 7);
        $missing->attributes->set('id', '999');
        $malformed = $this->viewRequest(accountId: 7);
        $malformed->attributes->set('id', 'not-a-real-id');

        $responses = [
            $router->handle($this->viewRequest(accountId: 8)),
            $router->handle($missing),
            $this->router('public://minutes.pdf', 7, uuidLookupFinds: false)->handle($malformed),
            $this->router('public://absent.pdf', 7)->handle($this->viewRequest(7)),
            $this->router('', 7)->handle($this->viewRequest(7)),
            $this->router('public://../minutes.pdf', 7)->handle($this->viewRequest(7)),
        ];
        $expected = $this->responseFingerprint($responses[0]);

        foreach ($responses as $response) {
            self::assertSame($expected, $this->responseFingerprint($response));
            self::assertFalse($response->headers->has('Content-Disposition'));
            self::assertFalse($response->headers->has('Content-Length'));
            self::assertFalse($response->headers->has('Accept-Ranges'));
        }
    }

    #[Test]
    public function view_route_ignores_allowed_ranges_and_denied_ranges_disclose_nothing(): void
    {
        foreach (['bytes=0-3', 'bytes=999-1000', 'not-a-range'] as $range) {
            $request = $this->viewRequest(accountId: 7);
            $request->headers->set('Range', $range);
            $response = $this->router('public://minutes.pdf', 7)->handle($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame("%PDF-1.4\nAANIIN MINUTES\n%%EOF\n", $response->getContent());
            self::assertSame('none', $response->headers->get('Accept-Ranges'));
            self::assertFalse($request->headers->has('Range'));
        }

        $denied = $this->viewRequest(accountId: 8);
        $denied->headers->set('Range', 'bytes=0-0');
        self::assertSame(
            $this->responseFingerprint($this->router('public://minutes.pdf', 7)->handle($this->viewRequest(8))),
            $this->responseFingerprint($this->router('public://minutes.pdf', 7)->handle($denied)),
        );
    }

    #[Test]
    public function one_router_instance_retains_no_principal_or_disposition_across_requests(): void
    {
        $router = $this->router('public://minutes.pdf', allowedAccountId: 7);

        $allowedView = $router->handle($this->viewRequest(7));
        $deniedView = $router->handle($this->viewRequest(8));
        $allowedDownload = $router->handle($this->request(7));

        self::assertSame('inline; filename="minutes.pdf"', $allowedView->headers->get('Content-Disposition'));
        self::assertSame(404, $deniedView->getStatusCode());
        self::assertSame('attachment; filename="minutes.pdf"', $allowedDownload->headers->get('Content-Disposition'));
    }

    #[Test]
    public function denied_request_never_opens_the_source_reader(): void
    {
        $sourceReader = $this->createMock(MediaDownloadSourceReaderInterface::class);
        $sourceReader->expects(self::never())->method('sourceUri');
        $original = $this->sourceReader;
        $this->sourceReader = $sourceReader;
        try {
            $response = $this->router('public://minutes.pdf', allowedAccountId: 7)->handle($this->viewRequest(8));
        } finally {
            $this->sourceReader = $original;
        }

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function uuid_resource_identifier_resolves_int_keyed_media_before_authorization(): void
    {
        $request = $this->request(accountId: 7);
        $request->attributes->set('id', 'e14dcf5b-042c-4d88-9f34-aeace1764b66');

        $response = $this->router('public://teaching.txt', allowedAccountId: 7)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('AANIIN', $response->getContent());
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

    private function router(
        string $sourceUri,
        int $allowedAccountId,
        bool $uuidLookupFinds = true,
        string $storedMimeType = 'text/plain',
        string $storedFilename = 'teaching.txt',
    ): MediaDownloadRouter
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

        return $this->routerWithPolicy($sourceUri, $policy, $uuidLookupFinds, $storedMimeType, $storedFilename);
    }

    private function routerWithPolicy(
        string $sourceUri,
        AccessPolicyInterface $policy,
        bool $uuidLookupFinds = true,
        string $storedMimeType = 'text/plain',
        string $storedFilename = 'teaching.txt',
    ): MediaDownloadRouter
    {
        $media = new Media([
            'mid' => 10,
            'uuid' => 'e14dcf5b-042c-4d88-9f34-aeace1764b66',
            'bundle' => 'document',
            'source_uri' => $sourceUri,
            'filename' => $storedFilename,
            'mime_type' => $storedMimeType,
            'status' => 1,
            'uid' => 99,
        ]);
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturnCallback(
            static fn(int|string $id): ?Media => (string) $id === '10' ? $media : null,
        );
        $query = $this->createStub(\Waaseyaa\Entity\Storage\EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn($uuidLookupFinds ? [10] : []);
        $storage->method('getQuery')->willReturn($query);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnMap([
            ['media', new StorageBackedStubRepository($storage)],
        ]);
        // Media is int-keyed on `mid` and declares `uuid`; resolution reads the
        // declared keys, so the stub must carry them (mirrors Media's
        // #[ContentEntityKeys]).
        $manager->method('getDefinition')->willReturn(new EntityType(
            id: 'media',
            label: 'Media',
            class: Media::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'bundle'],
        ));

        return new MediaDownloadRouter($manager, new EntityAccessHandler([$policy]), $this->filesRoot, $this->sourceReader);
    }

    private function request(int $accountId): Request
    {
        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
        $request->attributes->set('_controller', MediaDownloadRouter::CONTROLLER);
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

    private function viewRequest(int $accountId): Request
    {
        $request = $this->request($accountId);
        $request->attributes->set('_controller', MediaDownloadRouter::VIEW_CONTROLLER);

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

    /** @return array{status: int, content: string, headers: array<string, list<string|null>>} */
    private function responseFingerprint(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);

        return [
            'status' => $response->getStatusCode(),
            'content' => (string) $response->getContent(),
            'headers' => $headers,
        ];
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

    private function capture(Response $response): string
    {
        return (string) $response->getContent();
    }
}
