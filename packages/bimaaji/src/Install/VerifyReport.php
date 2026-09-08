<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Bounded, deterministic `ai:verify` result.
 *
 * Human and JSON surfaces share this structure. Neither echoes source file
 * contents or raw private paths beyond the recorded relative target path.
 *
 * @api
 */
final class VerifyReport
{
    /**
     * @param list<VerifyFinding> $findings
     * @param list<string> $verifiedClients
     * @param list<VerifyTargetStatus> $targetStatuses
     */
    public function __construct(
        public readonly ManifestReadStatus $manifestStatus,
        public readonly array $findings,
        public readonly array $verifiedClients = [],
        public readonly ?string $manifestDetail = null,
        public readonly array $targetStatuses = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->manifestStatus === ManifestReadStatus::Ok && $this->findings === [];
    }

    /**
     * Stable human-readable lines for operators and CI logs.
     *
     * @return list<string>
     */
    public function humanLines(): array
    {
        $lines = [];

        if ($this->manifestDetail !== 'not evaluated') {
            $lines[] = sprintf(
                'ai:verify: manifest %s%s',
                $this->manifestStatus->value,
                $this->manifestDetail !== null ? ' (' . $this->manifestDetail . ')' : '',
            );
        }

        if ($this->verifiedClients !== []) {
            $lines[] = 'ai:verify: clients ' . implode(', ', $this->verifiedClients);
        }

        foreach ($this->targetStatuses as $status) {
            $lines[] = sprintf(
                'ai:verify: client %s: %s wholefile=%s managed_region=%s',
                $status->clientId,
                $status->path,
                $status->wholefile,
                $status->managedRegion,
            );
        }

        foreach ($this->findings as $finding) {
            $parts = ['ai:verify:'];
            if ($finding->clientId !== null) {
                $parts[] = 'client ' . $finding->clientId . ':';
            }
            if ($finding->path !== null) {
                $parts[] = $finding->path;
            }
            $parts[] = $finding->code->value;
            if ($finding->detail !== null) {
                $parts[] = '(' . $finding->detail . ')';
            }
            $lines[] = trim(implode(' ', $parts));
        }

        if ($this->isSuccess()) {
            $lines[] = 'ai:verify: ok';
        }

        return $lines;
    }

    public function toJson(): string
    {
        $findings = array_map(static fn(VerifyFinding $finding): array => $finding->toArray(), $this->findings);
        usort(
            $findings,
            static fn(array $a, array $b): int => [$a['code'], $a['client'] ?? '', $a['path'] ?? ''] <=> [$b['code'], $b['client'] ?? '', $b['path'] ?? ''],
        );

        $targets = array_map(static fn(VerifyTargetStatus $status): array => $status->toArray(), $this->targetStatuses);
        usort(
            $targets,
            static fn(array $a, array $b): int => [$a['client'], $a['path']] <=> [$b['client'], $b['path']],
        );

        $payload = [
            'command' => 'ai:verify',
            'status' => $this->isSuccess() ? 'ok' : 'failed',
            'manifest' => [
                'status' => $this->manifestStatus->value,
            ],
            'clients' => $this->verifiedClients,
            'findings' => $findings,
            'targets' => $targets,
        ];

        if ($this->manifestDetail !== null) {
            $payload['manifest']['detail'] = $this->manifestDetail;
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
