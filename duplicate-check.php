<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

header('Content-Type: application/json');

try {
    $phoneDisplay = trim((string) ($_GET['phone_display'] ?? ''));
    $adUrl = contact_trace_normalize_ad_url((string) ($_GET['ad_url'] ?? ''));
    $phoneNormalized = contact_trace_normalize_whatsapp_phone($phoneDisplay);

    if ($phoneNormalized === '' && $adUrl === '') {
        echo json_encode([
            'ok' => true,
            'duplicate' => false,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = contact_trace_get_pdo();
    $duplicateLead = contact_trace_find_duplicate_lead($pdo, $phoneNormalized, $adUrl);

    if ($duplicateLead === null) {
        echo json_encode([
            'ok' => true,
            'duplicate' => false,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'duplicate' => true,
        'message' => contact_trace_duplicate_lead_message($duplicateLead, $phoneNormalized, $adUrl),
        'lead' => [
            'id' => (int) ($duplicateLead['id'] ?? 0),
            'owner_name' => (string) ($duplicateLead['owner_name'] ?? ''),
            'phone_display' => (string) ($duplicateLead['phone_display'] ?? ''),
            'ad_url' => (string) ($duplicateLead['ad_url'] ?? ''),
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}