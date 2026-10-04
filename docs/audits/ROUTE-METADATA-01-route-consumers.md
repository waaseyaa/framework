# ROUTE-METADATA-01 bounded CLI/Bimaaji consumer repair

State: source candidate; independent review and default preflight pending.
Base: 80aa9ef882f6e269c3d2e458ba7839c4d19422b7. Owner: root.

Finding RM-CONSUMER-01 is required for #3121/#3122. The original kernel bus
exposes neither RouteCollection nor WaaseyaaRouter, so strict installed graph
export fails. CLI route:list separately reconstructed only builtin routes.
The repair consumes completed RouteSnapshot, never legacy execution hooks.
An initially failing canonical provider test recorded a legacy collection read.

Scoped production roster: Foundation AbstractKernel owns the lazy kernel
callback; ProviderRegistry owns handoff; ProviderRegistryKernelServices owns
reserved access and refusal propagation. CLI MiscBServiceProvider owns command
composition. BimaajiServiceProvider owns default section composition;
JsonApiIntrospectionProvider, PublicSurfaceProvider and RoutingIntrospectionProvider
own existing scalar projections with late read accessors. ApplicationGraphGenerator
owns final authority validation after section collection, and GraphDumpHandler
owns raw JSON command transport. No extra production token or dependency is added.

Direct constructors retain collection compatibility, with optional final custody
callbacks for kernel-composed generators/handlers. Kernel refusal is fatal to
complete graph generation even in soft section mode; ordinary independent
section failures retain prior behavior. Bare caller compatibility is explicit
and does not qualify a kernel application. JSON:API defaults preserve identity.

Controls exercise repeated exports, malformed/incomplete authority refusal,
legacy participation without hook replay, literal console markup preservation,
real ConsoleKernel command composition and HTTP dispatch parity. Root qualifies
focused affected suites, production PHPStan, canonical scanner rosters and default
preflight. An immutable reviewer owns independent review. Framework remains PHP.

This is a consumer-driven bounded audit, not whole-package convergence. FETDER
producer migration, its real installed strict graph and HTTP/CLI parity, sibling
distribution profiles, full package ledgers and hosted Linux proof remain owned
by #145/#3122/#3124/#3117. Do not close the release blocker on synthetic proof.

The command rechecks route authority after JSON serialization and before writing.
Third-party JsonSerializable values may run code or catch mutation refusal;
failed final custody returns exit1 with no graph bytes in either strict or ordinary
mode. Serialization failures also return a command error without partial JSON.
