<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\Config\Exception\ConfigCommandCollisionException;

/**
 * Framework-side reservation point for the `config:*` CLI verb namespace.
 *
 * `ConfigCommand` serves two purposes:
 *
 * 1. **Reserved-verb registry (FR-047).** The {@see RESERVED_VERBS} constant
 *    lists the six framework-owned verb names: `export`, `import`, `diff`,
 *    `status`, `validate`, `reset`. With the `config:` prefix they form the
 *    six fully-qualified reserved verbs published in
 *    `contracts/cli-namespace.md`.
 *
 * 2. **Collision-detection hook (FR-048).** The Symfony Console application
 *    factory invokes {@see assertNoCollision()} once per command. App or
 *    extension code registering a command whose name equals a reserved verb
 *    AND whose class is not part of the framework's
 *    reserved-FQCN allowlist ({@see RESERVED_FQCNS}) causes the kernel to
 *    throw {@see ConfigCommandCollisionException} and refuse to boot.
 *
 * Apps MAY register non-reserved `config:<custom>` verbs (FR-049). They own
 * those entirely; the framework does not pre-empt the broader `config:`
 * prefix.
 *
 * Why an allowlist rather than `is_subclass_of()`? The six framework-owned
 * `ConfigExport/Import/Diff/Status/Validate/Reset` commands are thin handler
 * objects (no shared state, no shared base behaviour) implemented under
 * sibling WPs that share this lane. The reservation contract is data — a
 * fixed list of verb→FQCN bindings — not an OO inheritance chain. The
 * allowlist makes that explicit and keeps `ConfigCommand` decoupled from the
 * other command files' constructors.
 *
 * @api
 */
abstract class ConfigCommand
{
    /**
     * The reserved sub-verb names, without the `config:` prefix.
     *
     * Iteration-order matches `contracts/cli-namespace.md` §"Reserved verb
     * namespace" for parity with documentation.
     *
     * @var list<string>
     */
    public const RESERVED_VERBS = [
        'export',
        'import',
        'diff',
        'status',
        'validate',
        'reset',
    ];

    /**
     * The fully-qualified reserved verbs (`config:*`) framework-side.
     *
     * @var list<string>
     */
    public const RESERVED_FULL_VERBS = [
        'config:export',
        'config:import',
        'config:diff',
        'config:status',
        'config:validate',
        'config:reset',
    ];

    /**
     * Allowlist of FQCNs that legitimately register reserved verbs.
     *
     * These are the framework's own command classes. Any other class
     * registering a reserved verb triggers
     * {@see ConfigCommandCollisionException} at kernel boot.
     *
     * Allowed FQCNs map 1:1 to {@see RESERVED_FULL_VERBS}:
     *
     *  - `config:export`   → ConfigExportCommand
     *  - `config:import`   → ConfigImportCommand
     *  - `config:diff`     → ConfigDiffCommand
     *  - `config:status`   → ConfigStatusCommand
     *  - `config:validate` → ConfigValidateCommand
     *  - `config:reset`    → ConfigResetCommand
     *
     * @var list<class-string>
     */
    public const RESERVED_FQCNS = [
        ConfigExportCommand::class,
        ConfigImportCommand::class,
        ConfigDiffCommand::class,
        ConfigStatusCommand::class,
        ConfigValidateCommand::class,
        ConfigResetCommand::class,
    ];

    /**
     * Return true when `$verb` is in the reserved set.
     *
     * Accepts both the bare sub-verb (`'export'`) and the fully-qualified
     * form (`'config:export'`). Anything else returns false.
     */
    public static function isReservedVerb(string $verb): bool
    {
        if (in_array($verb, self::RESERVED_FULL_VERBS, true)) {
            return true;
        }

        return in_array($verb, self::RESERVED_VERBS, true);
    }

    /**
     * Return true when `$fqcn` is permitted to register reserved verbs.
     *
     * A subclass of {@see ConfigCommand} is also permitted — this lets
     * downstream apps build typed wrappers around the framework's reserved
     * verbs without forcing them through the allowlist. (Subclasses of the
     * framework's six concrete handlers also qualify because each handler
     * descends from {@see ConfigCommand} transitively via the allowlist
     * contract.)
     *
     * @param class-string|string $fqcn
     */
    public static function isReservedFqcn(string $fqcn): bool
    {
        if (in_array($fqcn, self::RESERVED_FQCNS, true)) {
            return true;
        }

        // Subclass of any allowlisted FQCN — supports app-side wrappers
        // while preserving the data-driven allowlist as the canonical list.
        foreach (self::RESERVED_FQCNS as $allowed) {
            if (is_subclass_of($fqcn, $allowed)) {
                return true;
            }
        }

        // ConfigCommand itself (this abstract class) is acceptable as a base
        // class for legitimate config command implementations. We do NOT
        // check `is_subclass_of($fqcn, self::class)` here because that would
        // let arbitrary subclasses register reserved verbs — the allowlist
        // is the authoritative gate.

        return false;
    }

    /**
     * Assert no collision between `$verb` and the reserved verb set.
     *
     * Called by the Symfony Console application factory for every command
     * registered at boot. Behaviour:
     *
     *  - `$verb` not reserved (e.g. `cache:clear`, `config:audit-export`,
     *    `import:run`) → no-op (FR-049).
     *  - `$verb` reserved AND `$offendingFqcn` in {@see RESERVED_FQCNS} (or
     *    a subclass thereof) → no-op (FR-047).
     *  - `$verb` reserved AND `$offendingFqcn` NOT in the allowlist → throw
     *    {@see ConfigCommandCollisionException} (FR-048).
     *
     * @param string              $verb           The command verb being registered
     *                                            (e.g. `'config:export'`).
     * @param class-string|string $offendingFqcn The FQCN of the command's
     *                                            handler class.
     *
     * @throws ConfigCommandCollisionException When the verb is reserved and
     *                                          the FQCN is not allowlisted.
     */
    public static function assertNoCollision(string $verb, string $offendingFqcn): void
    {
        if (!self::isReservedVerb($verb)) {
            // Non-reserved verbs (including `config:<custom>` per FR-049)
            // pass through unchallenged.
            return;
        }

        if (self::isReservedFqcn($offendingFqcn)) {
            // Framework-owned class registering its own reserved verb.
            return;
        }

        throw ConfigCommandCollisionException::forVerb($verb, $offendingFqcn);
    }
}
