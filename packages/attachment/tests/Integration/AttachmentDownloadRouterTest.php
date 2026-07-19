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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Http\AttachmentDownloadMetadata;
use Waaseyaa\Attachment\Http\AttachmentDownloadMetadataReaderInterface;
use Waaseyaa\Attachment\Http\AttachmentDownloadRouter;
use Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;

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

    #[Test]
    public function download_response_sets_nosniff_header(): void
    {
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin');
        $response = $router->handle($this->request('10', 1));

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function pure_ascii_filename_content_disposition_is_byte_identical_to_pre_change_output(): void
    {
        // Pins the pre-change output: `filename="report.txt"` must appear
        // verbatim. This is the fallback token every UA that doesn't
        // understand RFC 5987 falls back to.
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: 'report.txt');
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        self::assertStringStartsWith('attachment; filename="report.txt"', $header);
        // Decision: filename* is always emitted, even for pure-ASCII names, for a single
        // consistent code path (no ASCII-only special case).
        self::assertSame('attachment; filename="report.txt"; filename*=UTF-8\'\'report.txt', $header);
    }

    #[Test]
    public function syllabics_filename_carries_rfc5987_encoded_original(): void
    {
        // ASCII fallback degrades to underscores per the pre-existing sanitizer (each
        // UTF-8 byte outside [A-Za-z0-9._-] is byte-wise replaced); filename* carries
        // the percent-encoded original UTF-8 bytes so RFC 5987/6266-aware clients
        // (virtually all modern browsers) render the real Anishinaabemowin name.
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: 'ᐊᓂᔑᓈᐯᒧᐎᓐ.pdf');
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        self::assertSame(
            'attachment; filename="________________________.pdf"'
            . '; filename*=UTF-8\'\'%E1%90%8A%E1%93%82%E1%94%91%E1%93%88%E1%90%AF%E1%92%A7%E1%90%8E%E1%93%90.pdf',
            $header,
        );
    }

    #[Test]
    public function diacritic_and_glottal_filename_carries_rfc5987_encoded_original(): void
    {
        // U+02BC MODIFIER LETTER APOSTROPHE (glottal stop) + macron/acute diacritics.
        $filename = "Ozhibii\u{02BC}igan \u{0100}k\u{00ED}.pdf";
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $filename);
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        self::assertSame(
            'attachment; filename="Ozhibii__igan___k__.pdf"'
            . '; filename*=UTF-8\'\'Ozhibii%CA%BCigan%20%C4%80k%C3%AD.pdf',
            $header,
        );
    }

    #[Test]
    public function header_injection_characters_never_reach_the_header(): void
    {
        // Quote, CR, LF in the stored filename must never appear raw anywhere in the
        // emitted Content-Disposition value — the ASCII fallback sanitizer already
        // strips them to `_`, and filename* percent-encodes them.
        $filename = "evil\".txt\r\nX-Injected: yes";
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $filename);
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        // Exactly the two wrapping quotes of filename="..." — the malicious quote from
        // the stored filename must have been sanitized away, not smuggled through.
        self::assertSame(2, substr_count($header, '"'));
        self::assertStringNotContainsString("\r", $header);
        self::assertStringNotContainsString("\n", $header);
        self::assertSame(
            'attachment; filename="evil_.txt__X-Injected__yes"'
            . '; filename*=UTF-8\'\'evil%22.txt%0D%0AX-Injected%3A%20yes',
            $header,
        );
    }

    #[Test]
    public function bidi_override_characters_are_stripped_from_filename_star(): void
    {
        // U+202E (RTL OVERRIDE) makes a browser render "photo\u{202E}gnp.exe" as
        // "photoexe.png" in the save dialog — classic extension-spoofing. The old
        // ASCII-only header could never carry it; filename* must not reintroduce it.
        // Directional-formatting characters are stripped BEFORE percent-encoding
        // (ZWJ/ZWNJ are deliberately kept — they are orthographically meaningful).
        $filename = "photo\u{202E}gnp.exe";
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $filename);
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        self::assertSame(
            'attachment; filename="photo___gnp.exe"; filename*=UTF-8\'\'photognp.exe',
            $header,
        );

        // Isolate controls (U+2066–U+2069) and LRM/RLM/ALM are stripped too.
        $filename = "a\u{2066}b\u{200F}c\u{061C}d.txt";
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $filename);
        $header = (string) $router->handle($this->request('10', 1))->headers->get('Content-Disposition');

        self::assertStringEndsWith("filename*=UTF-8''abcd.txt", $header);
    }

    #[Test]
    public function overlong_filename_is_capped_in_filename_star(): void
    {
        // The filename lives in the unbounded `_data` blob, and percent-encoding
        // triples multibyte names — a pathological stored name must not balloon the
        // header past proxy/server line limits (~8KB). filename* caps at 255 chars.
        $filename = str_repeat('ᐊ', 1000) . '.pdf';
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $filename);
        $response = $router->handle($this->request('10', 1));
        $header = (string) $response->headers->get('Content-Disposition');

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(4096, \strlen($header));

        preg_match("/filename\\*=UTF-8''(.*)$/", $header, $m);
        self::assertSame(255, mb_strlen(rawurldecode($m[1]), 'UTF-8'));
    }

    #[Test]
    public function invalid_utf8_filename_omits_filename_star_and_does_not_throw(): void
    {
        $invalid = "bad\xFF\xFEname.pdf";
        $router = $this->buildRouter(parentViewableByAccountId: 1, storageUri: 'private://secret.bin', filename: $invalid);
        $response = $router->handle($this->request('10', 1));
        $header = (string) $response->headers->get('Content-Disposition');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('attachment; filename="bad__name.pdf"', $header);
        self::assertStringNotContainsString('filename*', $header);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function buildRouter(int $parentViewableByAccountId, string $storageUri, string $filename = 'report.txt'): AttachmentDownloadRouter
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'filename' => $filename,
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
        // C-22 WP3: read path now goes through the canonical repository.
        $manager->method('getRepository')->willReturnCallback(
            fn(string $type) => new StorageBackedStubRepository($type === 'attachment' ? $attachmentStorage : $parentStorage),
        );

        $handler = new EntityAccessHandler([$this->parentPolicy($parentViewableByAccountId)]);
        $handler->addPolicy(new ParentDelegatedAccessPolicy($manager, $handler));

        $metadataReader = new class ($storageUri, $filename) implements AttachmentDownloadMetadataReaderInterface {
            public function __construct(private string $storageUri, private string $filename) {}

            public function read(Attachment $attachment, AccountInterface $account): AttachmentDownloadMetadata
            {
                return new AttachmentDownloadMetadata($this->storageUri, 'text/plain', $this->filename);
            }
        };

        return new AttachmentDownloadRouter($manager, $handler, new PrivateFileStore($this->privateRoot), $metadataReader);
    }

    private function parentPolicy(int $allowedAccountId): AccessPolicyInterface
    {
        return new class ($allowedAccountId) implements AccessPolicyInterface {
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
        $account = $this->account($accountId);
        $request->attributes->set('_account', $account);
        $request->attributes->set('_authorization_principal', new AuthorizationPrincipal(
            $accountId,
            true,
            [],
            [],
            'attachment-download-test',
        ));

        return $request;
    }

    private function account(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
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
