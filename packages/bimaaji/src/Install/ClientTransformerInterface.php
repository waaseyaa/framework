<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Contract for per-client transformers used by `bin/waaseyaa bimaaji:install`.
 *
 * Each implementation knows one agent client's config-file convention and
 * converts the framework's parsed skill set into the file layout that client
 * expects to find at the consuming project's root.
 *
 * Implementations MUST:
 *  - Return a stable `clientId()` matching the CLI's `--client=<id>` argument
 *    (e.g. `claude`, `cursor`, `codex`).
 *  - Be pure with respect to filesystem state — `targetFiles()` returns the
 *    intended write set; the install command performs the writes. Transformers
 *    must not read or write files directly.
 *  - Cite the upstream convention they target in their class-level docblock
 *    (URL + date) so convention drift is caught at the next manual smoke
 *    (per the WP05 verification log).
 *
 * @api
 */
interface ClientTransformerInterface
{
    /**
     * Stable identifier for this client (matches the CLI `--client=<id>` arg).
     */
    public function clientId(): string;

    /**
     * Convert the parsed skill set into the files this client expects.
     *
     * @param list<ParsedSkill> $skills
     * @return list<TargetFile>
     */
    public function targetFiles(array $skills): array;
}
