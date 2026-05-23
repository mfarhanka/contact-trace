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
    $reply = contact_trace_handle_telegram_command($pdo, $chatIdString, $text);
} catch (Throwable $exception) {
    $reply = 'Error: ' . $exception->getMessage();
}

contact_trace_send_telegram_message($botToken, $chatIdString, $reply);

echo 'ok';

function contact_trace_find_telegram_add_draft(PDO $pdo, string $chatId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM telegram_add_drafts WHERE chat_id = :chat_id LIMIT 1');
    $statement->execute([':chat_id' => $chatId]);
    $draft = $statement->fetch();

    if (!is_array($draft)) {
        return null;
    }

    $payload = json_decode((string) ($draft['payload_json'] ?? '{}'), true);

    return [
        'chat_id' => (string) ($draft['chat_id'] ?? ''),
        'payload' => is_array($payload) ? $payload : [],
        'current_field' => (string) ($draft['current_field'] ?? ''),
        'updated_at' => (string) ($draft['updated_at'] ?? ''),
    ];
}

function contact_trace_save_telegram_add_draft(PDO $pdo, string $chatId, array $payload, string $currentField): void
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $updatedAt = date('c');

    if ($payloadJson === false) {
        throw new RuntimeException('Unable to save Telegram draft.');
    }

    $statement = $pdo->prepare(
        'UPDATE telegram_add_drafts
         SET payload_json = :payload_json,
             current_field = :current_field,
             updated_at = :updated_at
         WHERE chat_id = :chat_id'
    );

    $statement->execute([
        ':chat_id' => $chatId,
        ':payload_json' => $payloadJson,
        ':current_field' => $currentField,
        ':updated_at' => $updatedAt,
    ]);

    if ($statement->rowCount() > 0) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO telegram_add_drafts (chat_id, payload_json, current_field, updated_at)
         VALUES (:chat_id, :payload_json, :current_field, :updated_at)'
    );

    $statement->execute([
        ':chat_id' => $chatId,
        ':payload_json' => $payloadJson,
        ':current_field' => $currentField,
        ':updated_at' => $updatedAt,
    ]);
}

function contact_trace_delete_telegram_add_draft(PDO $pdo, string $chatId): void
{
    $statement = $pdo->prepare('DELETE FROM telegram_add_drafts WHERE chat_id = :chat_id');
    $statement->execute([':chat_id' => $chatId]);
}

