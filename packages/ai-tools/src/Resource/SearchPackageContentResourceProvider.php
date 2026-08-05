<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Version-bounded adapter over the optional principal-safe Search catalogue. */
final class SearchPackageContentResourceProvider implements ContentResourceProviderInterface
{
    private const string CATALOGUE = 'Waaseyaa\\Search\\SearchContentCatalogueInterface';
    private const string PROJECTION = 'Waaseyaa\\Search\\SearchCandidateProjection';
    private const string URI_PREFIX = 'waaseyaa://content/';
    private const int MAX_PATH_BYTES = 1_024;

    /** @param \Closure(): object $catalogueResolver */
    public function __construct(private readonly \Closure $catalogueResolver) {}

    public static function isAvailable(): bool
    {
        return interface_exists(self::CATALOGUE) && class_exists(self::PROJECTION);
    }

    public static function catalogueServiceId(): string
    {
        return self::CATALOGUE;
    }

    public function list(AuthorizationPrincipalInterface $principal): array
    {
        $catalogue = $this->catalogue();
        $list = \Closure::fromCallable([$catalogue, 'list']);
        $source = $list($principal);
        if (!is_array($source) || !array_is_list($source)) {
            throw new \RuntimeException('The optional Search catalogue returned an invalid list.');
        }

        $resources = [];
        foreach ($source as $projection) {
            if (!is_object($projection) || !is_a($projection, self::PROJECTION)) {
                throw new \RuntimeException('The optional Search catalogue returned an invalid projection.');
            }
            $path = self::projectionString($projection, 'url', self::MAX_PATH_BYTES);
            try {
                $uri = self::uriForPath($path);
            } catch (MalformedContentResourceUriException) {
                continue;
            }
            $token = substr($uri, strlen(self::URI_PREFIX));
            $title = self::projectionString($projection, 'title', 512);
            try {
                $resources[] = new ContentResourceDescriptor(
                    uri: $uri,
                    name: 'content:' . $token,
                    title: $title !== '' ? $title : $path,
                    description: self::projectionString($projection, 'sourceName', 2_048),
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $resources;
    }

    public function templates(): array
    {
        return [new ContentResourceTemplate(
            uriTemplate: self::URI_PREFIX . '{public_path_token}',
            name: 'content-by-public-path',
            title: 'CMS content by public path',
            description: 'Read principal-visible CMS content using the canonical unpadded base64url token of its public path.',
        )];
    }

    public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent
    {
        if (!str_starts_with($uri, self::URI_PREFIX)) {
            return null;
        }
        $path = self::pathFromUri($uri);
        $catalogue = $this->catalogue();
        $read = \Closure::fromCallable([$catalogue, 'readByPublicPath']);
        $projection = $read($path, $principal);
        if ($projection === null) {
            return null;
        }
        if (!is_object($projection) || !is_a($projection, self::PROJECTION)) {
            throw new \RuntimeException('The optional Search catalogue returned an invalid projection.');
        }
        if (!hash_equals($path, self::projectionString($projection, 'url', self::MAX_PATH_BYTES))) {
            return null;
        }

        return new ContentResourceContent(
            $uri,
            self::projectionString($projection, 'body', ContentResourceContent::MAX_TEXT_BYTES),
        );
    }

    public static function uriForPath(string $path): string
    {
        self::assertCanonicalPath($path);

        return self::URI_PREFIX . rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    public static function pathFromUri(string $uri): string
    {
        if (!str_starts_with($uri, self::URI_PREFIX)) {
            throw new MalformedContentResourceUriException('Malformed content resource URI.');
        }
        $token = substr($uri, strlen(self::URI_PREFIX));
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new MalformedContentResourceUriException('Malformed content resource URI.');
        }
        $padding = (4 - strlen($token) % 4) % 4;
        $decoded = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $token
        ) {
            throw new MalformedContentResourceUriException('Malformed content resource URI.');
        }
        self::assertCanonicalPath($decoded);

        return $decoded;
    }

    private function catalogue(): object
    {
        $catalogue = ($this->catalogueResolver)();
        if (!is_a($catalogue, self::CATALOGUE)) {
            throw new \RuntimeException('The optional Search catalogue binding is unavailable.');
        }

        return $catalogue;
    }

    private static function assertCanonicalPath(string $path): void
    {
        if ($path === ''
            || strlen($path) > self::MAX_PATH_BYTES
            || !mb_check_encoding($path, 'UTF-8')
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '//')
            || preg_match('/[\x00-\x1F\x7F?#\\\\%]/u', $path) === 1
        ) {
            throw new MalformedContentResourceUriException('Malformed content resource URI.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new MalformedContentResourceUriException('Malformed content resource URI.');
            }
        }
    }

    private static function projectionString(object $projection, string $property, int $maximum): string
    {
        $value = get_object_vars($projection)[$property] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $maximum) {
            throw new \RuntimeException('The optional Search projection contains an invalid value.');
        }

        return $value;
    }
}
