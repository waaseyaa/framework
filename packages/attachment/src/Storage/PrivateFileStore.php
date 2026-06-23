<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Storage;

/**
 * Resolves a `private://` file URI to a safe absolute path under a private root
 * that lives OUTSIDE any web-served directory.
 *
 * The private root is a sibling of the public `storage/files` tree (default
 * `storage/private-files`) and is deliberately never the `/files/` public
 * convention's target — so a host that serves `storage/files` statically cannot
 * reach private bytes by URL. Access to a private file is only ever granted
 * through an authorized download path (see {@see \Waaseyaa\Attachment\Http\AttachmentDownloadRouter}),
 * which enforces the owning entity's view access before streaming.
 *
 * Path containment is enforced with `realpath()`: the resolved path must be the
 * root itself or live strictly beneath it, so a `private://../../etc/passwd`-style
 * URI (or a symlink escaping the root) resolves to null, never to bytes outside
 * the private root.
 */
final class PrivateFileStore
{
    private const string SCHEME = 'private://';

    public function __construct(private readonly string $privateRoot) {}

    /**
     * Resolve a `private://<relative-path>` URI to an absolute filesystem path
     * within the private root, or null when the URI is not a private-scheme URI,
     * escapes the root, or does not point at an existing regular file.
     */
    public function resolve(string $uri): ?string
    {
        if (!str_starts_with($uri, self::SCHEME)) {
            return null;
        }

        $relative = substr($uri, \strlen(self::SCHEME));
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $rootReal = realpath($this->privateRoot);
        if ($rootReal === false) {
            return null;
        }

        $candidate = rtrim($this->privateRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // Containment: the resolved real path must be the root itself or live
        // strictly beneath it (defeats `../` traversal and symlink escapes).
        if ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
