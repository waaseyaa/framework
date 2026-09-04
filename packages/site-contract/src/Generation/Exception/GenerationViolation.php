<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation\Exception;

/**
 * One coded generation refusal (ADR-025 D-6.4).
 *
 * The shape is the ADR's error-envelope entry -- `{code, path?, pointer?,
 * message}` -- so a refusal raised as an exception and a refusal reported in a
 * result document are the same value, not two parallel descriptions of one
 * event. `path` names a project-relative target; `pointer` is a JSON Pointer
 * into a plan document, so a refusal about a specific artifact row or
 * registration is addressable the same way `SITE0xx` addresses a manifest.
 *
 * This mirrors `Waaseyaa\SiteContract\Exception\ManifestViolation`, which
 * plays the same role for the manifest-content family, with two differences the
 * decision requires: the code is the closed {@see GenerationErrorCode} rather
 * than a bare string, and the location is optional, because an execution
 * refusal such as a held lock has no address.
 */
final readonly class GenerationViolation
{
    public function __construct(
        public GenerationErrorCode $code,
        public string $message,
        public ?string $path = null,
        public ?string $pointer = null,
    ) {
        if ($message === '') {
            throw new \InvalidArgumentException('Generation violation message must not be empty.');
        }
        if ($path === '') {
            throw new \InvalidArgumentException('Generation violation path must not be empty when declared.');
        }
        if ($pointer === '') {
            throw new \InvalidArgumentException('Generation violation pointer must not be empty when declared.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $row = ['code' => $this->code->value, 'message' => $this->message];
        if ($this->path !== null) {
            $row['path'] = $this->path;
        }
        if ($this->pointer !== null) {
            $row['pointer'] = $this->pointer;
        }

        return $row;
    }
}
