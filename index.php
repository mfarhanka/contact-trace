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
            <div class="app-header border rounded-4 bg-white shadow-sm p-4">
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
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-1">Add lead</h2>
                            <p class="text-secondary small mb-4">Store one owner together with the ad link.</p>

                            <form method="post" class="vstack gap-3">
                                <input type="hidden" name="action" value="add_lead">

                                <div>
                                    <label for="owner_name" class="form-label">Owner name</label>
                                    <input id="owner_name" type="text" name="owner_name" class="form-control" placeholder="Optional">
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
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-md-row justify-content-md-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 mb-1">Search leads</h2>
                                    <p class="text-secondary small mb-0">Search by phone number, owner name, note, or ad link.</p>
                                </div>

                                <form method="get" class="row g-2 search-row">
                                    <div class="col">
                                        <input
                                            type="search"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search phone number"
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
                                <div class="vstack gap-3">
                                    <?php foreach ($leads as $lead): ?>
                                        <section class="card border lead-card-simple">
                                            <div class="card-body p-4">
                                                <div class="d-flex flex-column flex-md-row justify-content-md-between gap-2 mb-3">
                                                    <div>
                                                        <h3 class="h6 mb-1"><?= escape($lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display']) ?></h3>
                                                        <div class="text-secondary small"><?= escape($lead['phone_display']) ?></div>
                                                    </div>
                                                    <div>
                                                        <span class="badge text-bg-light border text-uppercase status-badge status-<?= escape($lead['status']) ?>">
                                                            <?= escape(ucwords($lead['status'])) ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="row g-3 small mb-3">
                                                    <div class="col-12 col-md-6">
                                                        <div class="text-secondary">Ad link</div>
                                                        <a href="<?= escape($lead['ad_url']) ?>" target="_blank" rel="noreferrer">Open ad</a>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <div class="text-secondary">Service</div>
                                                        <div><?= escape($lead['service_offer']) !== '' ? escape($lead['service_offer']) : 'Not set' ?></div>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <div class="text-secondary">Saved</div>
                                                        <div><?= escape(date('d M Y, h:i A', strtotime($lead['created_at']))) ?></div>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <div class="text-secondary">Updated</div>
                                                        <div><?= escape(date('d M Y, h:i A', strtotime($lead['updated_at']))) ?></div>
                                                    </div>
                                                </div>

                                                <form method="post" class="vstack gap-3">
                                                    <input type="hidden" name="action" value="update_lead">
                                                    <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                                    <input type="hidden" name="search" value="<?= escape($searchTerm) ?>">

                                                    <div>
                                                        <label for="latest_reply_<?= (int) $lead['id'] ?>" class="form-label">Latest reply</label>
                                                        <textarea id="latest_reply_<?= (int) $lead['id'] ?>" name="latest_reply" rows="3" class="form-control"><?= escape($lead['latest_reply']) ?></textarea>
                                                    </div>

                                                    <div>
                                                        <label for="notes_<?= (int) $lead['id'] ?>" class="form-label">Notes</label>
                                                        <textarea id="notes_<?= (int) $lead['id'] ?>" name="notes" rows="3" class="form-control"><?= escape($lead['notes']) ?></textarea>
                                                    </div>

                                                    <div class="row g-2 align-items-end">
                                                        <div class="col-12 col-md-6">
                                                            <label for="status_<?= (int) $lead['id'] ?>" class="form-label">Status</label>
                                                            <select id="status_<?= (int) $lead['id'] ?>" name="status" class="form-select">
                                                                <?php foreach ($statuses as $status): ?>
                                                                    <option value="<?= escape($status) ?>" <?= $lead['status'] === $status ? 'selected' : '' ?>>
                                                                        <?= escape(ucwords($status)) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-12 col-md-auto ms-md-auto">
                                                            <button type="submit" class="btn btn-outline-secondary w-100">Update</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </section>
                                    <?php endforeach; ?>
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