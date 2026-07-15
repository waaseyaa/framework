<?php

declare(strict_types=1);

/**
 * Reviewed mutable process-static state reachable from HTTP paths.
 *
 * Every entry must state its lifetime and why it cannot retain request/account
 * data. New entries fail AH005 until a rationale is reviewed here.
 *
 * @return array<string, string>
 */
return [
    'packages/foundation/src/Security/ApplicationSecret.php::$secrets'
        => 'Process-lifetime WeakMap custody keyed by kernel-owned ApplicationSecret objects; entries disappear with their kernel, contain no request/account data, and keep master bytes out of object debug and serialization surfaces.',
    'packages/foundation/src/Security/SensitiveKey.php::$keys'
        => 'Process-lifetime WeakMap custody keyed by derived-key holder objects; entries disappear with their holder, contain no request/account data, and keep derived bytes out of object debug and serialization surfaces.',
    'packages/graphql/src/Schema/SchemaFactory.php::$schemaCache'
        => 'Process-lifetime structural GraphQL schemas only; resolvers obtain request/account collaborators from GraphQlExecutionContext.',
    'packages/inertia/src/Inertia.php::$shared'
        => 'Request-scoped facade state cleared in InertiaMiddleware finally and after full-page render.',
    'packages/inertia/src/Inertia.php::$version'
        => 'Process-lifetime immutable asset version configured at boot; contains no request/account data.',
    'packages/inertia/src/Inertia.php::$renderer'
        => 'Process-lifetime stateless root renderer configured at boot; contains no request/account data.',
    'packages/ssr/src/Flash/Flash.php::$service'
        => 'Process-lifetime stateless facade service; all message state lives in the active PHP session, not the object.',
    'packages/ssr/src/Http/AppController/AppControllerMethodInvoker.php::$specCache'
        => 'Process-lifetime binding specifications keyed by class, method, route and fingerprint; resolved argument values are never cached.',
    'packages/ssr/src/SsrServiceProvider.php::$twigEnvironment'
        => 'Process-lifetime template environment configured at boot; request variables are supplied per render.',
    'packages/ssr/src/SsrServiceProvider.php::$formatterRegistry'
        => 'Process-lifetime formatter catalogue configured at boot; contains no request/account data.',
    'packages/ssr/src/ThemeServiceProvider.php::$twigEnvironment'
        => 'Process-lifetime template environment configured at boot; request variables are supplied per render.',
];
