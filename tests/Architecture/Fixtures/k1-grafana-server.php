<?php

declare(strict_types=1);

$configPath = getenv('K1_GRAFANA_FIXTURE_CONFIG');
$logPath = getenv('K1_GRAFANA_FIXTURE_LOG');
if (!is_string($configPath) || !is_string($logPath)) {
    http_response_code(500);
    exit;
}

$config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = (string) file_get_contents('php://input');
file_put_contents($logPath, json_encode([
    'method' => $method,
    'path' => $path,
    'authorization_matches' => hash_equals((string) ($config['authorization'] ?? ''), $authorization),
    'body' => $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if (!hash_equals((string) ($config['authorization'] ?? ''), $authorization)) {
    http_response_code(401);
    echo '{"message":"unauthorized"}';
    exit;
}

if ($method === 'GET' && $path === '/api/dashboards/uid/waaseyaa-k1-flow') {
    http_response_code((int) ($config['dashboard_status'] ?? 200));
    if (is_string($config['dashboard_location'] ?? null)) {
        header('Location: ' . $config['dashboard_location']);
    }
    echo json_encode(['dashboard' => $config['dashboard']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'POST' && $path === '/api/ds/query') {
    http_response_code((int) ($config['query_status'] ?? 200));
    if (is_string($config['query_location'] ?? null)) {
        header('Location: ' . $config['query_location']);
    }
    $fields = [];
    $values = [];
    $rowCount = (int) ($config['row_count'] ?? 1);
    foreach ($config['row'] as $name => $value) {
        $fields[] = ['name' => $name, 'type' => is_int($value) ? 'number' : 'string'];
        $values[] = array_fill(0, $rowCount, $value);
    }
    echo json_encode([
        'results' => [
            'A' => [
                'status' => (int) ($config['query_status'] ?? 200),
                'frames' => [[
                    'schema' => ['fields' => $fields],
                    'data' => ['values' => $values],
                ]],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

http_response_code(404);
echo '{"message":"not found"}';
