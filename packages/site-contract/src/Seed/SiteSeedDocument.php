<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Seed;

use Waaseyaa\SiteContract\ApplicationIdentity;
use Waaseyaa\SiteContract\ContentTypeDeclaration;

/**
 * The typed result of reading a `waaseyaa.site-seed` v1 document: the
 * identity and content-type decisions an operator authors, and nothing else.
 * Content types keep their authored order — the order the resolved manifest
 * will list them, and therefore the order of a capability's `public_routes`.
 *
 * @api
 */
final readonly class SiteSeedDocument
{
    /** @param array<string, ContentTypeDeclaration> $contentTypes */
    public function __construct(
        public ApplicationIdentity $application,
        public array $contentTypes,
    ) {}
}
