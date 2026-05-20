<?php
declare(strict_types=1);

$databaseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . 'contact-trace.sqlite';

if (!is_dir($databaseDirectory)) {
    mkdir($databaseDirectory, 0777, true);
}

$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

$message = '';
$messageType = 'success';
$searchTerm = trim((string) ($_GET['search'] ?? ''));

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function telegram_link(string $telegramHandle): string
{
    $normalizedHandle = ltrim(trim($telegramHandle), '@');

    if ($normalizedHandle === '') {
        return '';
    }

    return 'https://t.me/' . rawurlencode($normalizedHandle);
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'new', 'contacted' => 'text-bg-warning',
        'replied', 'follow-up' => 'text-bg-success',
        'closed' => 'text-bg-secondary',
        default => 'text-bg-light',
    };
}

function redirect_with_feedback(string $message, string $type, string $search = ''): never
{
    $query = ['message' => $message, 'type' => $type];

    if ($search !== '') {
        $query['search'] = $search;
    }

    header('Location: ?' . http_build_query($query));
    exit;
}

if (isset($_GET['message'])) {
    $message = trim((string) $_GET['message']);
    $messageType = $_GET['type'] === 'error' ? 'error' : 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_lead') {
        $ownerName = trim((string) ($_POST['owner_name'] ?? ''));
        $telegramHandle = trim((string) ($_POST['telegram_handle'] ?? ''));
        $phoneDisplay = trim((string) ($_POST['phone_display'] ?? ''));
        $phoneNormalized = normalize_phone($phoneDisplay);
        $adUrl = trim((string) ($_POST['ad_url'] ?? ''));
        $serviceOffer = trim((string) ($_POST['service_offer'] ?? ''));
        $latestReply = trim((string) ($_POST['latest_reply'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'contacted'));

        if ($phoneDisplay === '' || $phoneNormalized === '' || $adUrl === '') {
            redirect_with_feedback('Phone number and ad link are required.', 'error');
        }

        if (!filter_var($adUrl, FILTER_VALIDATE_URL)) {
            redirect_with_feedback('Please enter a valid ad link.', 'error');
        }

        $allowedStatuses = ['new', 'contacted', 'replied', 'follow-up', 'closed'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'contacted';
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

        redirect_with_feedback('Lead saved.', 'success', $phoneDisplay);
    }

    if ($action === 'update_lead') {
        $id = (int) ($_POST['id'] ?? 0);
        $search = trim((string) ($_POST['search'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'contacted'));
        $latestReply = trim((string) ($_POST['latest_reply'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $allowedStatuses = ['new', 'contacted', 'replied', 'follow-up', 'closed'];

        if ($id <= 0) {
            redirect_with_feedback('Lead update failed.', 'error', $search);
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'contacted';
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
            ':status' => $status,
            ':latest_reply' => $latestReply,
            ':notes' => $notes,
            ':updated_at' => date('c'),
            ':id' => $id,
        ]);

        redirect_with_feedback('Lead updated.', 'success', $search);
    }
}

$statuses = ['new', 'contacted', 'replied', 'follow-up', 'closed'];

if ($searchTerm !== '') {
    $likeTerm = '%' . $searchTerm . '%';
    $searchDigits = '%' . normalize_phone($searchTerm) . '%';

    $statement = $pdo->prepare(
        'SELECT *
         FROM leads
         WHERE phone_display LIKE :like_term
            OR phone_normalized LIKE :search_digits
                OR telegram_handle LIKE :like_term
            OR owner_name LIKE :like_term
            OR ad_url LIKE :like_term
            OR notes LIKE :like_term
         ORDER BY updated_at DESC'
    );
    $statement->execute([
        ':like_term' => $likeTerm,
        ':search_digits' => $searchDigits,
    ]);
} else {
    $statement = $pdo->query(
        'SELECT *
         FROM leads
         ORDER BY updated_at DESC
         LIMIT 20'
    );
}

$leads = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Trace</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-body-tertiary">
<div class="container py-4 py-lg-5">
    <div class="row justify-content-center mb-4">
        <div class="col-12 col-xl-10">
            <div class="border rounded bg-white shadow-sm p-4">
                <h1 class="h2 mb-2">Contact Trace</h1>
                <p class="text-secondary mb-1">Save the phone number, the ad link, and the latest reply so you can search later.</p>
                <p class="small text-body-secondary mb-0">Simple flow: save lead, search phone number, open the related ad.</p>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <?php if ($message !== ''): ?>
                <div class="alert <?= $messageType === 'error' ? 'alert-danger' : 'alert-success' ?>" role="alert">
                    <?= escape($message) ?>
                </div>
            <?php endif; ?>

            <div class="row g-4 align-items-start">
                <div class="col-12 col-lg-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-1">Add lead</h2>
                            <p class="text-secondary small mb-4">Store one owner together with the phone, Telegram, and ad link.</p>

                            <form method="post" class="vstack gap-3">
                                <input type="hidden" name="action" value="add_lead">

                                <div>
                                    <label for="owner_name" class="form-label">Owner name</label>
                                    <input id="owner_name" type="text" name="owner_name" class="form-control" placeholder="Optional">
                                </div>

                                <div>
                                    <label for="telegram_handle" class="form-label">Telegram</label>
                                    <input id="telegram_handle" type="text" name="telegram_handle" class="form-control" placeholder="@username or t.me/username">
                                </div>

                                <div>
                                    <label for="phone_display" class="form-label">Phone number</label>
                                    <input id="phone_display" type="text" name="phone_display" class="form-control" placeholder="012-3456789" required>
                                </div>

                                <div>
                                    <label for="ad_url" class="form-label">Ads link</label>
                                    <input id="ad_url" type="url" name="ad_url" class="form-control" placeholder="https://www.mudah.my/..." required>
                                </div>

                                <div>
                                    <label for="service_offer" class="form-label">Service offered</label>
                                    <input id="service_offer" type="text" name="service_offer" class="form-control" placeholder="Optional">
                                </div>

                                <div>
                                    <label for="latest_reply" class="form-label">Latest reply</label>
                                    <textarea id="latest_reply" name="latest_reply" rows="3" class="form-control" placeholder="Optional"></textarea>
                                </div>

                                <div>
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea id="notes" name="notes" rows="4" class="form-control" placeholder="Optional"></textarea>
                                </div>

                                <div>
                                    <label for="status" class="form-label">Status</label>
                                    <select id="status" name="status" class="form-select">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= escape($status) ?>" <?= $status === 'contacted' ? 'selected' : '' ?>>
                                                <?= escape(ucwords($status)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary">Save lead</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-8">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-md-row justify-content-md-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 mb-1">Search leads</h2>
                                    <p class="text-secondary small mb-0">Search by phone number, Telegram, owner name, note, or ad link.</p>
                                </div>

                                <form method="get" class="row g-2">
                                    <div class="col">
                                        <input
                                            type="search"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search phone or Telegram"
                                            value="<?= escape($searchTerm) ?>"
                                        >
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-outline-primary">Search</button>
                                    </div>
                                </form>
                            </div>

                            <p class="small text-body-secondary mb-4">
                                <?php if ($searchTerm !== ''): ?>
                                    Showing <?= count($leads) ?> result(s) for <strong><?= escape($searchTerm) ?></strong>
                                <?php else: ?>
                                    Showing the latest <?= count($leads) ?> lead(s)
                                <?php endif; ?>
                            </p>

                            <?php if ($leads === []): ?>
                                <div class="border rounded-3 p-4 bg-body-tertiary">
                                    <h3 class="h6 mb-1">No leads found</h3>
                                    <p class="text-secondary small mb-0">Save your first contact, then search here by phone number later.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                        <tr>
                                            <th scope="col">Owner</th>
                                            <th scope="col">Ad</th>
                                            <th scope="col">Reply</th>
                                            <th scope="col">Notes</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Updated</th>
                                            <th scope="col">Action</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($leads as $lead): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?= escape($lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display']) ?></div>
                                                    <div class="text-secondary small"><?= escape($lead['phone_display']) ?></div>
                                                    <?php if ($lead['telegram_handle'] !== ''): ?>
                                                        <div class="small">
                                                            <a href="<?= escape(telegram_link($lead['telegram_handle'])) ?>" target="_blank" rel="noreferrer">
                                                                <?= escape($lead['telegram_handle']) ?>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="text-secondary small"><?= escape($lead['service_offer']) !== '' ? escape($lead['service_offer']) : 'No service set' ?></div>
                                                </td>
                                                <td>
                                                    <a href="<?= escape($lead['ad_url']) ?>" target="_blank" rel="noreferrer">Open ad</a>
                                                </td>
                                                <td colspan="5">
                                                    <form method="post" class="row g-2 align-items-start">
                                                        <input type="hidden" name="action" value="update_lead">
                                                        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                                        <input type="hidden" name="search" value="<?= escape($searchTerm) ?>">

                                                        <div class="col-12 col-xl-3">
                                                            <textarea
                                                                id="latest_reply_<?= (int) $lead['id'] ?>"
                                                                name="latest_reply"
                                                                rows="2"
                                                                class="form-control form-control-sm"
                                                                placeholder="Latest reply"
                                                            ><?= escape($lead['latest_reply']) ?></textarea>
                                                        </div>

                                                        <div class="col-12 col-xl-3">
                                                            <textarea
                                                                id="notes_<?= (int) $lead['id'] ?>"
                                                                name="notes"
                                                                rows="2"
                                                                class="form-control form-control-sm"
                                                                placeholder="Notes"
                                                            ><?= escape($lead['notes']) ?></textarea>
                                                        </div>

                                                        <div class="col-6 col-xl-2">
                                                            <select id="status_<?= (int) $lead['id'] ?>" name="status" class="form-select form-select-sm">
                                                                <?php foreach ($statuses as $status): ?>
                                                                    <option value="<?= escape($status) ?>" <?= $lead['status'] === $status ? 'selected' : '' ?>>
                                                                        <?= escape(ucwords($status)) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <div class="mt-2">
                                                                <span class="badge <?= status_badge_class($lead['status']) ?>">
                                                                    <?= escape(ucwords($lead['status'])) ?>
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div class="col-6 col-xl-2 small text-secondary">
                                                            <div><?= escape(date('d M Y', strtotime($lead['updated_at']))) ?></div>
                                                            <div><?= escape(date('h:i A', strtotime($lead['updated_at']))) ?></div>
                                                        </div>

                                                        <div class="col-12 col-xl-2">
                                                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Update</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>