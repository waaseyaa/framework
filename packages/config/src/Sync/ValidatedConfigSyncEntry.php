<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Schema\ConfigContentHashes;

/** One strict source artifact and its three verified content identities. @api */
final readonly class ValidatedConfigSyncEntry
{
    public function __construct(
        public ConfigSyncFile $file,
        public string $exactBytes,
        public ConfigContentHashes $hashes,
    ) {}

    public function key(): string
    {
        return $this->file->ref() . '@' . $this->file->langcode;
    }
}
