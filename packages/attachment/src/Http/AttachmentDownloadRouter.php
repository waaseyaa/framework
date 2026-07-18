<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Authorized download of a private attachment's bytes.
 *
 * `GET /attachment/{id}/download` (controller `attachment.download`). Entity-row
 * access policies protect the attachment RECORD; this is the only path that
 * serves its BYTES, and it enforces the SAME access decision before streaming:
 * deny-by-default (`isAllowed()`), fail-closed, and 404-on-deny so the endpoint
 * cannot be used as an existence oracle.
 *
 * Attachment view access delegates to the parent entity (ParentDelegatedAccessPolicy),
 * so a download is permitted exactly when the caller may view the parent. Bytes
 * are read only from the `private://` root (outside any web-served directory) via
 * {@see PrivateFileStore}; a non-`private://` `storage_uri` (e.g. a legitimately
 * public asset) or missing bytes resolve to 404 here — public assets are served
 * by the host's normal static path, not through this authorized route.
 */
final class AttachmentDownloadRouter implements DomainRouterInterface
{
    public const string CONTROLLER = 'attachment.download';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly PrivateFileStore $privateStore,
        private readonly AttachmentDownloadMetadataReaderInterface $metadataReader,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === self::CONTROLLER;
    }

    public function handle(Request $request): Response
    {
        $id = (string) $request->attributes->get('id', '');
        $account = $request->attributes->get('_account');

        // C-22 WP3: read path now goes through the canonical repository.
        $attachment = $id !== ''
            ? $this->entityTypeManager->getRepository('attachment')->find($id)
            : null;

        // Deny-by-default, fail-closed: anything we can't prove viewable → 404
        // (never 403 — that would leak existence). No account bound, no such
        // attachment, or the view check is not Allowed all return the same 404.
        if (
            !$attachment instanceof Attachment
            || !$account instanceof AccountInterface
            || !$this->accessHandler->check($attachment, 'view', $account)->isAllowed()
        ) {
            return $this->notFound();
        }

        $metadata = $this->metadataReader->read($attachment, $account);
        $path = $this->privateStore->resolve($metadata->storageUri);
        if ($path === null) {
            // Not a private-scheme file, escapes the root, or bytes are missing.
            return $this->notFound();
        }

        $contentType = $metadata->contentType;
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        return new StreamedResponse(
            static function () use ($path): void {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    return;
                }
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                }
                fclose($handle);
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => $this->contentDisposition($metadata->filename),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Builds an RFC 6266 Content-Disposition value carrying both an ASCII-safe
     * `filename=` fallback (for user agents that don't understand `filename*`)
     * and — for every well-formed name, ASCII or not — an RFC 5987
     * `filename*=UTF-8''<percent-encoded>` extended parameter so Anishinaabemowin
     * and other non-ASCII filenames survive intact for RFC 6266-aware clients.
     */
    private function contentDisposition(string $filename): string
    {
        $header = sprintf('attachment; filename="%s"', $this->safeFilename($filename));

        $encoded = $this->rfc5987Encode($filename);
        if ($encoded !== null) {
            $header .= sprintf("; filename*=UTF-8''%s", $encoded);
        }

        return $header;
    }

    private function safeFilename(string $filename): string
    {
        $base = basename($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        if ($clean === null || $clean === '') {
            return 'download';
        }

        // ASCII-only at this point, so a byte cap is a character cap. Keeps a
        // pathological stored name from ballooning the header (mirrors the
        // 255-char cap in rfc5987Encode()); names ≤255 chars are untouched.
        return substr($clean, 0, 255);
    }

    /**
     * RFC 5987 attr-char percent-encoding of the basename (emitted for every
     * valid name, ASCII included — one code path), or null when there is nothing
     * to encode (empty basename) or the stored filename is not valid UTF-8
     * (malformed data must not produce a malformed header).
     *
     * `rawurlencode()` leaves only `A-Za-z0-9-_.~` unescaped, which is a strict
     * subset of RFC 5987 attr-char (`ALPHA / DIGIT / "!" / "#" / "$" / "&" / "+" /
     * "-" / "." / "^" / "_" / "`" / "|" / "~"`); over-encoding a few extra attr-char
     * characters is RFC-compliant and safe, unlike under-encoding.
     *
     * Directional-formatting characters (RTLO & friends) are stripped first: the
     * pre-RFC-5987 header was ASCII-only and could never carry U+202E, so keeping
     * it out closes the extension-spoofing vector this feature would otherwise
     * open ("photo\u{202E}gnp.exe" rendering as "photoexe.png" in a save dialog).
     * ZWJ/ZWNJ are deliberately NOT stripped — they are orthographically
     * meaningful (Persian, Indic scripts). The name is also capped at 255
     * characters so a pathological stored name (the field lives in the unbounded
     * `_data` blob) cannot balloon the header past proxy line limits.
     */
    private function rfc5987Encode(string $filename): ?string
    {
        $base = basename($filename);
        if ($base === '' || !mb_check_encoding($base, 'UTF-8')) {
            return null;
        }

        $base = (string) preg_replace(
            '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
            '',
            $base,
        );
        if ($base === '') {
            return null;
        }

        if (mb_strlen($base, 'UTF-8') > 255) {
            $base = mb_substr($base, 0, 255, 'UTF-8');
        }

        return rawurlencode($base);
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
