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
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="page-shell">
    <header class="hero">
        <div>
            <p class="eyebrow">Lead memory for outreach</p>
            <h1>Contact Trace</h1>
            <p class="hero-copy">
                Save the phone number, the ad you replied to, and the latest reply so you can search any owner later.
            </p>
        </div>
        <div class="hero-card">
            <span>Quick flow</span>
            <strong>Save lead -> search phone -> see ad + notes</strong>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="flash <?= escape($messageType) ?>"><?= escape($message) ?></div>
    <?php endif; ?>

    <section class="grid-layout">
        <article class="panel form-panel">
            <div class="panel-header">
                <h2>Add a lead</h2>
                <p>Store the ad link together with the owner phone number.</p>
            </div>

            <form method="post" class="stacked-form">
                <input type="hidden" name="action" value="add_lead">

                <label>
                    <span>Owner name</span>
                    <input type="text" name="owner_name" placeholder="Optional">
                </label>

                <label>
                    <span>Phone number</span>
                    <input type="text" name="phone_display" placeholder="012-3456789" required>
                </label>

                <label>
                    <span>Ads link</span>
                    <input type="url" name="ad_url" placeholder="https://www.mudah.my/..." required>
                </label>

                <label>
                    <span>Service offered</span>
                    <input type="text" name="service_offer" placeholder="Example: renovation, cleaning, wiring">
                </label>

                <label>
                    <span>Latest reply</span>
                    <textarea name="latest_reply" rows="3" placeholder="Example: Owner asked for pricing"></textarea>
                </label>

                <label>
                    <span>Notes</span>
                    <textarea name="notes" rows="4" placeholder="Anything you want to remember about this lead"></textarea>
                </label>

                <label>
                    <span>Status</span>
                    <select name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= escape($status) ?>" <?= $status === 'contacted' ? 'selected' : '' ?>>
                                <?= escape(ucwords($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit">Save lead</button>
            </form>
        </article>

        <article class="panel results-panel">
            <div class="panel-header split-header">
                <div>
                    <h2>Search leads</h2>
                    <p>Search by phone number, name, note, or ad link.</p>
                </div>

                <form method="get" class="search-form">
                    <input
                        type="search"
                        name="search"
                        placeholder="Search phone number"
                        value="<?= escape($searchTerm) ?>"
                    >
                    <button type="submit">Search</button>
                </form>
            </div>

            <div class="results-meta">
                <?php if ($searchTerm !== ''): ?>
                    Showing <?= count($leads) ?> result(s) for <strong><?= escape($searchTerm) ?></strong>
                <?php else: ?>
                    Showing the latest <?= count($leads) ?> lead(s)
                <?php endif; ?>
            </div>

            <?php if ($leads === []): ?>
                <div class="empty-state">
                    <h3>No leads found</h3>
                    <p>Save your first contact on the left, then search here by phone number later.</p>
                </div>
            <?php else: ?>
                <div class="lead-list">
                    <?php foreach ($leads as $lead): ?>
                        <section class="lead-card">
                            <div class="lead-card-header">
                                <div>
                                    <h3><?= escape($lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display']) ?></h3>
                                    <p class="lead-phone"><?= escape($lead['phone_display']) ?></p>
                                </div>
                                <span class="status-pill status-<?= escape($lead['status']) ?>">
                                    <?= escape(ucwords($lead['status'])) ?>
                                </span>
                            </div>

                            <dl class="lead-details">
                                <div>
                                    <dt>Ad link</dt>
                                    <dd><a href="<?= escape($lead['ad_url']) ?>" target="_blank" rel="noreferrer">Open ad</a></dd>
                                </div>
                                <div>
                                    <dt>Service</dt>
                                    <dd><?= escape($lead['service_offer']) !== '' ? escape($lead['service_offer']) : 'Not set' ?></dd>
                                </div>
                                <div>
                                    <dt>Saved</dt>
                                    <dd><?= escape(date('d M Y, h:i A', strtotime($lead['created_at']))) ?></dd>
                                </div>
                                <div>
                                    <dt>Updated</dt>
                                    <dd><?= escape(date('d M Y, h:i A', strtotime($lead['updated_at']))) ?></dd>
                                </div>
                            </dl>

                            <form method="post" class="update-form">
                                <input type="hidden" name="action" value="update_lead">
                                <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                <input type="hidden" name="search" value="<?= escape($searchTerm) ?>">

                                <label>
                                    <span>Latest reply</span>
                                    <textarea name="latest_reply" rows="3"><?= escape($lead['latest_reply']) ?></textarea>
                                </label>

                                <label>
                                    <span>Notes</span>
                                    <textarea name="notes" rows="4"><?= escape($lead['notes']) ?></textarea>
                                </label>

                                <div class="update-row">
                                    <label>
                                        <span>Status</span>
                                        <select name="status">
                                            <?php foreach ($statuses as $status): ?>
                                                <option value="<?= escape($status) ?>" <?= $lead['status'] === $status ? 'selected' : '' ?>>
                                                    <?= escape(ucwords($status)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <button type="submit">Update</button>
                                </div>
                            </form>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
</div>
</body>
</html>