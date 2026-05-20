<?php
declare(strict_types=1);

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

function contact_trace_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
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

function contact_trace_update_lead(PDO $pdo, int $id, array $input): void
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
