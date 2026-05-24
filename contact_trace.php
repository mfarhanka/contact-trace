<?php
declare(strict_types=1);

function contact_trace_load_env_file(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmedLine = trim($line);

        if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
            continue;
        }

        $separatorPosition = strpos($trimmedLine, '=');

        if ($separatorPosition === false) {
            continue;
        }

        $name = trim(substr($trimmedLine, 0, $separatorPosition));
        $value = trim(substr($trimmedLine, $separatorPosition + 1));

        if ($name === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = contact_trace_decode_env_value(substr($value, 1, -1));
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function contact_trace_decode_env_value(string $value): string
{
    return strtr($value, [
        '\\r' => "\r",
        '\\n' => "\n",
        '\\t' => "\t",
        '\\\\' => '\\',
        '\\"' => '"',
        "\\'" => "'",
    ]);
}

function contact_trace_env_file_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.env';
}

function contact_trace_manageable_settings(): array
{
    return [
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_ALLOWED_CHAT_IDS',
        'APP_PUBLIC_URL',
        'WHATSAPP_BRIDGE_URL',
        'WHATSAPP_BRIDGE_TOKEN',
        'WHATSAPP_AUTO_MESSAGE_TEMPLATE',
    ];
}

function contact_trace_current_manageable_settings(): array
{
    $settings = [];

    foreach (contact_trace_manageable_settings() as $key) {
        $settings[$key] = contact_trace_env($key);
    }

    return $settings;
}

function contact_trace_save_manageable_settings(array $settings): void
{
    $managedSettings = [];

    foreach (contact_trace_manageable_settings() as $key) {
        $managedSettings[$key] = trim((string) ($settings[$key] ?? ''));
    }

    $publicUrl = $managedSettings['APP_PUBLIC_URL'];

    if ($publicUrl !== '' && !filter_var($publicUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Please enter a valid public app URL.');
    }

    $lines = [];

    foreach ($managedSettings as $key => $value) {
        $lines[] = $key . '=' . contact_trace_encode_env_value($value);
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $result = file_put_contents(contact_trace_env_file_path(), implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);

    if ($result === false) {
        throw new RuntimeException('Unable to save Telegram settings to .env.');
    }
}

function contact_trace_encode_env_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_:\/.,@\-]+$/', $value) === 1) {
        return $value;
    }

    return '"' . strtr($value, [
        '\\' => '\\\\',
        "\r" => '\\r',
        "\n" => '\\n',
        "\t" => '\\t',
        '"' => '\\"',
    ]) . '"';
}

function contact_trace_database_directory(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

function contact_trace_database_path(): string
{
    return contact_trace_database_directory() . DIRECTORY_SEPARATOR . 'contact-trace.sqlite';
}

function contact_trace_get_pdo(): PDO
{
    $databaseDirectory = contact_trace_database_directory();

    if (!is_dir($databaseDirectory)) {
        mkdir($databaseDirectory, 0777, true);
    }

    $pdo = new PDO('sqlite:' . contact_trace_database_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    contact_trace_ensure_schema($pdo);

    return $pdo;
}

function contact_trace_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_name TEXT NOT NULL DEFAULT \'\',
            telegram_handle TEXT NOT NULL DEFAULT \'\',
            phone_display TEXT NOT NULL,
            phone_normalized TEXT NOT NULL,
            ad_url TEXT NOT NULL,
            service_offer TEXT NOT NULL DEFAULT \'\',
            latest_reply TEXT NOT NULL DEFAULT \'\',
            notes TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'contacted\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_users_username ON admin_users (username)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS telegram_add_drafts (
            chat_id TEXT PRIMARY KEY,
            payload_json TEXT NOT NULL DEFAULT \'{}\',
            current_field TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $columns = $pdo->query('PRAGMA table_info(leads)')->fetchAll();
    $columnNames = array_column($columns, 'name');

    if (!in_array('telegram_handle', $columnNames, true)) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN telegram_handle TEXT NOT NULL DEFAULT ''");
    }
}

function contact_trace_allowed_statuses(): array
{
    return ['new', 'contacted', 'replied', 'follow-up', 'closed'];
}

