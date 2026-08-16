<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Database\DatabaseInterface;

/** Read-only CFG-03 replay preflight over committed CFG-02 state. */
final readonly class DatabaseConfigReplayStateReader implements ConfigReplayStateReaderInterface
{
    public function __construct(
        private DatabaseInterface $database,
        private ConfigurationAuthorityContext $context,
    ) {}

    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
    {
        if (!$this->database->schema()->tableExists('waaseyaa_config_manifest_replay')) {
            throw new \RuntimeException(
                'CFG-03 manifest replay state is unavailable; the configuration manifest replay migration is required.',
            );
        }

        foreach ($this->database->query(
            'SELECT last_sequence FROM waaseyaa_config_manifest_replay '
            . 'WHERE authority_id = ? AND bundle_scope = ? AND trust_key_reference = ?',
            [$this->context->authorityId, $bundleScope, $trustKeyReference],
        ) as $row) {
            return (int) $row['last_sequence'];
        }

        return null;
    }
}
