<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Waaseyaa\Api\JsonApiError;

/**
 * Value object representing an admin surface operation result.
 *
 * Maps to AdminSurfaceResult<T> in contract/types.ts.
 */
final readonly class AdminSurfaceResultData
{
    private const string SAVE_ADVISORY_CODE = 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED';

    private const int MAX_SAVE_ADVISORIES = 32;

    /**
     * @param bool                      $ok
     * @param mixed                     $data
     * @param array<string, mixed>|null $error
     * @param array<string, mixed>      $meta
     */
    private function __construct(
        public bool $ok,
        public mixed $data = null,
        public ?array $error = null,
        public array $meta = [],
    ) {}

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data, array $meta = []): self
    {
        return new self(ok: true, data: $data, meta: $meta);
    }

    /**
     * @param array<string, string> $source
     * @param list<array<string, mixed>> $saveAdvisories
     */
    public static function error(
        int $status,
        string $title,
        ?string $detail = null,
        string $code = '',
        array $source = [],
        array $saveAdvisories = [],
    ): self {
        $projectedAdvisories = self::projectSaveAdvisories($saveAdvisories);

        return new self(
            ok: false,
            error: array_filter([
                'status' => $status,
                'title' => $title,
                'detail' => $detail,
                'code' => $code !== '' ? $code : null,
                'source' => $source !== [] ? $source : null,
                'meta' => $projectedAdvisories !== []
                    ? ['save_advisories' => $projectedAdvisories]
                    : null,
            ], fn($v) => $v !== null),
        );
    }

    /**
     * Project a JSON:API error into the Admin envelope.
     *
     * Status, title, and detail remain the legacy error shape. Machine `code`
     * and `meta` are emitted only for the save-advisory acknowledgement
     * contract; every other JSON:API meta key is dropped.
     */
    public static function fromJsonApiError(JsonApiError $error, int $fallbackStatus): self
    {
        $status = (int) $error->status;
        if ($status < 400) {
            $status = $fallbackStatus;
        }

        $code = $error->code === self::SAVE_ADVISORY_CODE ? self::SAVE_ADVISORY_CODE : '';
        $saveAdvisories = $code === self::SAVE_ADVISORY_CODE
            ? ($error->meta['save_advisories'] ?? [])
            : [];

        return self::error(
            $status,
            $error->title,
            $error->detail !== '' ? $error->detail : null,
            $code,
            saveAdvisories: is_array($saveAdvisories) ? $saveAdvisories : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error,
            'meta' => $this->meta !== [] ? $this->meta : null,
        ], fn($v) => $v !== null);
    }

    /**
     * @param list<mixed>|array<int|string, mixed> $candidates
     * @return list<array{code:string,field:string,severity:string,message:string,acknowledgement:string}>
     */
    private static function projectSaveAdvisories(array $candidates): array
    {
        $projected = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $code = $candidate['code'] ?? null;
            $field = $candidate['field'] ?? null;
            $severity = $candidate['severity'] ?? null;
            $message = $candidate['message'] ?? null;
            $acknowledgement = $candidate['acknowledgement'] ?? null;
            if (
                !is_string($code) || $code === ''
                || !is_string($field) || $field === ''
                || $severity !== 'warning'
                || !is_string($message) || $message === ''
                || !is_string($acknowledgement)
                || preg_match('/^[a-f0-9]{64}$/', $acknowledgement) !== 1
            ) {
                continue;
            }

            $projected[] = [
                'code' => $code,
                'field' => $field,
                'severity' => $severity,
                'message' => $message,
                'acknowledgement' => $acknowledgement,
            ];

            if (count($projected) >= self::MAX_SAVE_ADVISORIES) {
                break;
            }
        }

        return $projected;
    }
}