function contact_trace_normalize_admin_username(string $username): string
{
    return strtolower(trim($username));
}

function contact_trace_validate_admin_username(string $username): string
{
    $normalizedUsername = contact_trace_normalize_admin_username($username);

    if ($normalizedUsername === '' || preg_match('/^[a-z0-9_.-]{3,32}$/', $normalizedUsername) !== 1) {
        throw new InvalidArgumentException('Username must be 3 to 32 characters and use only letters, numbers, dot, dash, or underscore.');
    }

    return $normalizedUsername;
}

function contact_trace_validate_admin_password(string $password): string
{
    $cleanPassword = trim($password);

    if (strlen($cleanPassword) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters long.');
    }

    return $cleanPassword;
}

function contact_trace_count_admin_users(PDO $pdo): int
{
    $count = $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();

    return (int) $count;
}

function contact_trace_find_admin_user_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function contact_trace_find_admin_user_by_username(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare('SELECT * FROM admin_users WHERE username = :username LIMIT 1');
    $statement->execute([':username' => contact_trace_normalize_admin_username($username)]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function contact_trace_create_admin_user(PDO $pdo, string $username, string $password): int
{
    $normalizedUsername = contact_trace_validate_admin_username($username);
    $validatedPassword = contact_trace_validate_admin_password($password);

    if (contact_trace_find_admin_user_by_username($pdo, $normalizedUsername) !== null) {
        throw new InvalidArgumentException('That username already exists.');
    }

    $passwordHash = password_hash($validatedPassword, PASSWORD_DEFAULT);

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Unable to secure the password.');
    }

    $timestamp = date('c');
    $statement = $pdo->prepare(
        'INSERT INTO admin_users (
            username,
            password_hash,
            created_at,
            updated_at
        ) VALUES (
            :username,
            :password_hash,
            :created_at,
            :updated_at
        )'
    );

    $statement->execute([
        ':username' => $normalizedUsername,
        ':password_hash' => $passwordHash,
        ':created_at' => $timestamp,
        ':updated_at' => $timestamp,
    ]);

    return (int) $pdo->lastInsertId();
}

function contact_trace_verify_admin_credentials(PDO $pdo, string $username, string $password): ?array
{
    $user = contact_trace_find_admin_user_by_username($pdo, $username);

    if ($user === null) {
        return null;
    }

    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        return null;
    }

    return $user;
}

function contact_trace_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function contact_trace_normalize_whatsapp_phone(string $phone): string
{
    $digits = contact_trace_normalize_phone($phone);

    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '00')) {
        return substr($digits, 2);
    }

    if (str_starts_with($digits, '60')) {
        return $digits;
    }

    if (str_starts_with($digits, '0')) {
        return '6' . $digits;
    }

    return $digits;
}

function contact_trace_whatsapp_link(string $phone): string
{
    $whatsappPhone = contact_trace_normalize_whatsapp_phone($phone);

    if ($whatsappPhone === '') {
        return '';
    }

    return 'https://wa.me/' . rawurlencode($whatsappPhone);
}

function contact_trace_normalize_telegram_handle(string $telegramHandle): string
{
    $value = trim($telegramHandle);

    if ($value === '') {
        return '';
    }

    if (preg_match('~(?:https?://)?(?:www\.)?t\.me/([A-Za-z0-9_]+)/*$~i', $value, $matches) === 1) {
        return '@' . $matches[1];
    }

    if (preg_match('~^@?([A-Za-z0-9_]+)$~', $value, $matches) === 1) {
        return '@' . $matches[1];
    }

    return $value;
}

function contact_trace_validate_status(string $status): string
{
    $cleanStatus = trim($status);

    if (!in_array($cleanStatus, contact_trace_allowed_statuses(), true)) {
        return 'contacted';
    }

    return $cleanStatus;
}

