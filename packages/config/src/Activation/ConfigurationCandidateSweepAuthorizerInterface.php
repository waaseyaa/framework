<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/** Verifies the maintenance lease/fence before a candidate lifecycle write. @api */
interface ConfigurationCandidateSweepAuthorizerInterface
{
    public function authorize(ConfigurationCandidateSweepRequest $request): void;
}
