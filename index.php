<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

$pdo = contact_trace_get_pdo();

$message = '';
$messageType = 'success';
$searchTerm = trim((string) ($_GET['search'] ?? ''));

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
        $phoneDisplay = trim((string) ($_POST['phone_display'] ?? ''));

        try {
            contact_trace_add_lead($pdo, $_POST);
        } catch (InvalidArgumentException $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback('Lead saved.', 'success', $phoneDisplay);
    }

    if ($action === 'update_lead') {
        $id = (int) ($_POST['id'] ?? 0);
        $search = trim((string) ($_POST['search'] ?? ''));

        $existingLead = contact_trace_find_lead($pdo, $id);

        if ($existingLead === null) {
            redirect_with_feedback('Lead not found.', 'error', $search);
        }

        try {
            $updatedLead = contact_trace_update_lead($pdo, $id, $_POST);
        } catch (InvalidArgumentException $exception) {
            redirect_with_feedback($exception->getMessage(), 'error', $search);
        }

        $feedback = 'Lead updated.';

        try {
            $alertResult = contact_trace_send_lead_update_telegram_alert($existingLead, $updatedLead);

            if (($alertResult['sent'] ?? false) === true) {
                $chatCount = (int) ($alertResult['chat_count'] ?? 1);
                $feedback .= ' Telegram alert sent to ' . $chatCount . ' chat' . ($chatCount === 1 ? '' : 's') . '.';
            }
        } catch (Throwable $exception) {
            $feedback .= ' Telegram alert failed: ' . $exception->getMessage();
        }

        redirect_with_feedback($feedback, 'success', $search);
    }

    if ($action === 'delete_lead') {
        $id = (int) ($_POST['id'] ?? 0);
        $search = trim((string) ($_POST['search'] ?? ''));

        if ($id < 1) {
            redirect_with_feedback('Lead not found.', 'error', $search);
        }

        if (!contact_trace_delete_lead($pdo, $id)) {
            redirect_with_feedback('Lead not found.', 'error', $search);
        }

        redirect_with_feedback('Lead removed.', 'success', $search);
    }
}

