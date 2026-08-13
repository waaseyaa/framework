<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

/** @api */
final readonly class SuppressionApplication
{
    /**
     * @param list<SiteDoctorFinding> $findings
     * @param list<SiteDoctorFinding> $suppressed
     */
    public function __construct(public array $findings, public array $suppressed) {}
}
