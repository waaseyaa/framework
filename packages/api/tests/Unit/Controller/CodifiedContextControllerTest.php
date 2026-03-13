<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use Waaseyaa\Api\Controller\CodifiedContextController;
use Waaseyaa\Telescope\CodifiedContext\Storage\SqliteCodifiedContextStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodifiedContextController::class)]
final class CodifiedContextControllerTest extends TestCase
{
    #[Test]
    public function null_store_list_sessions_returns_empty(): void
    {
        $controller = new CodifiedContextController(null);
        $result = $controller->listSessions();

        $this->assertSame(['data' => []], $result);
    }

    #[Test]
    public function null_store_get_session_returns_null(): void
    {
        $controller = new CodifiedContextController(null);
        $result = $controller->getSession('sess-123');

        $this->assertSame(['data' => null], $result);
    }

    #[Test]
    public function null_store_get_session_events_returns_empty(): void
    {
        $controller = new CodifiedContextController(null);
        $result = $controller->getSessionEvents('sess-123');

        $this->assertSame(['data' => []], $result);
    }

    #[Test]
    public function null_store_get_session_validation_returns_null(): void
    {
        $controller = new CodifiedContextController(null);
        $result = $controller->getSessionValidation('sess-123');

        $this->assertSame(['data' => null], $result);
    }

    #[Test]
    public function list_sessions_groups_by_session_id(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', [
            'session_id' => 'sess-A',
            'event_type' => 'cc_session',
            'phase' => 'start',
        ]);
        $store->store('cc_session', [
            'session_id' => 'sess-B',
            'event_type' => 'cc_session',
            'phase' => 'start',
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->listSessions();

        $this->assertCount(2, $result['data']);
        $sessionIds = array_column($result['data'], 'session_id');
        $this->assertContains('sess-A', $sessionIds);
        $this->assertContains('sess-B', $sessionIds);
    }

    #[Test]
    public function get_session_returns_null_for_unknown_session(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $controller = new CodifiedContextController($store);

        $result = $controller->getSession('nonexistent-session');

        $this->assertSame(['data' => null], $result);
    }

    #[Test]
    public function get_session_returns_data_for_known_session(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', [
            'session_id' => 'sess-X',
            'event_type' => 'cc_session',
            'phase' => 'start',
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSession('sess-X');

        $this->assertNotNull($result['data']);
        $this->assertSame('sess-X', $result['data']['session_id']);
    }

    #[Test]
    public function get_session_events_filters_to_cc_event_type(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', [
            'session_id' => 'sess-Y',
            'event_type' => 'cc_session',
            'phase' => 'start',
        ]);
        $store->store('cc_event', [
            'session_id' => 'sess-Y',
            'event_type' => 'cc_event',
            'action' => 'load_spec',
        ]);
        $store->store('cc_validation', [
            'session_id' => 'sess-Y',
            'event_type' => 'cc_validation',
            'drift_score' => 0.3,
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSessionEvents('sess-Y');

        $this->assertCount(1, $result['data']);
        $this->assertSame('cc_event', $result['data'][0]['type']);
    }

    #[Test]
    public function get_session_validation_returns_null_when_no_validation(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', [
            'session_id' => 'sess-Z',
            'event_type' => 'cc_session',
            'phase' => 'start',
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSessionValidation('sess-Z');

        $this->assertSame(['data' => null], $result);
    }

    #[Test]
    public function get_session_validation_returns_latest_report(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_validation', [
            'session_id' => 'sess-V',
            'event_type' => 'cc_validation',
            'drift_score' => 0.85,
            'issues' => ['stale spec detected'],
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSessionValidation('sess-V');

        $this->assertNotNull($result['data']);
        $this->assertSame('sess-V', $result['data']['session_id']);
        $this->assertArrayHasKey('report', $result['data']);
        $this->assertSame(0.85, $result['data']['report']['drift_score']);
    }
}
