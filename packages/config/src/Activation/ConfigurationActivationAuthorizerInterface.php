<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/** Mandatory authorization seam for every configuration activation. @api */
interface ConfigurationActivationAuthorizerInterface
{
    public function authorize(ConfigurationActivationRequest $request, bool $deletes): void;
}
