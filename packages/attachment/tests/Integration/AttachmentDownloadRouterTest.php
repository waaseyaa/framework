<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Http\AttachmentDownloadRouter;
use Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * The authorized download path serves a private attachment's BYTES only to a
 * caller who may VIEW the attachment (which delegates to the parent entity) —
 * deny-by-default, fail-closed, 404-on-deny. Bytes are read only from the
 * `private://` root via {@see PrivateFileStore}.
 *
 * This is new capability (the framework previously served no attachment bytes),
 * so the access check is built in from the start; the tests pin that enforcement
 * so it cannot be loosened: an unauthorized caller (or no account, or a denied
 * parent, or a traversal URI) gets 404, never the bytes.
 */
#[CoversClass(AttachmentDownloadRouter::class)]
final class AttachmentDownloadRouterTest extends TestCase
{
    private const string SECRET = 'TOP-SECRET-ATTACHMENT-BYTES';

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateRoot = sys_get_temp_dir() . '/waaseyaa_attach_dl_' . bin2hex(random_bytes(6));
        mkdir($this->privateRoot, 0o755, true);
        file_put_contents($this->privateRoot . '/secret.bin', self::SECRET);
    }

    protected function tearDown(): void
    {
        @unlink($this->privateRoot . '/secret.bin');
        @rmdir($this->privateRoot);
        parent::tearDown();
    }

    #[Test]
    public function authorized_caller_streams_the_private_bytes(): void
    {
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin');
        $response = $router->handle($this->request(attachmentId: '10', accountId: 1));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(self::SECRET, $this->capture($response));
        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="report.txt"', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function unauthorized_caller_gets_404_not_the_bytes(): void
    {
        // Parent is viewable only by account 1; account 2 is denied → 404, no bytes.
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin');
        $response = $router->handle($this->request(attachmentId: '10', accountId: 2));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, $this->capture($response));
    }

    #[Test]
    public function missing_account_is_404_fail_closed(): void
    {
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin');
        $request = Request::create('/attachment/10/download');
        $request->attributes->set('id', '10'); // no _account bound

        self::assertSame(404, $router->handle($request)->getStatusCode());
    }

    #[Test]
    public function non_private_uri_is_404(): void
    {
        // A public:// asset is not served through the authorized path.
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'public://secret.bin');
        self::assertSame(404, $router->handle($this->request('10', 1))->getStatusCode());
    }

    #[Test]
    public function path_traversal_uri_is_contained_404(): void
    {
        file_put_contents(\dirname($this->privateRoot) . '/outside.bin', 'OUTSIDE');
        try {
            $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://../outside.bin');
            $response = $router->handle($this->request('10', 1));
            self::assertSame(404, $response->getStatusCode());
            self::assertStringNotContainsString('OUTSIDE', $this->capture($response));
        } finally {
            @unlink(\dirname($this->privateRoot) . '/outside.bin');
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function buildRouter(int $parentViewableByAccountId, string $storageUri): AttachmentDownloadRouter
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'filename' => 'report.txt',
            'content_type' => 'text/plain',
            'storage_uri' => $storageUri,
        ]);

        $attachmentStorage = $this->createStub(EntityStorageInterface::class);
        $attachmentStorage->method('load')->willReturn($attachment);

        $parent = $this->createStub(EntityInterface::class);
        $parent->method('getEntityTypeId')->willReturn('node');
        $parentStorage = $this->createStub(EntityStorageInterface::class);
        $parentStorage->method('load')->willReturn($parent);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getStorage')->willReturnCallback(
            fn(string $type): EntityStorageInterface => $type === 'attachment' ? $attachmentStorage : $parentStorage,
        );

        $handler = new EntityAccessHandler([$this->parentPolicy($parentViewableByAccountId)]);
        $handler->addPolicy(new ParentDelegatedAccessPolicy($manager, $handler));

        return new AttachmentDownloadRouter($manager, $handler, new PrivateFileStore($this->privateRoot));
    }

    private function parentPolicy(int $allowedAccountId): AccessPolicyInterface
    {
        return new class($allowedAccountId) implements AccessPolicyInterface {
            public function __construct(private readonly int $allowedAccountId) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $account->id() === $this->allowedAccountId
                    ? AccessResult::allowed('parent viewable by this account')
                    : AccessResult::forbidden('parent not viewable by this account');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }
        };
    }

    private function request(string $attachmentId, int $accountId): Request
    {
        $request = Request::create('/attachment/' . $attachmentId . '/download');
        $request->attributes->set('id', $attachmentId);
        $request->attributes->set('_account', $this->account($accountId));

        return $request;
    }

    private function account(int $id): AccountInterface
    {
        return new class($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function capture(\Symfony\Component\HttpFoundation\Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