function contact_trace_handle_telegram_command(PDO $pdo, string $chatId, string $text): string
{
    if (preg_match('~^/(?:start|help)(?:@\w+)?$~i', $text) === 1) {
        return contact_trace_help_text();
    }

    if (preg_match('~^/cancel(?:@\w+)?$~i', $text) === 1) {
        if (contact_trace_find_telegram_add_draft($pdo, $chatId) === null) {
            return 'Nothing to cancel.';
        }

        contact_trace_delete_telegram_add_draft($pdo, $chatId);

        return 'Add cancelled.';
    }

    if (preg_match('~^/add(?:@\w+)?(?:\s+(.+))?$~is', $text, $matches) === 1) {
        $payload = trim((string) ($matches[1] ?? ''));

        if ($payload !== '') {
            contact_trace_delete_telegram_add_draft($pdo, $chatId);

            [$input, $error] = contact_trace_parse_add_command($payload);

            if ($error !== null) {
                return $error . "\n\n" . contact_trace_add_usage_text();
            }

            if (($input['phone_display'] ?? '') === '' && ($input['ad_url'] ?? '') !== '') {
                $draftPayload = contact_trace_telegram_fill_from_ad_url($input);

                if (($draftPayload['phone_display'] ?? '') === '') {
                    contact_trace_save_telegram_add_draft($pdo, $chatId, $draftPayload, 'phone_display');

                    return implode("\n\n", [
                        'I saved the ad URL first, but could not read the contact number automatically.',
                        contact_trace_telegram_add_prompt('phone_display'),
                    ]);
                }

                $input = $draftPayload;
            }

            return contact_trace_telegram_save_lead($pdo, $chatId, $input);
        }

        $firstField = contact_trace_telegram_add_first_field();
        contact_trace_save_telegram_add_draft($pdo, $chatId, [], $firstField);

        return implode("\n\n", [
            'Let\'s add a lead step by step.',
            'Reply with /skip for optional fields or /cancel to stop.',
            contact_trace_telegram_add_prompt($firstField),
        ]);
    }

    $draft = contact_trace_find_telegram_add_draft($pdo, $chatId);

    if ($draft !== null) {
        if (str_starts_with($text, '/') && preg_match('~^/skip(?:@\w+)?$~i', $text) !== 1) {
            return 'You are in the middle of /add. Reply to the current question, send /skip for optional fields, or /cancel.';
        }

        return contact_trace_continue_telegram_add_draft($pdo, $chatId, $draft, $text);
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

    return contact_trace_help_text();
}

function contact_trace_continue_telegram_add_draft(PDO $pdo, string $chatId, array $draft, string $text): string
{
    $fieldDefinitions = contact_trace_telegram_add_field_definitions();
    $currentField = (string) ($draft['current_field'] ?? '');
    $payload = is_array($draft['payload'] ?? null) ? $draft['payload'] : [];

    if (!isset($fieldDefinitions[$currentField])) {
        if (($payload['phone_display'] ?? '') !== '' && ($payload['ad_url'] ?? '') !== '') {
            contact_trace_delete_telegram_add_draft($pdo, $chatId);
            return contact_trace_telegram_save_lead($pdo, $chatId, $payload);
        }

        contact_trace_delete_telegram_add_draft($pdo, $chatId);

        return 'Add state expired. Send /add to start again.';
    }

    [$value, $error] = contact_trace_telegram_add_normalize_answer($currentField, $text, $fieldDefinitions[$currentField]);

    if ($error !== null) {
        return $error . "\n\n" . contact_trace_telegram_add_prompt($currentField);
    }

    if ($currentField === 'ad_url') {
        $payload['ad_url'] = (string) $value;
        $payload = contact_trace_telegram_fill_from_ad_url($payload);

        if (($payload['phone_display'] ?? '') !== '') {
            contact_trace_delete_telegram_add_draft($pdo, $chatId);
            return contact_trace_telegram_save_lead($pdo, $chatId, $payload);
        }

        contact_trace_save_telegram_add_draft($pdo, $chatId, $payload, 'phone_display');

        return implode("\n\n", [
            'I saved the ad URL first, but could not read the contact number automatically.',
            contact_trace_telegram_add_prompt('phone_display'),
        ]);
    }

    if (is_array($value) && (($value['__save_now'] ?? false) === true)) {
        unset($value['__save_now']);
        contact_trace_delete_telegram_add_draft($pdo, $chatId);
        return contact_trace_telegram_save_lead($pdo, $chatId, $value);
    }

    $payload[$currentField] = $value;

    if ($currentField === 'phone_display' && ($payload['owner_name'] ?? '') !== '') {
        contact_trace_delete_telegram_add_draft($pdo, $chatId);
        return contact_trace_telegram_save_lead($pdo, $chatId, $payload);
    }

    $nextField = contact_trace_telegram_add_next_field($currentField);

    if ($nextField !== null) {
        contact_trace_save_telegram_add_draft($pdo, $chatId, $payload, $nextField);

        return contact_trace_telegram_add_prompt($nextField);
    }

    contact_trace_delete_telegram_add_draft($pdo, $chatId);
    return contact_trace_telegram_save_lead($pdo, $chatId, $payload);
}

function contact_trace_parse_add_command(string $payload): array
{
    $trimmedPayload = trim($payload);

    if ($trimmedPayload !== '' && filter_var($trimmedPayload, FILTER_VALIDATE_URL)) {
        return [[
            'phone_display' => '',
            'ad_url' => $trimmedPayload,
            'owner_name' => '',
            'telegram_handle' => '',
            'service_offer' => '',
            'latest_reply' => '',
            'notes' => '',
            'status' => 'contacted',
        ], null];
    }

    $parts = array_map(
        static fn (string $value): string => trim($value),
        explode('|', $payload)
    );

    if (count($parts) < 2) {
        return [null, 'Add needs at least ad URL and phone number.'];
    }

    $parts = array_pad($parts, 8, '');

    $firstPartIsUrl = filter_var($parts[0], FILTER_VALIDATE_URL) !== false;
    $secondPartIsUrl = filter_var($parts[1], FILTER_VALIDATE_URL) !== false;

    if ($firstPartIsUrl || !$secondPartIsUrl) {
        $adUrl = $parts[0];
        $phoneDisplay = $parts[1];
    } else {
        $phoneDisplay = $parts[0];
        $adUrl = $parts[1];
    }

    $input = [
        'phone_display' => $phoneDisplay,
        'ad_url' => $adUrl,
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

function contact_trace_telegram_add_field_definitions(): array
{
    return [
        'ad_url' => [
            'prompt' => '1/3 Send the ad URL.',
            'required' => true,
        ],
        'phone_display' => [
            'prompt' => '2/3 Send the contact number.',
            'required' => true,
        ],
        'owner_name' => [
            'prompt' => '3/3 Send the owner name, or /skip.',
            'required' => false,
        ],
    ];
}

function contact_trace_telegram_fill_from_ad_url(array $payload): array
{
    $adUrl = trim((string) ($payload['ad_url'] ?? ''));

    if ($adUrl === '' || !filter_var($adUrl, FILTER_VALIDATE_URL)) {
        return $payload;
    }

    $contact = contact_trace_extract_contact_from_ad_url($adUrl);

    if (($payload['phone_display'] ?? '') === '') {
        $payload['phone_display'] = (string) ($contact['phone_display'] ?? '');
    }

    if (($payload['owner_name'] ?? '') === '') {
        $payload['owner_name'] = trim((string) ($contact['owner_name'] ?? ''));
    }

    return $payload;
}

function contact_trace_telegram_save_lead(PDO $pdo, string $chatId, array $payload): string
{
    try {
        $leadId = contact_trace_add_lead($pdo, $payload);
    } catch (InvalidArgumentException $exception) {
        $adUrl = trim((string) ($payload['ad_url'] ?? ''));
        $phoneDisplay = trim((string) ($payload['phone_display'] ?? ''));

        if (
            $exception->getMessage() === 'Phone number is required. Leave it blank only when the app can read it from a supported ad link.'
            && $adUrl !== ''
            && $phoneDisplay === ''
        ) {
            contact_trace_save_telegram_add_draft($pdo, $chatId, $payload, 'phone_display');

            return implode("\n\n", [
                'I saved the ad URL first, but could not read the contact number automatically.',
                contact_trace_telegram_add_prompt('phone_display'),
            ]);
        }

        throw $exception;
    }

    $lead = contact_trace_find_lead($pdo, $leadId);

    return contact_trace_telegram_add_success_text($leadId, $lead ?? []);
}

function contact_trace_telegram_add_first_field(): string
{
    $fields = array_keys(contact_trace_telegram_add_field_definitions());

    return $fields[0];
}

function contact_trace_telegram_add_next_field(string $currentField): ?string
{
    $fields = array_keys(contact_trace_telegram_add_field_definitions());
    $currentIndex = array_search($currentField, $fields, true);

    if (!is_int($currentIndex) || !isset($fields[$currentIndex + 1])) {
        return null;
    }

    return $fields[$currentIndex + 1];
}

function contact_trace_telegram_add_prompt(string $field): string
{
    $definitions = contact_trace_telegram_add_field_definitions();

    return (string) ($definitions[$field]['prompt'] ?? 'Send the next value.');
}

function contact_trace_telegram_add_normalize_answer(string $field, string $text, array $definition): array
{
    $value = trim($text);
    $isSkip = in_array(strtolower($value), ['/skip', '-'], true);
    $isRequired = (bool) ($definition['required'] ?? false);

    if ($isSkip) {
        if ($isRequired) {
            return ['', 'This field is required.'];
        }

        if ($field === 'status') {
            return ['contacted', null];
        }

        return ['', null];
    }

    if ($value === '') {
        return ['', $isRequired ? 'This field is required.' : null];
    }

    if ($field === 'phone_display') {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return [[
                'phone_display' => '',
                'ad_url' => $value,
                'owner_name' => '',
                'telegram_handle' => '',
                'service_offer' => '',
                'latest_reply' => '',
                'notes' => '',
                'status' => 'contacted',
                '__save_now' => true,
            ], null];
        }

        if (contact_trace_normalize_phone($value) === '') {
            return ['', 'Please send a valid phone number, or send a supported ad URL such as a Mudah link.'];
        }

        return [$value, null];
    }

    if ($field === 'ad_url') {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return ['', 'Please send a valid URL, for example https://example.com/ad'];
        }

        return [$value, null];
    }

    if ($field === 'status') {
        if (!in_array($value, contact_trace_allowed_statuses(), true)) {
            return ['', 'Status must be one of: ' . implode(', ', contact_trace_allowed_statuses()) . '.'];
        }

        return [$value, null];
    }

    return [$value, null];
}

function contact_trace_telegram_add_success_text(int $leadId, array $lead): string
{
    $lines = ['Lead saved with ID #' . $leadId];
    $ownerName = trim((string) ($lead['owner_name'] ?? ''));
    $phoneDisplay = trim((string) ($lead['phone_display'] ?? ''));

    if ($ownerName !== '') {
        $lines[] = 'Owner: ' . $ownerName;
    }

    if ($phoneDisplay !== '') {
        $lines[] = 'Phone: ' . $phoneDisplay;
    }

    $whatsAppLink = contact_trace_telegram_whatsapp_link($phoneDisplay);

    if ($whatsAppLink !== '') {
        $lines[] = 'WhatsApp: ' . $whatsAppLink;
    }

    return implode("\n", $lines);
}

function contact_trace_telegram_whatsapp_link(string $phone): string
{
    $digits = contact_trace_normalize_phone($phone);

    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $digits = '6' . $digits;
    }

    return 'https://wa.me/' . rawurlencode($digits);
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
        '/add',
        '/cancel',
        '/add https://www.mudah.my/...',
        '/add ad_url | phone | owner',
        '',
        'Use /add for step-by-step entry.',
        'You can also send only a supported ad URL and the bot will try to fill phone and owner name.',
        'Use - or /skip for the optional owner name.',
    ]);
}

function contact_trace_add_usage_text(): string
{
    return implode("\n", [
        'Usage:',
        '/add',
        'Starts step-by-step entry in Telegram.',
        '',
        'Or send only a supported ad URL:',
        '/add https://www.mudah.my/your-listing',
        '',
        'Or send everything in one line:',
        '/add https://example.com/ad | 012-3456789 | Ali',
        '',
        'Phone number and ad URL are required unless the bot can read them from a supported ad link.',
        'The bot accepts both ad_url | phone and phone | ad_url, but ad_url first is preferred.',
        'Owner name is optional.',
        'Use - or /skip for the owner name if you want to leave it empty.',
    ]);
}
