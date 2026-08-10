<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tenancy;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Repairs legacy translation peers whose community discriminator is empty.
 *
 * Ownership is adopted only from the same entity's non-empty canonical
 * default-language row. Ambiguous or ownerless rows remain untouched.
 * @api
 */
final readonly class CommunityTranslationPeerRepairer
{
    public function __construct(private DatabaseInterface $database) {}

    public function repair(EntityTypeInterface $entityType, bool $dryRun = false): CommunityTranslationPeerRepairReport
    {
        if (($entityType->getTenancy()['scope'] ?? null) !== EntityType::TENANCY_SCOPE_COMMUNITY) {
            throw new \InvalidArgumentException(sprintf(
                'Entity type "%s" is not community-scoped.',
                $entityType->id(),
            ));
        }
        if (!$entityType->isTranslatable()) {
            throw new \InvalidArgumentException(sprintf(
                'Entity type "%s" is not translatable.',
                $entityType->id(),
            ));
        }

        $table = $entityType->id();
        $keys = $entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $langKey = $keys['langcode'] ?? 'langcode';
        $defaultLangKey = $keys['default_langcode'] ?? 'default_langcode';
        $uuidKey = $keys['uuid'] ?? null;
        foreach ([$idKey, $langKey, $defaultLangKey, 'community_id'] as $column) {
            if (!$this->database->schema()->fieldExists($table, $column)) {
                throw new \LogicException(sprintf(
                    'Community translation-peer repair requires column "%s" on entity type "%s".',
                    $column,
                    $table,
                ));
            }
        }

        $candidateFields = [$idKey, $langKey, $defaultLangKey];
        if ($uuidKey !== null) {
            $candidateFields[] = $uuidKey;
        }
        $candidates = $this->database->select($table)
            ->fields($table, $candidateFields)
            ->condition('community_id', '')
            ->execute();
        $examined = 0;
        $eligible = 0;
        $repaired = 0;
        $skipped = 0;
        $transaction = $dryRun ? null : $this->database->transaction();

        try {
            foreach ($candidates as $candidate) {
                ++$examined;
                $candidate = (array) $candidate;
                $id = (string) ($candidate[$idKey] ?? '');
                $langcode = (string) ($candidate[$langKey] ?? '');
                $defaultLangcode = (string) ($candidate[$defaultLangKey] ?? '');
                if ($id === '' || $langcode === '' || $defaultLangcode === '' || $langcode === $defaultLangcode) {
                    ++$skipped;
                    continue;
                }

                $owner = $this->canonicalOwner(
                    $table,
                    $idKey,
                    $langKey,
                    $uuidKey,
                    $id,
                    $defaultLangcode,
                    $uuidKey !== null ? (string) ($candidate[$uuidKey] ?? '') : null,
                );
                if ($owner === null) {
                    ++$skipped;
                    continue;
                }

                ++$eligible;
                if ($dryRun) {
                    continue;
                }

                $affected = $this->database->update($table)
                    ->fields(['community_id' => $owner])
                    ->condition($idKey, $id)
                    ->condition($langKey, $langcode)
                    ->condition('community_id', '')
                    ->execute();
                if ($affected === 1) {
                    ++$repaired;
                } else {
                    ++$skipped;
                }
            }
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        return new CommunityTranslationPeerRepairReport($examined, $eligible, $repaired, $skipped, $dryRun);
    }

    private function canonicalOwner(
        string $table,
        string $idKey,
        string $langKey,
        ?string $uuidKey,
        string $id,
        string $defaultLangcode,
        ?string $candidateUuid,
    ): ?string {
        $fields = ['community_id'];
        if ($uuidKey !== null) {
            $fields[] = $uuidKey;
        }
        $query = $this->database->select($table)
            ->fields($table, $fields)
            ->condition($idKey, $id)
            ->condition($langKey, $defaultLangcode)
            ->condition('community_id', '', '<>');
        foreach ($query->execute() as $row) {
            $row = (array) $row;
            if ($uuidKey !== null && ($candidateUuid === '' || (string) ($row[$uuidKey] ?? '') !== $candidateUuid)) {
                return null;
            }
            $owner = (string) $row['community_id'];
            return $owner !== '' ? $owner : null;
        }

        return null;
    }
}
