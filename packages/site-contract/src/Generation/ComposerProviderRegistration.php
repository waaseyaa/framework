<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * One Composer provider registration an artifact plan declares (ADR-025 D-6.6).
 *
 * `composer.json` is not modeled as generated file content — a real
 * application's manifest has hundreds of unrelated, user-owned keys — so a
 * registration is a merge instruction carried by the plan and enacted by the
 * execution authority inside the same transaction as the plan's file writes.
 *
 * `group` is plan and roster metadata only: the live Composer surface is a flat
 * list of provider FQCNs, and defining a Composer representation for `group` is
 * a separate decision with a separate consumer requirement.
 *
 * @api
 */
final readonly class ComposerProviderRegistration
{
    public function __construct(
        public string $fqcn,
        public ?string $group = null,
    ) {
        if ($fqcn === '') {
            throw new \InvalidArgumentException('Composer provider registration fqcn must not be empty.');
        }
        if ($group === '') {
            throw new \InvalidArgumentException('Composer provider registration group must not be empty when declared.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $row = ['fqcn' => $this->fqcn];
        if ($this->group !== null) {
            $row['group'] = $this->group;
        }

        return $row;
    }
}
