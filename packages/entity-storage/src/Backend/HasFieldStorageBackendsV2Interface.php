<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Provider capability for fingerprinted privileged field-storage gateways. @api */
interface HasFieldStorageBackendsV2Interface
{
    /** @return list<FieldStorageBackendV2Interface> */
    public function fieldStorageBackendsV2(): array;
}