$statuses = contact_trace_allowed_statuses();
$leads = contact_trace_search_leads($pdo, $searchTerm);
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
<div class="container-fluid px-3 px-lg-4 py-4 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-12">
            <?php if ($message !== ''): ?>
                <div class="alert <?= $messageType === 'error' ? 'alert-danger' : 'alert-success' ?>" role="alert">
                    <?= escape($message) ?>
                </div>
            <?php endif; ?>

            <div class="row g-4 align-items-start">
                <div class="col-12 col-lg-4 col-xl-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-1">Add lead</h2>
                            <p class="text-secondary small mb-4">Store one owner together with the phone and ad link.</p>

                            <form method="post" class="vstack gap-3" id="addLeadForm" novalidate>
                                <input type="hidden" name="action" value="add_lead">

                                <div id="duplicateLeadAlert" class="alert alert-danger d-none mb-0" role="alert"></div>

                                <div>
                                    <label for="owner_name" class="form-label">Owner name</label>
                                    <input id="owner_name" type="text" name="owner_name" class="form-control" placeholder="Optional">
                                </div>

                                <div>
                                    <label for="phone_display" class="form-label">Phone number</label>
                                    <input id="phone_display" type="text" name="phone_display" class="form-control" placeholder="012-3456789" inputmode="tel" required>
                                    <div class="form-text">Local numbers like 0107744530 are saved in WhatsApp format as 60107744530.</div>
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

                <div class="col-12 col-lg-8 col-xl-9">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-md-row justify-content-md-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 mb-1">Search leads</h2>
                                    <p class="text-secondary small mb-0">Search by phone number, owner name, note, or ad link.</p>
                                </div>

                                <form method="get" class="row g-2">
                                    <div class="col">
                                        <input
                                            type="search"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search phone, owner, notes, or ad"
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
                                                    <?php $whatsappLink = contact_trace_whatsapp_link($lead['phone_display']); ?>
                                                    <?php if ($whatsappLink !== ''): ?>
                                                        <div class="small">
                                                            <a href="<?= escape($whatsappLink) ?>" target="_blank" rel="noreferrer">WhatsApp</a>
                                                        </div>
                                                    <?php endif; ?>
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
                                                <td>
                                                    <div class="small text-break"><?= escape($lead['latest_reply'] !== '' ? $lead['latest_reply'] : 'No reply yet') ?></div>
                                                </td>
                                                <td>
                                                    <div class="small text-break"><?= escape($lead['notes'] !== '' ? $lead['notes'] : 'No notes') ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= status_badge_class($lead['status']) ?>">
                                                        <?= escape(ucwords($lead['status'])) ?>
                                                    </span>
                                                </td>
                                                <td class="small text-secondary">
                                                    <div><?= escape(date('d M Y', strtotime($lead['updated_at']))) ?></div>
                                                    <div><?= escape(date('h:i A', strtotime($lead['updated_at']))) ?></div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-secondary btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editLeadModal"
                                                            data-lead-id="<?= (int) $lead['id'] ?>"
                                                            data-lead-name="<?= escape($lead['owner_name'] !== '' ? $lead['owner_name'] : $lead['phone_display']) ?>"
                                                            data-latest-reply="<?= escape($lead['latest_reply']) ?>"
                                                            data-notes="<?= escape($lead['notes']) ?>"
                                                            data-status="<?= escape($lead['status']) ?>"
                                                        >Edit</button>
                                                        <form method="post" onsubmit="return confirm('Remove this lead?');">
                                                            <input type="hidden" name="action" value="delete_lead">
                                                            <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
                                                            <input type="hidden" name="search" value="<?= escape($searchTerm) ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                                                        </form>
                                                    </div>
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

            <div class="card shadow-sm mt-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-3">
                        <div>
                            <h2 class="h5 mb-1">Admin</h2>
                            <p class="text-secondary small mb-0">Open the separate admin page to manage Telegram bot settings and the local polling worker.</p>
                        </div>
                        <div>
                            <a href="admin.php" class="btn btn-outline-primary">Open admin page</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="editLeadModal" tabindex="-1" aria-labelledby="editLeadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="editLeadModalLabel">Edit lead</h2>
                        <p class="text-secondary small mb-0" id="editLeadModalDescription">Update the latest reply, notes, and status for this lead.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="action" value="update_lead">
                    <input type="hidden" name="id" id="edit_lead_id" value="0">
                    <input type="hidden" name="search" value="<?= escape($searchTerm) ?>">

                    <div class="mb-3">
                        <div class="small text-secondary">Editing</div>
                        <div class="fw-semibold" id="edit_lead_name">Lead</div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_latest_reply" class="form-label">Latest reply</label>
                        <textarea
                            id="edit_latest_reply"
                            name="latest_reply"
                            rows="4"
                            class="form-control"
                            placeholder="Latest reply"
                        ></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea
                            id="edit_notes"
                            name="notes"
                            rows="5"
                            class="form-control"
                            placeholder="Notes"
                        ></textarea>
                    </div>

                    <div>
                        <label for="edit_status" class="form-label">Status</label>
                        <select id="edit_status" name="status" class="form-select">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= escape($status) ?>"><?= escape(ucwords($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var addLeadForm = document.getElementById('addLeadForm');
    var phoneInput = document.getElementById('phone_display');
    var adUrlInput = document.getElementById('ad_url');
    var duplicateAlert = document.getElementById('duplicateLeadAlert');
    var editLeadModal = document.getElementById('editLeadModal');
    var duplicateCheckTimer = null;
    var duplicateState = {
        pending: false,
        duplicate: false,
        message: '',
    };

    function setDuplicateAlert(message) {
        if (!duplicateAlert) {
            return;
        }

        duplicateAlert.textContent = message;
        duplicateAlert.classList.toggle('d-none', message === '');
    }

    function setDuplicateFieldState(hasDuplicate) {
        if (phoneInput) {
            phoneInput.classList.toggle('is-invalid', hasDuplicate);
        }

        if (adUrlInput) {
            adUrlInput.classList.toggle('is-invalid', hasDuplicate);
        }
    }

    async function checkDuplicateLead() {
        if (!phoneInput || !adUrlInput) {
            return false;
        }

        var phoneValue = phoneInput.value.trim();
        var adUrlValue = adUrlInput.value.trim();

        if (phoneValue === '' && adUrlValue === '') {
            duplicateState = { pending: false, duplicate: false, message: '' };
            setDuplicateAlert('');
            setDuplicateFieldState(false);
            return false;
        }

        duplicateState.pending = true;

        try {
            var url = new URL('duplicate-check.php', window.location.href);
            url.searchParams.set('phone_display', phoneValue);
            url.searchParams.set('ad_url', adUrlValue);

            var response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
            });
            var payload = await response.json();

            if (!payload.ok) {
                throw new Error(payload.error || 'Unable to validate duplicate lead.');
            }

            duplicateState = {
                pending: false,
                duplicate: payload.duplicate === true,
                message: payload.duplicate === true ? String(payload.message || 'Duplicate lead detected.') : '',
            };
        } catch (error) {
            duplicateState = {
                pending: false,
                duplicate: false,
                message: error instanceof Error ? error.message : String(error),
            };
        }

        setDuplicateAlert(duplicateState.duplicate ? duplicateState.message : '');
        setDuplicateFieldState(duplicateState.duplicate);

        return duplicateState.duplicate;
    }

    function scheduleDuplicateCheck() {
        if (duplicateCheckTimer) {
            window.clearTimeout(duplicateCheckTimer);
        }

        duplicateCheckTimer = window.setTimeout(function () {
            void checkDuplicateLead();
        }, 250);
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', scheduleDuplicateCheck);
        phoneInput.addEventListener('blur', function () {
            void checkDuplicateLead();
        });
    }

    if (adUrlInput) {
        adUrlInput.addEventListener('input', scheduleDuplicateCheck);
        adUrlInput.addEventListener('blur', function () {
            void checkDuplicateLead();
        });
    }

    if (addLeadForm) {
        addLeadForm.addEventListener('submit', async function (event) {
            var isDuplicate = await checkDuplicateLead();

            if (isDuplicate) {
                event.preventDefault();
                if (duplicateAlert) {
                    duplicateAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
    }

    if (!editLeadModal) {
        return;
    }

    editLeadModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;

        if (!trigger) {
            return;
        }

        document.getElementById('edit_lead_id').value = trigger.getAttribute('data-lead-id') || '0';
        document.getElementById('edit_lead_name').textContent = trigger.getAttribute('data-lead-name') || 'Lead';
        document.getElementById('edit_latest_reply').value = trigger.getAttribute('data-latest-reply') || '';
        document.getElementById('edit_notes').value = trigger.getAttribute('data-notes') || '';
        document.getElementById('edit_status').value = trigger.getAttribute('data-status') || 'contacted';
    });
});
</script>
</body>
</html>