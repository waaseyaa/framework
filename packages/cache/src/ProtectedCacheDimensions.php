<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

/** Complete access dimensions required by a protected-result cache key. @api */
final readonly class ProtectedCacheDimensions
{
    public function __construct(
        public string $principalId,
        public string $claimsGeneration,
        public ?string $tenantId,
        public ?string $communityId,
        public string $classificationGeneration,
        public string $policyGeneration,
        public string $bundle,
        public string $language,
        public int|string|null $revisionId,
    ) {
        if ($principalId === '' || $claimsGeneration === '' || $classificationGeneration === '' || $policyGeneration === '' || $bundle === '' || $language === '') {
            throw new \InvalidArgumentException('Protected cache dimensions require principal, claims, generations, bundle, and language identities.');
        }
    }

    public function keySuffix(): string
    {
        return hash('sha256', json_encode([
            $this->principalId,
            $this->claimsGeneration,
            $this->tenantId,
            $this->communityId,
            $this->classificationGeneration,
            $this->policyGeneration,
            $this->bundle,
            $this->language,
            $this->revisionId,
        ], JSON_THROW_ON_ERROR));
    }
}
