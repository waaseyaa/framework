<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Advisory;

use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;

/** Bounded, value-free evidence for one migration-owned advisory acknowledgement. @api */
final readonly class SaveAdvisoryEvidence
{
    public function __construct(
        public string $migrationId,
        public string $sourceIdHash,
        public string $code,
        public string $field,
        public string $severity,
        public string $message,
        public string $acknowledgement,
    ) {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('Save advisory evidence requires a migration id.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceIdHash) !== 1) {
            throw new \InvalidArgumentException('Save advisory evidence requires a source-id hash.');
        }
        SaveAdvisory::assertCode($code);
        if ($field === '' || $severity !== SaveAdvisory::SEVERITY_WARNING || $message === '') {
            throw new \InvalidArgumentException('Save advisory evidence requires field, warning severity, and message.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $acknowledgement) !== 1) {
            throw new \InvalidArgumentException('Save advisory evidence requires an acknowledgement token.');
        }
    }

    public static function fromAdvisory(string $migrationId, string $sourceIdHash, SaveAdvisory $advisory): self
    {
        return new self(
            $migrationId,
            $sourceIdHash,
            $advisory->code,
            $advisory->field,
            $advisory->severity,
            $advisory->message,
            $advisory->acknowledgement,
        );
    }

    /** @return array{migration_id:string,source_id_hash:string,code:string,field:string,severity:string,message:string,acknowledgement:string} */
    public function toArray(): array
    {
        return [
            'migration_id' => $this->migrationId,
            'source_id_hash' => $this->sourceIdHash,
            'code' => $this->code,
            'field' => $this->field,
            'severity' => $this->severity,
            'message' => $this->message,
            'acknowledgement' => $this->acknowledgement,
        ];
    }
}