function contact_trace_add_lead(PDO $pdo, array $input): int
{
    $ownerName = trim((string) ($input['owner_name'] ?? ''));
    $telegramHandle = contact_trace_normalize_telegram_handle((string) ($input['telegram_handle'] ?? ''));
    $phoneDisplay = trim((string) ($input['phone_display'] ?? ''));
    $phoneNormalized = contact_trace_normalize_phone($phoneDisplay);
    $adUrl = trim((string) ($input['ad_url'] ?? ''));
    $serviceOffer = trim((string) ($input['service_offer'] ?? ''));
    $latestReply = trim((string) ($input['latest_reply'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $status = contact_trace_validate_status((string) ($input['status'] ?? 'contacted'));

    if ($phoneDisplay === '' || $phoneNormalized === '' || $adUrl === '') {
        throw new InvalidArgumentException('Phone number and ad link are required.');
    }

    if (!filter_var($adUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Please enter a valid ad link.');
    }

    $timestamp = date('c');
    $statement = $pdo->prepare(
        'INSERT INTO leads (
            owner_name,
            telegram_handle,
            phone_display,
            phone_normalized,
            ad_url,
            service_offer,
            latest_reply,
            notes,
            status,
            created_at,
            updated_at
        ) VALUES (
            :owner_name,
            :telegram_handle,
            :phone_display,
            :phone_normalized,
            :ad_url,
            :service_offer,
            :latest_reply,
            :notes,
            :status,
            :created_at,
            :updated_at
        )'
    );

    $statement->execute([
        ':owner_name' => $ownerName,
        ':telegram_handle' => $telegramHandle,
        ':phone_display' => $phoneDisplay,
        ':phone_normalized' => $phoneNormalized,
        ':ad_url' => $adUrl,
        ':service_offer' => $serviceOffer,
        ':latest_reply' => $latestReply,
        ':notes' => $notes,
        ':status' => $status,
        ':created_at' => $timestamp,
        ':updated_at' => $timestamp,
    ]);

    return (int) $pdo->lastInsertId();
}

function contact_trace_update_lead(PDO $pdo, int $id, array $input): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Lead update failed.');
    }

    $statement = $pdo->prepare(
        'UPDATE leads
         SET status = :status,
             latest_reply = :latest_reply,
             notes = :notes,
             updated_at = :updated_at
         WHERE id = :id'
    );

    $statement->execute([
        ':status' => contact_trace_validate_status((string) ($input['status'] ?? 'contacted')),
        ':latest_reply' => trim((string) ($input['latest_reply'] ?? '')),
        ':notes' => trim((string) ($input['notes'] ?? '')),
        ':updated_at' => date('c'),
        ':id' => $id,
    ]);

    $lead = contact_trace_find_lead($pdo, $id);

    if ($lead === null) {
        throw new RuntimeException('Lead update failed.');
    }

    return $lead;
}

function contact_trace_search_leads(PDO $pdo, string $searchTerm, int $limit = 20): array
{
    $term = trim($searchTerm);

    if ($term === '') {
        return contact_trace_recent_leads($pdo, $limit);
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM leads
         WHERE phone_display LIKE :like_term
            OR phone_normalized LIKE :search_digits
            OR telegram_handle LIKE :like_term
            OR owner_name LIKE :like_term
            OR ad_url LIKE :like_term
            OR service_offer LIKE :like_term
            OR latest_reply LIKE :like_term
            OR notes LIKE :like_term
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':like_term', '%' . $term . '%', PDO::PARAM_STR);
    $statement->bindValue(':search_digits', '%' . contact_trace_normalize_phone($term) . '%', PDO::PARAM_STR);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function contact_trace_recent_leads(PDO $pdo, int $limit = 20): array
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM leads
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function contact_trace_find_lead(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM leads WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $lead = $statement->fetch();

    return is_array($lead) ? $lead : null;
}

function contact_trace_delete_lead(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare('DELETE FROM leads WHERE id = :id');
    $statement->execute([':id' => $id]);

    return $statement->rowCount() > 0;
}

function contact_trace_env(string $name): string
{
    contact_trace_load_env_file();

    $value = getenv($name);

    if (is_string($value) && $value !== '') {
        return trim($value);
    }

    $serverValue = $_SERVER[$name] ?? $_ENV[$name] ?? '';

    return is_string($serverValue) ? trim($serverValue) : '';
}

function contact_trace_telegram_api_request(string $botToken, string $method, array $payload = []): array
{
    if ($botToken === '') {
        throw new InvalidArgumentException('Missing TELEGRAM_BOT_TOKEN.');
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Unable to encode Telegram request payload.');
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/' . rawurlencode($method);

    if (function_exists('curl_init')) {
        $response = contact_trace_telegram_api_request_via_curl($url, $body);
    } else {
        $response = contact_trace_telegram_api_request_via_stream($url, $body);
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Telegram API returned an invalid response.');
    }

    if (($decoded['ok'] ?? false) !== true) {
        $description = trim((string) ($decoded['description'] ?? 'Telegram API error.'));
        throw new RuntimeException($description !== '' ? $description : 'Telegram API error.');
    }

    $result = $decoded['result'] ?? [];

    return is_array($result) ? $result : [];
}

function contact_trace_telegram_api_request_via_curl(string $url, string $body): string
{
    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for Telegram API request.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($handle);

    if ($response === false) {
        $errorMessage = trim(curl_error($handle));
        curl_close($handle);
        throw new RuntimeException('Telegram API request failed: ' . ($errorMessage !== '' ? $errorMessage : 'Unknown cURL error.'));
    }

    curl_close($handle);

    return is_string($response) ? $response : '';
}

function contact_trace_telegram_api_request_via_stream(string $url, string $body): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
            ]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $lastError = error_get_last();
        $errorMessage = trim((string) ($lastError['message'] ?? 'Unknown stream error.'));
        throw new RuntimeException('Telegram API request failed: ' . $errorMessage);
    }

    return $response;
}

