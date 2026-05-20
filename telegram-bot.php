<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

$botToken = contact_trace_env('TELEGRAM_BOT_TOKEN');
$allowedChatIds = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', contact_trace_env('TELEGRAM_ALLOWED_CHAT_IDS'))
)));

if ($botToken === '') {
    http_response_code(500);
    echo 'Missing TELEGRAM_BOT_TOKEN';
    exit;
}

$rawBody = file_get_contents('php://input');
$update = json_decode($rawBody !== false ? $rawBody : '', true);

if (!is_array($update)) {
    http_response_code(400);
    echo 'Invalid update payload';
    exit;
}

$message = $update['message'] ?? $update['edited_message'] ?? null;

if (!is_array($message)) {
    echo 'ok';
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$text = trim((string) ($message['text'] ?? ''));

if (!is_int($chatId) && !is_string($chatId)) {
    echo 'ok';
    exit;
}

$chatIdString = (string) $chatId;

if ($allowedChatIds !== [] && !in_array($chatIdString, $allowedChatIds, true)) {
    contact_trace_send_telegram_message($botToken, $chatIdString, 'Chat not allowed for this bot.');
    echo 'ok';
    exit;
}

if ($text === '') {
    contact_trace_send_telegram_message($botToken, $chatIdString, contact_trace_help_text());
    echo 'ok';
    exit;
}

try {
    $pdo = contact_trace_get_pdo();
    $reply = contact_trace_handle_telegram_command($pdo, $text);
} catch (Throwable $exception) {
    $reply = 'Error: ' . $exception->getMessage();
}

contact_trace_send_telegram_message($botToken, $chatIdString, $reply);

echo 'ok';

function contact_trace_handle_telegram_command(PDO $pdo, string $text): string
{
    if (preg_match('~^/(?:start|help)(?:@\w+)?$~i', $text) === 1) {
        return contact_trace_help_text();
    }

    if (preg_match('~^/search(?:@\w+)?(?:\s+(.+))?$~is', $text, $matches) === 1) {
        $searchTerm = trim((string) ($matches[1] ?? ''));

        if ($searchTerm === '') {
            return "Usage:\n/search keyword";
        }

        $leads = contact_trace_search_leads($pdo, $searchTerm, 5);

        if ($leads === []) {
            return 'No leads found for: ' . $searchTerm;
        }

        $lines = ['Results for: ' . $searchTerm];

        foreach ($leads as $lead) {
            $lines[] = contact_trace_format_telegram_lead($lead);
        }

        return implode("\n\n", $lines);
    }

    if (preg_match('~^/delete(?:@\w+)?(?:\s+(\d+))?$~i', $text, $matches) === 1) {
        $leadId = (int) ($matches[1] ?? 0);

        if ($leadId <= 0) {
            return "Usage:\n/delete 12\n\nUse /search first to find the lead ID.";
        }

        $lead = contact_trace_find_lead($pdo, $leadId);

        if ($lead === null) {
            return 'Lead not found for ID #' . $leadId;
        }

        if (!contact_trace_delete_lead($pdo, $leadId)) {
            return 'Lead could not be deleted for ID #' . $leadId;
        }

        $name = $lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display'];

        return 'Deleted lead #' . $leadId . ' (' . $name . ')';
    }

    if (preg_match('~^/add(?:@\w+)?(?:\s+(.+))?$~is', $text, $matches) === 1) {
        $payload = trim((string) ($matches[1] ?? ''));

        if ($payload === '') {
            return contact_trace_add_usage_text();
        }

        [$input, $error] = contact_trace_parse_add_command($payload);

        if ($error !== null) {
            return $error . "\n\n" . contact_trace_add_usage_text();
        }

        $leadId = contact_trace_add_lead($pdo, $input);

        return 'Lead saved with ID #' . $leadId;
    }

    return contact_trace_help_text();
}

function contact_trace_parse_add_command(string $payload): array
{
    $parts = array_map(
        static fn (string $value): string => trim($value),
        explode('|', $payload)
    );

    if (count($parts) < 2) {
        return [null, 'Add needs at least phone and ad URL.'];
    }

    $parts = array_pad($parts, 8, '');

    $input = [
        'phone_display' => $parts[0],
        'ad_url' => $parts[1],
        'owner_name' => contact_trace_optional_part($parts[2]),
        'telegram_handle' => contact_trace_optional_part($parts[3]),
        'service_offer' => contact_trace_optional_part($parts[4]),
        'latest_reply' => contact_trace_optional_part($parts[5]),
        'notes' => contact_trace_optional_part($parts[6]),
        'status' => contact_trace_optional_part($parts[7]) !== '' ? contact_trace_optional_part($parts[7]) : 'contacted',
    ];

    return [$input, null];
}

function contact_trace_optional_part(string $value): string
{
    return in_array($value, ['', '-'], true) ? '' : $value;
}

function contact_trace_format_telegram_lead(array $lead): string
{
    $title = '#' . (int) $lead['id'] . ' ' . ($lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display']);
    $lines = [$title, 'Phone: ' . $lead['phone_display']];

    if ($lead['telegram_handle'] !== '') {
        $lines[] = 'Telegram: ' . $lead['telegram_handle'];
    }

    if ($lead['service_offer'] !== '') {
        $lines[] = 'Service: ' . contact_trace_truncate_text($lead['service_offer']);
    }

    if ($lead['latest_reply'] !== '') {
        $lines[] = 'Reply: ' . contact_trace_truncate_text($lead['latest_reply']);
    }

    if ($lead['notes'] !== '') {
        $lines[] = 'Notes: ' . contact_trace_truncate_text($lead['notes']);
    }

    $lines[] = 'Status: ' . $lead['status'];
    $lines[] = 'Ad: ' . contact_trace_truncate_text($lead['ad_url'], 120);

    return implode("\n", $lines);
}

function contact_trace_truncate_text(string $value, int $maxLength = 80): string
{
    $cleanValue = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if (strlen($cleanValue) <= $maxLength) {
        return $cleanValue;
    }

    return substr($cleanValue, 0, $maxLength - 3) . '...';
}

function contact_trace_help_text(): string
{
    return implode("\n", [
        'Commands:',
        '/search keyword',
        '/delete 12',
        '/add phone | ad_url | owner | telegram | service | latest reply | notes | status',
        '',
        'Use - for empty optional fields.',
    ]);
}

function contact_trace_add_usage_text(): string
{
    return implode("\n", [
        'Usage:',
        '/add 012-3456789 | https://example.com/ad | Ali | @ali_owner | Aircond service | Interested | Call again Friday | contacted',
        '',
        'Only phone and ad URL are required.',
        'Optional order: owner | telegram | service | latest reply | notes | status',
        'Use - for any empty optional value.',
    ]);
}
