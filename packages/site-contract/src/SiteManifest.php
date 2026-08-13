<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

use Waaseyaa\SiteContract\Capability\CapabilityDeclaration;

/** @api */
final readonly class SiteManifest
{
    /**
     * @param array<string, ContentTypeDeclaration> $contentTypes
     * @param array<string, CapabilityDeclaration> $capabilities
     * @param array<string, PersonalDataStore> $personalDataStores
     * @param array<string, RecipeSelection> $recipes
     */
    public function __construct(
        public int $schemaVersion,
        public int $generatorVersion,
        public ApplicationIdentity $application,
        public FrameworkIdentity $framework,
        public array $contentTypes,
        public array $capabilities,
        public array $personalDataStores,
        public array $recipes,
        public string $verificationCommand,
        public string $canonicalJson,
        public string $digest,
    ) {}
}
