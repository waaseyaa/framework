<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * One bounded `ai:verify` finding — no file contents or secrets.
 *
 * @api
 */
final readonly class VerifyFinding
{
    public function __construct(
        public VerifyFindingCode $code,
        public ?string $clientId = null,
        public ?string $path = null,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{code: string, client?: string, path?: string, detail?: string}
     */
    public function toArray(): array
    {
        $row = ['code' => $this->code->value];
        if ($this->clientId !== null) {
            $row['client'] = $this->clientId;
        }
        if ($this->path !== null) {
            $row['path'] = $this->path;
        }
        if ($this->detail !== null) {
            $row['detail'] = $this->detail;
        }

        return $row;
    }
}
