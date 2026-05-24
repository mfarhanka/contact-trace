<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$configuredToken = contact_trace_env('WHATSAPP_BRIDGE_TOKEN');

if ($configuredToken === '') {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'WHATSAPP_BRIDGE_TOKEN is missing.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$requestToken = trim((string) ($_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''));

if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Bridge token is invalid.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON payload.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = contact_trace_get_pdo();
    $result = contact_trace_process_whatsapp_inbound_message($pdo, $payload);
    $lead = is_array($result['lead'] ?? null) ? $result['lead'] : [];
    $alert = is_array($result['alert'] ?? null) ? $result['alert'] : [];

    echo json_encode([
        'ok' => true,
        'result' => [
            'leadId' => (int) ($lead['id'] ?? 0),
            'status' => (string) ($lead['status'] ?? ''),
            'alertSent' => ($alert['sent'] ?? false) === true,
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}