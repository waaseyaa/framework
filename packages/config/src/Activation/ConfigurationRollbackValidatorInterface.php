<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Mandatory compatibility gate for retained-generation rollback. @api */
interface ConfigurationRollbackValidatorInterface
{
    /** @param list<ConfigSyncFile> $targetFiles */
    public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void;
}