function contact_trace_register_telegram_webhook(string $botToken, string $webhookUrl): array
{
    $cleanUrl = trim($webhookUrl);

    if ($cleanUrl === '') {
        throw new InvalidArgumentException('Webhook URL is required.');
    }

    if (!filter_var($cleanUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Please enter a valid webhook URL.');
    }

    return contact_trace_telegram_api_request($botToken, 'setWebhook', ['url' => $cleanUrl]);
}

function contact_trace_get_telegram_webhook_info(string $botToken): array
{
    return contact_trace_telegram_api_request($botToken, 'getWebhookInfo');
}

function contact_trace_delete_telegram_webhook(string $botToken, bool $dropPendingUpdates = false): array
{
    return contact_trace_telegram_api_request($botToken, 'deleteWebhook', [
        'drop_pending_updates' => $dropPendingUpdates,
    ]);
}

function contact_trace_get_telegram_updates(string $botToken, int $offset = 0, int $timeout = 25): array
{
    $payload = [
        'timeout' => max(0, min($timeout, 50)),
        'allowed_updates' => ['message', 'edited_message'],
    ];

    if ($offset > 0) {
        $payload['offset'] = $offset;
    }

    return contact_trace_telegram_api_request($botToken, 'getUpdates', $payload);
}

function contact_trace_send_telegram_message(string $botToken, string $chatId, string $text): void
{
    contact_trace_telegram_api_request($botToken, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
    ]);
}

function contact_trace_telegram_alert_chat_ids(): array
{
    $values = array_map(
        static fn (string $value): string => trim($value),
        explode(',', contact_trace_env('TELEGRAM_ALLOWED_CHAT_IDS'))
    );

    $chatIds = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));

    return array_values(array_unique($chatIds));
}

function contact_trace_format_lead_update_alert(array $beforeLead, array $afterLead): string
{
    $leadId = (int) ($afterLead['id'] ?? 0);
    $leadName = trim((string) ($afterLead['owner_name'] ?? ''));
    $phone = trim((string) ($afterLead['phone_display'] ?? ''));
    $title = $leadName !== '' ? $leadName : $phone;
    $lines = ['Lead updated: #' . $leadId . ' ' . $title];

    if ($phone !== '') {
        $lines[] = 'Phone: ' . $phone;
    }

    $beforeStatus = trim((string) ($beforeLead['status'] ?? ''));
    $afterStatus = trim((string) ($afterLead['status'] ?? ''));

    if ($beforeStatus !== $afterStatus) {
        $lines[] = 'Status: ' . ($beforeStatus !== '' ? $beforeStatus : 'none') . ' -> ' . ($afterStatus !== '' ? $afterStatus : 'none');
    } elseif ($afterStatus !== '') {
        $lines[] = 'Status: ' . $afterStatus;
    }

    $beforeReply = trim((string) ($beforeLead['latest_reply'] ?? ''));
    $afterReply = trim((string) ($afterLead['latest_reply'] ?? ''));

    if ($beforeReply !== $afterReply) {
        $lines[] = 'Reply: ' . ($afterReply !== '' ? contact_trace_truncate_text($afterReply, 240) : '(cleared)');
    }

    $adUrl = trim((string) ($afterLead['ad_url'] ?? ''));

    if ($adUrl !== '') {
        $lines[] = 'Ad: ' . contact_trace_truncate_text($adUrl, 120);
    }

    return implode("\n", $lines);
}

