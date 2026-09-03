<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Seed;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\SiteContract\ManifestShapeReader;

/**
 * `waaseyaa.site-seed` v1 — the second authored input the fresh-site golden
 * path accepts (#2442). A `site:init --preset` run needs only the decisions a
 * preset cannot make on an operator's behalf (application identity, the
 * public content types and their canonical routes); the preset supplies the
 * rest. That smaller document is still an **authored input contract**, so it
 * is closed and versioned exactly as `waaseyaa.site` is, and for the same
 * reason: an unknown, duplicated, or ill-typed key is an operator decision,
 * and silently discarding one while reporting success is the failure this
 * schema exists to prevent.
 *
 * This is not a second validation authority. It reuses
 * {@see ManifestShapeReader} — the same violation codes, the same JSON
 * Pointer construction, the same reject-unknown-then-require-then-type order
 * as `SiteManifestParser` — and reads the *same two sections* that parser
 * reads, through the same shared readers. There is no separate JSON-Schema
 * mirror the way `SiteManifestSchema` has one, because a seed is an input to
 * `site:init` and never a generated artifact a consumer's tooling validates:
 * the closed vocabulary below is the whole contract.
 *
 * @api
 */
final class SiteSeedParser
{
    use ManifestShapeReader;

    public const string SCHEMA = 'waaseyaa.site-seed';

    public const int CURRENT_VERSION = 1;

    /** @var list<string> */
    private const array ROOT_KEYS = ['schema', 'version', 'application', 'content_types'];

    public function parse(string $yaml, string $source = '<memory>'): SiteSeedDocument
    {
        try {
            $decoded = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            $this->fail($source, 'SITE000_INVALID_YAML', '/', $exception->getMessage(), $exception);
        }

        $root = $this->shape($decoded, self::ROOT_KEYS, self::ROOT_KEYS, '/', $source);
        if ($this->string($root['schema'], '/schema', $source) !== self::SCHEMA) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/schema', 'Expected ' . self::SCHEMA . '.');
        }
        if ($this->integer($root['version'], '/version', $source) !== self::CURRENT_VERSION) {
            $this->fail(
                $source,
                'SITE003_UNSUPPORTED_SCHEMA_VERSION',
                '/version',
                'Only preset seed schema version ' . self::CURRENT_VERSION . ' is supported.',
            );
        }

        return new SiteSeedDocument(
            $this->applicationIdentity($root['application'], $source),
            $this->contentTypeDeclarations($root['content_types'], $source),
        );
    }
}
