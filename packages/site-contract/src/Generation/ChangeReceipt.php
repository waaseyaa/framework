<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * One governed-change receipt (ADR-025 D-14.3).
 *
 * The envelope is closed: an authority adds detail under `domain_payload`,
 * never as a new top-level member. This type is authorized by D-14.7 -- the
 * generation binding of the protocol -- rather than by D-3's list of plan
 * types, and it stays in `site-contract` because D-14.8 forbids extracting a
 * shared protocol home before a second binding exists to observe.
 *
 * v1 emits receipts and retains none. A durable sink is a custody problem this
 * decision has not solved, and a half-solved one would be the discontinuity the
 * protocol exists to prevent: a failed append either makes the record
 * best-effort telemetry misdescribed as evidence, or fails an apply that has
 * already committed. So a receipt reaches an operator through the command's
 * machine-readable output and a caller as a return value, and nothing here
 * writes anything anywhere.
 *
 * `authority_version` is carried, never defaulted: D-14.9 gives it exactly one
 * machine-readable source per authority, and "a fixture carrying a literal
 * version integer is a defect, not a convenience".
 *
 * @api
 */
final readonly class ChangeReceipt
{
    public const int PROTOCOL_VERSION = 1;

    /** The generation authority's namespaced name (D-14.7). */
    public const string GENERATION_AUTHORITY = 'waaseyaa.generation';

    /** @param array<string, mixed> $domainPayload authority-owned detail carrying its own version */
    public function __construct(
        public string $receiptId,
        public string $authority,
        public int $authorityVersion,
        public string $operation,
        public string $planDigest,
        public ChangeOutcome $outcome,
        public string $correlationId,
        public \DateTimeImmutable $issuedAt,
        public array $domainPayload,
        public ?string $causationReceiptId = null,
        public ?string $decisionReceiptId = null,
    ) {
        foreach ([
            'receipt_id' => $receiptId,
            'authority' => $authority,
            'operation' => $operation,
            'correlation_id' => $correlationId,
        ] as $member => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("A change receipt {$member} must not be empty.");
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $planDigest) !== 1) {
            throw new \InvalidArgumentException('A change receipt plan_digest must be 64 lowercase hex characters.');
        }
        if ($causationReceiptId === '' || $decisionReceiptId === '') {
            throw new \InvalidArgumentException('A change receipt chain reference must not be empty when declared.');
        }
        if ($causationReceiptId !== null && $causationReceiptId === $decisionReceiptId) {
            throw new \InvalidArgumentException('A change receipt causation must not be its decision receipt.');
        }
        if (!is_int($domainPayload['version'] ?? null)) {
            throw new \InvalidArgumentException('A change receipt domain payload must carry its own version.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $envelope = [
            'receipt_id' => $this->receiptId,
            'protocol_version' => self::PROTOCOL_VERSION,
            'authority' => $this->authority,
            'authority_version' => $this->authorityVersion,
            'operation' => $this->operation,
            'plan_digest' => $this->planDigest,
            'outcome' => $this->outcome->value,
            'correlation_id' => $this->correlationId,
        ];
        if ($this->causationReceiptId !== null) {
            $envelope['causation_receipt_id'] = $this->causationReceiptId;
        }
        if ($this->decisionReceiptId !== null) {
            $envelope['decision_receipt_id'] = $this->decisionReceiptId;
        }
        $envelope['issued_at'] = $this->issuedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339);
        $envelope['domain_payload'] = $this->domainPayload;

        return $envelope;
    }
}
