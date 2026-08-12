<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationGenerationResolverInterface;
use Waaseyaa\Database\DatabaseInterface;

final class DatabaseConfigurationGenerationResolver implements ConfigurationGenerationResolverInterface
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function bind(ConfigurationAuthorityContext $context): ConfigurationAuthorityContext
    {
        $schema = $this->database->schema();
        if (!$schema->tableExists('waaseyaa_config_activation') || !$schema->tableExists('waaseyaa_config_generation')) {
            return $context;
        }

        $activation = null;
        foreach ($this->database->query(
            'SELECT generation_id, activation_sequence FROM waaseyaa_config_activation WHERE authority_id = ?',
            [$context->authorityId],
        ) as $row) {
            $activation = $row;
        }
        if ($activation === null) {
            return $context;
        }

        $generation = null;
        foreach ($this->database->query(
            'SELECT activation_sequence, lifecycle_state FROM waaseyaa_config_generation '
            . 'WHERE authority_id = ? AND generation_id = ?',
            [$context->authorityId, (string) $activation['generation_id']],
        ) as $row) {
            $generation = $row;
        }
        if ($generation === null
            || (int) $generation['activation_sequence'] !== (int) $activation['activation_sequence']
            || (string) $generation['lifecycle_state'] !== 'active'
        ) {
            throw new ConfigurationAuthorityConflictException(
                'configuration.authority.v1 activation pointer does not identify one matching active generation.',
            );
        }

        return new ConfigurationAuthorityContext(
            authorityId: $context->authorityId,
            databaseIdentity: $context->databaseIdentity,
            syncPath: $context->syncPath,
            selectorProvenance: $context->selectorProvenance,
            activeGenerationId: (string) $activation['generation_id'],
            activationSequence: (int) $activation['activation_sequence'],
        );
    }
}
