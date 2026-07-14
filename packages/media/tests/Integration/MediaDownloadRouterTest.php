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
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Media\Http\Router\MediaDownloadRouter;
use Waaseyaa\Media\Media;

#[CoversClass(MediaDownloadRouter::class)]
final class MediaDownloadRouterTest extends TestCase
{
    private string $filesRoot;

    protected function setUp(): void
    {
        $this->filesRoot = sys_get_temp_dir() . '/waaseyaa_media_dl_' . bin2hex(random_bytes(6));
        mkdir($this->filesRoot, 0o755, true);
        file_put_contents($this->filesRoot . '/teaching.txt', 'AANIIN');
    }

    protected function tearDown(): void
    {
        @unlink($this->filesRoot . '/teaching.txt');
        @rmdir($this->filesRoot);
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
    }

    #[Test]
    public function denied_and_missing_account_requests_fail_closed_as_404(): void
    {
        $router = $this->router('public://teaching.txt', allowedAccountId: 7);

        self::assertSame(404, $router->handle($this->request(accountId: 8))->getStatusCode());

        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
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
        $media = new Media([
            'mid' => 10,
            'bundle' => 'document',
            'source_uri' => $sourceUri,
            'filename' => 'teaching.txt',
            'mime_type' => 'text/plain',
        ]);
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn($media);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->with('media')->willReturn(new StorageBackedStubRepository($storage));

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

        return new MediaDownloadRouter($manager, new EntityAccessHandler([$policy]), $this->filesRoot);
    }

    private function request(int $accountId): Request
    {
        $request = Request::create('/media/10/download');
        $request->attributes->set('id', '10');
        $request->attributes->set('_account', new class ($accountId) implements AccountInterface {
            public function __construct(private readonly int $id) {}
            public function id(): int|string { return $this->id; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['authenticated']; }
        });

        return $request;
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
