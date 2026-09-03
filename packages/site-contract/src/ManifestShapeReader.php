<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;

/**
 * Shared closed-shape reading helpers used by every `waaseyaa.site` v1
 * structural parser (#2785). Extracted from `SiteManifestParser` so
 * `Blueprint\ApplicationBlueprintParser` fails closed with identical
 * semantics — same codes, same JSON Pointer construction, same "reject
 * unknown, then require, then type" order — without a second implementation.
 *
 * The two shared *sections* below — application identity and the content-type
 * list — live here for the same reason (#2442): `Seed\SiteSeedParser` reads
 * exactly those two sections out of a `waaseyaa.site-seed` document, and a
 * second copy of them would be a second authority on what an application
 * identity or a canonical route is allowed to be.
 *
 * @internal implementation detail of the package's own parsers, not an
 * extension point.
 */
trait ManifestShapeReader
{
    /**
     * @param list<string> $allowed
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function shape(
        mixed $value,
        array $allowed,
        array $required,
        string $path,
        string $source,
        bool $rejectUnknown = true,
    ): array {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a mapping.');
        }

        if ($rejectUnknown) {
            $unknown = array_values(array_diff(array_keys($value), $allowed));
            sort($unknown, SORT_STRING);
            if ($unknown !== []) {
                $unknownPath = ($path === '/' ? '' : $path) . '/' . $this->pointer($unknown[0]);
                $this->fail($source, 'SITE001_UNKNOWN_KEY', $unknownPath, 'Unknown manifest key.');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                $requiredPath = ($path === '/' ? '' : $path) . '/' . $this->pointer($key);
                $this->fail($source, 'SITE011_REQUIRED_KEY', $requiredPath, 'Required manifest key is missing.');
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path, string $source, bool $allowEmpty = true): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a list.');
        }
        if (!$allowEmpty && $value === []) {
            $this->fail($source, 'SITE012_EMPTY_VALUE', $path, 'At least one entry is required.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path, string $source, bool $allowEmpty = true): array
    {
        $rows = $this->list($value, $path, $source, $allowEmpty);
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->string($row, $path . '/' . $index, $source);
            if (isset($seen[$item])) {
                $this->fail($source, 'SITE021_DUPLICATE_VALUE', $path . '/' . $index, 'List values must be unique.');
            }
            $seen[$item] = true;
            $rows[$index] = $item;
        }

        return $rows;
    }

    private function string(mixed $value, string $path, string $source): string
    {
        if (!is_string($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a string.');
        }
        if ($value === '' || trim($value) !== $value) {
            $this->fail($source, 'SITE012_EMPTY_VALUE', $path, 'Expected a non-empty, trimmed string.');
        }

        return $value;
    }

    private function id(mixed $value, string $path, string $source): string
    {
        $id = $this->string($value, $path, $source);
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $id) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a stable lowercase identity.');
        }

        return $id;
    }

    private function boolean(mixed $value, string $path, string $source): bool
    {
        if (!is_bool($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a boolean.');
        }

        return $value;
    }

    private function integer(mixed $value, string $path, string $source): int
    {
        if (!is_int($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected an integer.');
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $path, string $source): int
    {
        $integer = $this->integer($value, $path, $source);
        if ($integer < 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a positive integer.');
        }

        return $integer;
    }

    private function sha256(mixed $value, string $path, string $source): string
    {
        $digest = $this->string($value, $path, $source);
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a lowercase SHA-256 digest.');
        }

        return $digest;
    }

    private function route(mixed $value, string $path, string $source): string
    {
        $route = $this->string($value, $path, $source);
        $segments = explode('/', $route);
        if (
            preg_match('/^\/(?!\/)[^\x00-\x20\x7F?#\\\\%]*$/D', $route) !== 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a local route path without an origin, query, or fragment.');
        }

        return $route;
    }

    private function applicationIdentity(mixed $value, string $source): ApplicationIdentity
    {
        $application = $this->shape($value, ['id', 'name', 'canonical_origin'], ['id', 'name', 'canonical_origin'], '/application', $source);
        $origin = $this->shape($application['canonical_origin'], ['config_key'], ['config_key'], '/application/canonical_origin', $source);
        $id = $this->id($application['id'], '/application/id', $source);
        $name = $this->string($application['name'], '/application/name', $source);
        $configKey = $this->string($origin['config_key'], '/application/canonical_origin/config_key', $source);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $configKey) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/application/canonical_origin/config_key', 'Expected an environment configuration key, not an origin literal.');
        }

        return new ApplicationIdentity($id, $name, $configKey);
    }

    /**
     * Declaration order is preserved; a caller that needs a canonical order
     * sorts the returned map itself.
     *
     * @return array<string, ContentTypeDeclaration>
     */
    private function contentTypeDeclarations(mixed $value, string $source): array
    {
        $rows = $this->list($value, '/content_types', $source, false);
        $result = [];
        $routes = [];
        foreach ($rows as $index => $item) {
            $path = '/content_types/' . $index;
            $row = $this->shape($item, ['id', 'canonical_route'], ['id', 'canonical_route'], $path, $source);
            $id = $this->id($row['id'], $path . '/id', $source);
            $this->assertUniqueId($result, $id, $path . '/id', $source);
            $route = $this->route($row['canonical_route'], $path . '/canonical_route', $source);
            if (isset($routes[$route])) {
                $this->fail($source, 'SITE022_DUPLICATE_ROUTE', $path . '/canonical_route', 'Canonical routes must be unique.');
            }
            $routes[$route] = true;
            $result[$id] = new ContentTypeDeclaration($id, $route);
        }

        return $result;
    }

    /** @param array<string, mixed> $items */
    private function assertUniqueId(array $items, string $id, string $path, string $source): void
    {
        if (array_key_exists($id, $items)) {
            $this->fail($source, 'SITE020_DUPLICATE_ID', $path, 'Identities must be unique within their collection.');
        }
    }

    private function pointer(string|int $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    private function fail(
        string $source,
        string $code,
        string $path,
        string $message,
        ?\Throwable $previous = null,
    ): never {
        throw new SiteManifestValidationException(
            $source,
            [new ManifestViolation($code, $path, $message)],
            $previous,
        );
    }
}
