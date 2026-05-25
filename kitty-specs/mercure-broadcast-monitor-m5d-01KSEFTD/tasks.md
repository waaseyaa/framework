# Work Packages: mercure-broadcast-monitor-m5d-01KSEFTD

**Mission:** M5D — Mercure broadcast monitor (live SSE debugger, channel inspector, subscriber list). Tracks audit row C-L0-04 under umbrella issue #1415.
**Pattern reference:** M5A (`ai-observability-dashboard-01KSE9BX`) — CodifiedContext cross-layer.

## Work Package WP01: Monitor read contracts, foundation adapters, BroadcastRouter extension, binding, routes, kernel-boot test

**Dependencies**: None
**Requirement Refs**: FR-001, FR-002, FR-003, FR-004, FR-005, FR-006, FR-007, FR-008, NFR-001, NFR-002, NFR-003, NFR-004, C-001, C-002, C-003, C-004, C-005
**Owned Files**: packages/api/src/MercureMonitor/ChannelInspectorInterface.php, packages/api/src/MercureMonitor/EventStreamReadModelInterface.php, packages/api/src/MercureMonitor/SubscriberObserverInterface.php, packages/api/src/MercureMonitor/ChannelInspectorRow.php, packages/api/src/MercureMonitor/EventStreamFilter.php, packages/api/src/MercureMonitor/BroadcastEventRow.php, packages/api/src/MercureMonitor/SubscriberRow.php, packages/api/src/Controller/MercureMonitorController.php, packages/api/src/Http/Router/MercureMonitorApiRouter.php, packages/api/src/ApiServiceProvider.php, packages/api/tests/Unit/Controller/MercureMonitorControllerTest.php, packages/api/tests/Unit/Http/Router/MercureMonitorApiRouterTest.php, packages/foundation/src/Http/Inbound/ChannelInspector.php, packages/foundation/src/Http/Inbound/EventStreamReadModel.php, packages/foundation/src/Http/Inbound/SubscriberObserver.php, packages/foundation/src/Http/Router/BroadcastRouter.php, packages/foundation/src/MercureMonitorServiceProvider.php, packages/foundation/tests/Unit/Http/Inbound/ChannelInspectorTest.php, packages/foundation/tests/Unit/Http/Inbound/EventStreamReadModelTest.php, packages/foundation/tests/Unit/Http/Inbound/SubscriberObserverTest.php, packages/foundation/src/Kernel/BuiltinRouteRegistrar.php, tests/Integration/PhaseMercureMonitor/MercureMonitorEndpointTest.php
**Subtasks**: T001, T002, T003, T004
**Prompt**: `tasks/WP01-monitor-backend.md`

## Work Package WP02: Monitor admin SPA — single-page live monitor + composable, components, nav, i18n, docs

**Dependencies**: WP01
**Requirement Refs**: FR-009, FR-010, NFR-002, C-001
**Owned Files**: packages/admin/app/composables/useMercureMonitor.ts, packages/admin/app/pages/mercure/monitor.vue, packages/admin/app/components/mercure/ChannelInspectorPanel.vue, packages/admin/app/components/mercure/EventStreamPanel.vue, packages/admin/app/components/mercure/SubscriberListPanel.vue, packages/admin/app/components/mercure/MercureFilterBar.vue, packages/admin/app/i18n/en.json, packages/admin/tests/unit/composables/useMercureMonitor.test.ts, packages/admin/e2e/mercure-monitor.spec.ts, docs/specs/broadcasting.md, docs/specs/admin-spa.md, CHANGELOG.md
**Subtasks**: T005, T006, T007
**Prompt**: `tasks/WP02-monitor-frontend.md`