function contact_trace_send_lead_update_telegram_alert(array $beforeLead, array $afterLead): array
{
    $beforeStatus = trim((string) ($beforeLead['status'] ?? ''));
    $afterStatus = trim((string) ($afterLead['status'] ?? ''));
    $beforeReply = trim((string) ($beforeLead['latest_reply'] ?? ''));
    $afterReply = trim((string) ($afterLead['latest_reply'] ?? ''));

    if ($beforeStatus === $afterStatus && $beforeReply === $afterReply) {
        return [
            'sent' => false,
            'reason' => 'No reply or status change.',
        ];
    }

    $botToken = contact_trace_env('TELEGRAM_BOT_TOKEN');

    if ($botToken === '') {
        return [
            'sent' => false,
            'reason' => 'Telegram bot token is missing.',
        ];
    }

    $chatIds = contact_trace_telegram_alert_chat_ids();

    if ($chatIds === []) {
        return [
            'sent' => false,
            'reason' => 'No Telegram alert chat IDs configured.',
        ];
    }

    $message = contact_trace_format_lead_update_alert($beforeLead, $afterLead);

    foreach ($chatIds as $chatId) {
        contact_trace_send_telegram_message($botToken, $chatId, $message);
    }

    return [
        'sent' => true,
        'chat_count' => count($chatIds),
    ];
}

function contact_trace_whatsapp_bridge_is_configured(): bool
{
    return contact_trace_env('WHATSAPP_BRIDGE_URL') !== '' && contact_trace_env('WHATSAPP_BRIDGE_TOKEN') !== '';
}

function contact_trace_whatsapp_bridge_request(string $method, string $path, array $payload = []): array
{
    $baseUrl = rtrim(contact_trace_env('WHATSAPP_BRIDGE_URL'), '/');
    $token = contact_trace_env('WHATSAPP_BRIDGE_TOKEN');

    if ($baseUrl === '' || $token === '') {
        throw new RuntimeException('WhatsApp bridge is not configured yet.');
    }

    if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('WhatsApp bridge URL is invalid.');
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Unable to encode WhatsApp bridge payload.');
    }

    if (function_exists('curl_init')) {
        $response = contact_trace_whatsapp_bridge_request_via_curl($method, $url, $token, $body);
    } else {
        $response = contact_trace_whatsapp_bridge_request_via_stream($method, $url, $token, $body);
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('WhatsApp bridge returned an invalid response.');
    }

    if (($decoded['ok'] ?? false) !== true) {
        $error = trim((string) ($decoded['error'] ?? 'WhatsApp bridge request failed.'));
        throw new RuntimeException($error !== '' ? $error : 'WhatsApp bridge request failed.');
    }

    $result = $decoded['result'] ?? [];

    return is_array($result) ? $result : [];
}

function contact_trace_whatsapp_bridge_request_via_curl(string $method, string $url, string $token, string $body): string
{
    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for WhatsApp bridge request.');
    }

    $headers = [
        'X-Bridge-Token: ' . $token,
        'Accept: application/json',
    ];

    if ($body !== '') {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($handle);

    if ($response === false) {
        $errorMessage = trim(curl_error($handle));
        curl_close($handle);
        throw new RuntimeException('WhatsApp bridge request failed: ' . ($errorMessage !== '' ? $errorMessage : 'Unknown cURL error.'));
    }

    curl_close($handle);

    return is_string($response) ? $response : '';
}

