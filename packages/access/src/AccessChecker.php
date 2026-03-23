<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Gate\GateInterface;

/**
 * Checks route-level access for an account.
 *
 * Routes can declare access requirements via options:
 *   - '_public' => true — always allow access (no authentication required)
 *   - '_authenticated' => true — require a non-anonymous identity (returns 401 if not authenticated)
 *   - '_session' => true|['key1','key2'] — require active session, optionally with specific keys
 *   - '_permission' => 'administer site' — require a specific permission
 *   - '_role' => 'administrator' — require a specific role (or comma-separated list)
 *   - '_gate' => ['ability' => 'config.export', 'subject' => null] — require a gate ability
 *
 * '_authenticated' short-circuits before other checks: if present and the account
 * is anonymous, access is denied immediately without evaluating further options.
 *
 * Multiple requirements are combined with AND logic (all must pass).
 * If no access requirements are present, returns AccessResult::neutral().
 */
final class AccessChecker
{
    public function __construct(
        private readonly ?GateInterface $gate = null,
    ) {}

    /**
     * Check access for a matched route.
     *
     * @param Route $route The matched route.
     * @param AccountInterface $account The current user account.
     * @return AccessResult The access result.
     */
    public function check(Route $route, AccountInterface $account): AccessResult
    {
        $hasRequirement = false;
        $result = AccessResult::allowed();

        // Check _public option (blanket allow).
        $public = $route->getOption('_public');
        if ($public === true) {
            $hasRequirement = true;
            // Remains allowed — no change needed.
        }

        // Check _authenticated option (requires a non-anonymous identity).
        // Short-circuits: no point evaluating permissions for an anonymous user.
        $authenticated = $route->getOption('_authenticated');
        if ($authenticated === true) {
            $hasRequirement = true;
            if (!$account->isAuthenticated()) {
                return AccessResult::unauthenticated('Authentication is required to access this resource.');
            }
        }

        // Check _session option (requires active session, optionally with specific keys).
        $sessionOption = $route->getOption('_session');
        if ($sessionOption !== null) {
            $hasRequirement = true;
            if (session_status() !== \PHP_SESSION_ACTIVE) {
                $result = $result->andIf(AccessResult::forbidden('An active session is required to access this resource.'));
            } elseif (is_array($sessionOption)) {
                foreach ($sessionOption as $key) {
                    if (!isset($_SESSION[$key])) {
                        $result = $result->andIf(AccessResult::forbidden(sprintf('Session key "%s" is required.', $key)));
                        break;
                    }
                }
            }
        }

        // Check _permission option.
        $permission = $route->getOption('_permission');
        if ($permission !== null) {
            $hasRequirement = true;
            $permResult = $account->hasPermission($permission)
                ? AccessResult::allowed()
                : AccessResult::forbidden("The '{$permission}' permission is required.");
            $result = $result->andIf($permResult);
        }

        // Check _role option (comma-separated list of roles).
        $role = $route->getOption('_role');
        if ($role !== null) {
            $hasRequirement = true;
            $requiredRoles = array_map('trim', explode(',', $role));
            $accountRoles = $account->getRoles();
            $hasRole = !empty(array_intersect($requiredRoles, $accountRoles));
            $roleResult = $hasRole
                ? AccessResult::allowed()
                : AccessResult::forbidden('The required role is missing.');
            $result = $result->andIf($roleResult);
        }

        // Check _gate option (gate ability check).
        $gateOptions = $route->getOption('_gate');
        if ($gateOptions !== null && is_array($gateOptions)) {
            $hasRequirement = true;
            $result = $result->andIf($this->checkGate($gateOptions, $account));
        }

        if (!$hasRequirement) {
            return AccessResult::neutral('No access requirements specified on route.');
        }

        return $result;
    }

    /**
     * Check a gate ability on the route.
     *
     * @param array{ability: string, subject?: mixed} $gateOptions
     */
    private function checkGate(array $gateOptions, AccountInterface $account): AccessResult
    {
        if ($this->gate === null) {
            return AccessResult::forbidden('Gate check required but no Gate implementation is available.');
        }

        $ability = $gateOptions['ability'] ?? '';
        if ($ability === '') {
            return AccessResult::forbidden('Gate ability not specified.');
        }

        $subject = $gateOptions['subject'] ?? null;

        return $this->gate->allows($ability, $subject, $account)
            ? AccessResult::allowed()
            : AccessResult::forbidden("Gate denied ability '{$ability}'.");
    }

    /**
     * Apply GateAttribute metadata to a route's options.
     *
     * Call this during route compilation to transfer #[GateAttribute]
     * metadata into the route's '_gate' option for runtime checking.
     *
     * @param Route $route The route to enhance.
     * @param string $ability The gate ability from the attribute.
     * @param mixed $subject Optional subject for the gate check.
     */
    public static function applyGateToRoute(Route $route, string $ability, mixed $subject = null): void
    {
        $route->setOption('_gate', [
            'ability' => $ability,
            'subject' => $subject,
        ]);
    }
}
