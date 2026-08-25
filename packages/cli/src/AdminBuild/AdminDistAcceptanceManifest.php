<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Versioned, machine-readable acceptance manifest for the committed Admin
 * bundle. It ships inside waaseyaa/admin-surface next to the published tree,
 * so a consumer resolving a Framework RELEASE can re-accept the exact released
 * bytes from the installed package alone — it never has to copy a hash from a
 * candidate branch.
 *
 * Identity is deterministic: `identityDigest` covers the whole document except
 * `identityDigest` itself and every top-level key named in `identityExcludes`.
 * The excluded `acceptance` section is the transition record of the run that
 * last changed the published tree — build provenance, the exact Node/npm
 * runtime, the broader intermediate artifact count, and the changed/removed
 * path inventory. Those are evidence, not identity, so a different toolchain
 * patch release or a different starting conflict side cannot move identity.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final readonly class AdminDistAcceptanceManifest
{
    public const string PATH = 'packages/admin-surface/dist.manifest.json';
    public const int VERSION = 1;

    /** @var list<string> */
    public const array IDENTITY_EXCLUDES = ['acceptance'];

    /** @param array<string, mixed> $document */
    private function __construct(public array $document) {}

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document): self
    {
        unset($document['identityDigest']);
        $digest = self::computeIdentityDigest($document);

        $ordered = [];
        foreach (['manifestVersion', 'identityDigest', 'identityExcludes', 'release', 'source', 'published', 'markers', 'acceptance'] as $key) {
            if ($key === 'identityDigest') {
                $ordered['identityDigest'] = $digest;
                continue;
            }
            if (array_key_exists($key, $document)) {
                $ordered[$key] = $document[$key];
            }
        }
        foreach ($document as $key => $value) {
            $ordered[$key] ??= $value;
        }

        return new self($ordered);
    }

    public static function fromJson(string $json): self
    {
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AdminDistAcceptanceException('manifest-unreadable', [self::PATH]);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new AdminDistAcceptanceException('manifest-invalid', [self::PATH]);
        }

        /** @var array<string, mixed> $document */
        return new self($document);
    }

    public function identityDigest(): string
    {
        $digest = $this->document['identityDigest'] ?? null;

        return is_string($digest) ? $digest : '';
    }

    /** The digest the document's own content implies, for tamper detection. */
    public function recomputedIdentityDigest(): string
    {
        $document = $this->document;
        unset($document['identityDigest']);

        return self::computeIdentityDigest($document);
    }

    public function toJson(): string
    {
        return json_encode(
            $this->document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /** @param array<string, mixed> $document */
    private static function computeIdentityDigest(array $document): string
    {
        $excludes = $document['identityExcludes'] ?? self::IDENTITY_EXCLUDES;
        if (is_array($excludes)) {
            foreach ($excludes as $key) {
                if (is_string($key)) {
                    unset($document[$key]);
                }
            }
        }

        return hash('sha256', json_encode(
            self::canonicalize($document),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $canonical = array_map(static fn(mixed $item): mixed => self::canonicalize($item), $value);
        if (!array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