function contact_trace_whatsapp_bridge_request_via_stream(string $method, string $url, string $token, string $body): string
{
    $headers = [
        'X-Bridge-Token: ' . $token,
        'Accept: application/json',
    ];

    if ($body !== '') {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $lastError = error_get_last();
        $errorMessage = trim((string) ($lastError['message'] ?? 'Unknown stream error.'));
        throw new RuntimeException('WhatsApp bridge request failed: ' . $errorMessage);
    }

    return $response;
}

function contact_trace_get_whatsapp_bridge_status(): array
{
    return contact_trace_whatsapp_bridge_request('GET', '/api/status');
}

function contact_trace_send_whatsapp_message(string $phone, string $message): array
{
    $normalizedPhone = contact_trace_normalize_whatsapp_phone($phone);

    if ($normalizedPhone === '') {
        throw new InvalidArgumentException('Lead phone number is not valid for WhatsApp.');
    }

    $cleanMessage = trim($message);

    if ($cleanMessage === '') {
        throw new InvalidArgumentException('WhatsApp message is empty.');
    }

    return contact_trace_whatsapp_bridge_request('POST', '/api/send', [
        'phone' => $normalizedPhone,
        'text' => $cleanMessage,
    ]);
}

function contact_trace_render_whatsapp_template(string $template, array $lead): string
{
    $replacements = [
        '{{owner_name}}' => trim((string) ($lead['owner_name'] ?? '')),
        '{{phone}}' => trim((string) ($lead['phone_display'] ?? '')),
        '{{ad_url}}' => trim((string) ($lead['ad_url'] ?? '')),
        '{{service_offer}}' => trim((string) ($lead['service_offer'] ?? '')),
        '{{latest_reply}}' => trim((string) ($lead['latest_reply'] ?? '')),
        '{{notes}}' => trim((string) ($lead['notes'] ?? '')),
        '{{status}}' => trim((string) ($lead['status'] ?? '')),
    ];

    return trim(strtr($template, $replacements));
}

function contact_trace_parse_whatsapp_templates(string $templateSet): array
{
    $normalizedTemplateSet = str_replace(["\r\n", "\r"], "\n", $templateSet);
    $parts = preg_split('/^\s*---\s*$/m', $normalizedTemplateSet);

    if ($parts === false) {
        $parts = [$normalizedTemplateSet];
    }

    $templates = [];

    foreach ($parts as $part) {
        $template = trim($part);

        if ($template !== '') {
            $templates[] = $template;
        }
    }

    return $templates;
}

function contact_trace_send_whatsapp_auto_message_for_lead(array $lead): array
{
    $templateSet = contact_trace_env('WHATSAPP_AUTO_MESSAGE_TEMPLATE');

    if ($templateSet === '') {
        return [
            'sent' => false,
            'reason' => 'WhatsApp auto message template is empty.',
        ];
    }

    if (!contact_trace_whatsapp_bridge_is_configured()) {
        return [
            'sent' => false,
            'reason' => 'WhatsApp bridge is not configured.',
        ];
    }

    $templates = contact_trace_parse_whatsapp_templates($templateSet);

    if ($templates === []) {
        return [
            'sent' => false,
            'reason' => 'WhatsApp auto message templates rendered empty text.',
        ];
    }

    $messageTexts = [];
    $result = [];
    $templateCount = count($templates);

    foreach ($templates as $index => $template) {
        $message = contact_trace_render_whatsapp_template($template, $lead);

        if ($message === '') {
            continue;
        }

        $result = contact_trace_send_whatsapp_message((string) ($lead['phone_display'] ?? ''), $message);
        $messageTexts[] = $message;

        if ($index < $templateCount - 1) {
            sleep(5);
        }
    }

    if ($messageTexts === []) {
        return [
            'sent' => false,
            'reason' => 'WhatsApp auto message templates rendered empty text.',
        ];
    }

    $result['sent'] = true;
    $result['message_text'] = implode("\n\n---\n\n", $messageTexts);
    $result['message_texts'] = $messageTexts;
    $result['message_count'] = count($messageTexts);

    return $result;
}
