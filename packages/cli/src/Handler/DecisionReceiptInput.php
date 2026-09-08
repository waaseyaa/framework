<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;

/**
 * Closed, read-once decoding for `--decision-receipt` on site-contract commands.
 */
final class DecisionReceiptInput
{
    public static function load(string $receiptPath, string $projectRoot): BlueprintDecisionReceipt
    {
        try {
            $path = self::resolve($receiptPath, $projectRoot);
            if (!is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException('The decision receipt must be a readable JSON document.');
            }
            // Read exactly once: a later path replacement cannot change the
            // immutable approval snapshot used by this invocation.
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \InvalidArgumentException('The decision receipt could not be read.');
            }
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException('The decision receipt must be a JSON object.');
            }

            return BlueprintDecisionReceipt::fromArray($document, $receiptPath);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            throw new SiteManifestValidationException($receiptPath, [new ManifestViolation(
                'SITE050_DECISION_RECEIPT_INVALID',
                '/decision_receipt',
                'Expected a valid closed blueprint decision receipt JSON document.',
            )], $exception);
        }
    }

    /** Absolute, or project-relative. */
    private static function resolve(string $receiptPath, string $projectRoot): string
    {
        if (str_starts_with($receiptPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $receiptPath) === 1) {
            return $receiptPath;
        }

        return rtrim($projectRoot, '/\\') . '/' . $receiptPath;
    }
}
