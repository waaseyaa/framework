<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

/** @api */
final readonly class ManagementManifest
{
    /** @param array<string, array{id:string,state:string,reason?:string,contract?:ManagementOperation}> $operations */
    public function __construct(
        public string $applicationId,
        public array $operations,
        public string $canonicalJson,
        public string $digest,
    ) {}
}
