# Controller ergonomics implementation plan (#2291)

## Outcome

Give Drupal- and Laravel-familiar application developers a first-class
redirect/named-route experience without requiring inheritance or exposing the
service container from controllers.

## Work

1. Pin `Redirector` and the optional `Controller` base red-first at the routing
   package boundary, including unsafe-target rejection and named-route error
   propagation.
2. Thread one request-scoped `Redirector` from `HttpKernel` through SSR
   app-controller constructor and action-parameter resolution.
3. Prove both styles through the real app-controller dispatch path: an
   extending controller and a plain final controller using composition.
4. Document the recommended choice and migration examples, update the
   changelog and public surface, then run architecture, static, split-suite,
   and complete verification gates.

## Boundaries

- No global functions or mandatory inheritance.
- No controller container/service locator.
- No external redirects.
- No release or downstream deployment.
