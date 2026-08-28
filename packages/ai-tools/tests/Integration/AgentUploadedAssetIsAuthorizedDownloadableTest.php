<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Tools\Content\MediaAssetStore;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Media\Http\AuditedMediaDownloadSourceReader;
use Waaseyaa\Media\Http\Router\MediaDownloadRouter;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaAccessPolicy;

/**
 * #2517 acceptance: an asset uploaded through the curated agent tooling must be
 * retrievable through the framework's own authorized download route, and must
 * be refused there when the caller may not view the media row.
 *
 * This composes the two real halves — {@see MediaAssetStore} writing the row
 * and {@see MediaDownloadRouter} serving it — over real SQLite storage, the
 * real {@see MediaAccessPolicy}, and the real protected-field-read guard. The
 * defect it pins was invisible to either half alone: each was internally
 * consistent, and only the pair disagreed about `source_uri`.
 */
#[CoversNothing]
final class AgentUploadedAssetIsAuthorizedDownloadableTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const int UPLOADER_UID = 4242;

    private string $filesRoot;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $mediaRepository;
    private EntityAccessHandler $accessHandler;
    private AuditedMediaDownloadSourceReader $sourceReader;

    protected function setUp(): void
    {
        $this->filesRoot = sys_get_temp_dir() . '/waaseyaa_agent_assets_' . bin2hex(random_bytes(6));

        $database = DBALDatabase::createSqlite();
        $mediaType = new EntityType(
            id: 'media',
            label: 'Media',
            class: Media::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'bundle'],
        );
        new SqlSchemaHandler($mediaType, $database)->ensureTable();
        $this->mediaRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $mediaType,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'mid'),
            new EventDispatcher(),
            database: $database,
        );
        $repository = $this->mediaRepository;
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $this->entityTypeManager->registerEntityType($mediaType);

        $this->accessHandler = new EntityAccessHandler([new MediaAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard(
            new AccountFieldReadScope(),
            $this->accessHandler->checkProtectedFieldRead(...),
        ));

        $capabilities = new InMemoryCapabilityRegistry();
        $this->sourceReader = new AuditedMediaDownloadSourceReader(
            new AuditedFieldRead($capabilities, new class implements StrictPrivilegedReadLedgerInterface {
                public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
                {
                    return new PrivilegedReadReceipt('agent-asset-download-test');
                }

                public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
            }),
            $capabilities,
            'test-classification',
            'test-policy',
        );
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        new Filesystem()->remove($this->filesRoot);
    }

    #[Test]
    public function an_agent_uploaded_asset_is_served_by_the_authorized_download_route(): void
    {
        $uploaded = $this->store()->upload('pixel.png', $this->pngBytes(), $this->principal(
            self::UPLOADER_UID,
            ['administer media'],
        ));

        $response = $this->router()->handle($this->downloadRequest(
            $uploaded['media_id'],
            self::UPLOADER_UID,
            ['administer media'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->pngBytes(), $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_authorized_download_route_refuses_a_caller_who_may_not_view_the_media(): void
    {
        $uploaded = $this->store()->upload('pixel.png', $this->pngBytes(), $this->principal(
            self::UPLOADER_UID,
            ['administer media'],
        ));

        // A different, unprivileged account: MediaAccessPolicy grants view only
        // with 'access media' on published rows.
        $response = $this->router()->handle($this->downloadRequest($uploaded['media_id'], 9, []));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Retraction through the route: unpublishing the row withdraws the bytes,
     * even though they remain content-addressed on disk.
     */
    #[Test]
    public function unpublishing_the_media_row_retracts_it_from_the_download_route(): void
    {
        $uploaded = $this->store()->upload('pixel.png', $this->pngBytes(), $this->principal(
            self::UPLOADER_UID,
            ['administer media'],
        ));

        $reader = $this->principal(9, ['access media']);
        $router = $this->router();
        self::assertSame(
            200,
            $router->handle($this->downloadRequest($uploaded['media_id'], 9, ['access media']))->getStatusCode(),
        );

        $media = $this->mediaRepository->find($uploaded['media_id']);
        self::assertInstanceOf(Media::class, $media);
        $media->set('status', 0);
        $this->mediaRepository->save($media);

        self::assertSame(
            404,
            $router->handle($this->downloadRequest($uploaded['media_id'], 9, ['access media']))->getStatusCode(),
        );
        self::assertNull($this->store()->get($uploaded['asset_id'], $reader));
    }

    private function store(): MediaAssetStore
    {
        return new MediaAssetStore(
            $this->mediaRepository,
            $this->filesRoot . '/agent-uploads',
            '/media/uploads',
            $this->accessHandler,
            $this->filesRoot,
        );
    }

    private function router(): MediaDownloadRouter
    {
        return new MediaDownloadRouter(
            $this->entityTypeManager,
            $this->accessHandler,
            $this->filesRoot,
            $this->sourceReader,
        );
    }

    /** @param list<string> $permissions */
    private function downloadRequest(string $mediaId, int $accountId, array $permissions): Request
    {
        $request = Request::create('/media/' . $mediaId . '/download');
        $request->attributes->set('id', $mediaId);
        $request->attributes->set('_controller', MediaDownloadRouter::CONTROLLER);
        $request->attributes->set('_account', $this->account($accountId, $permissions));
        $request->attributes->set('_authorization_principal', $this->principal($accountId, $permissions));

        return $request;
    }

    /** @param list<string> $permissions */
    private function principal(int $accountId, array $permissions): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            $accountId,
            true,
            ['authenticated'],
            $permissions,
            'agent-asset-download-test',
        );
    }

    /** @param list<string> $permissions */
    private function account(int $accountId, array $permissions): AccountInterface
    {
        return new class ($accountId, $permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly int $id, private readonly array $permissions) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return ['authenticated'];
            }
        };
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::PNG_BASE64, true);
        self::assertIsString($bytes);

        return $bytes;
    }
}
