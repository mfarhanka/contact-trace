<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

$message = '';
$messageType = 'success';
$telegramBotToken = contact_trace_env('TELEGRAM_BOT_TOKEN');
$telegramAllowedChatIds = contact_trace_env('TELEGRAM_ALLOWED_CHAT_IDS');
$publicBaseUrl = contact_trace_env('APP_PUBLIC_URL');

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_feedback(string $message, string $type): never
{
    header('Location: admin.php?' . http_build_query([
        'message' => $message,
        'type' => $type,
    ]));
    exit;
}

function mask_secret(string $value): string
{
    $length = strlen($value);

    if ($length === 0) {
        return 'missing';
    }

    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
}

function current_request_scheme(): string
{
    $forwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    if ($forwardedProto !== '') {
        return strtolower(explode(',', $forwardedProto)[0]);
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    return $https !== '' && $https !== 'off' ? 'https' : 'http';
}

function current_request_host(): string
{
    $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));

    if ($forwardedHost !== '') {
        return trim(explode(',', $forwardedHost)[0]);
    }

    return trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

function suggested_telegram_webhook_url(): string
{
    $configuredBaseUrl = rtrim(contact_trace_env('APP_PUBLIC_URL'), '/');

    if ($configuredBaseUrl !== '') {
        return $configuredBaseUrl . '/telegram-bot.php';
    }

    $scriptPath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin.php')));
    $basePath = rtrim($scriptPath, '/');

    return current_request_scheme() . '://' . current_request_host() . ($basePath === '' ? '' : $basePath) . '/telegram-bot.php';
}

function telegram_webhook_status_summary(array $info): string
{
    $url = trim((string) ($info['url'] ?? ''));
    $pendingUpdates = (int) ($info['pending_update_count'] ?? 0);
    $lastError = trim((string) ($info['last_error_message'] ?? ''));

    $parts = ['Webhook ' . ($url !== '' ? 'set to ' . $url : 'is not set') . '.'];
    $parts[] = 'Pending updates: ' . $pendingUpdates . '.';

    if ($lastError !== '') {
        $parts[] = 'Last error: ' . $lastError . '.';
    }

    return implode(' ', $parts);
}

if (isset($_GET['message'])) {
    $message = trim((string) $_GET['message']);
    $messageType = $_GET['type'] === 'error' ? 'error' : 'success';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_telegram_settings' || $action === 'save_and_register_telegram_webhook') {
        $submittedToken = trim((string) ($_POST['telegram_bot_token'] ?? ''));
        $settings = [
            'TELEGRAM_BOT_TOKEN' => $submittedToken !== '' ? $submittedToken : $telegramBotToken,
            'TELEGRAM_ALLOWED_CHAT_IDS' => trim((string) ($_POST['telegram_allowed_chat_ids'] ?? '')),
            'APP_PUBLIC_URL' => trim((string) ($_POST['app_public_url'] ?? '')),
        ];

        try {
            contact_trace_save_manageable_settings($settings);

            if ($action === 'save_and_register_telegram_webhook') {
                $webhookUrl = trim((string) ($_POST['webhook_url'] ?? ''));
                contact_trace_register_telegram_webhook($settings['TELEGRAM_BOT_TOKEN'], $webhookUrl);

                redirect_with_feedback('Telegram settings saved and webhook registered: ' . $webhookUrl, 'success');
            }
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback('Telegram settings saved.', 'success');
    }

    if ($action === 'check_telegram_webhook') {
        try {
            $webhookInfo = contact_trace_get_telegram_webhook_info($telegramBotToken);
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback(telegram_webhook_status_summary($webhookInfo), 'success');
    }
}

$telegramWebhookUrl = suggested_telegram_webhook_url();
$telegramBotReady = $telegramBotToken !== '';
$isSuggestedWebhookPublic = current_request_scheme() === 'https' && stripos(current_request_host(), 'localhost') === false && current_request_host() !== '127.0.0.1';
$isSuggestedWebhookPublic = $publicBaseUrl !== '' || $isSuggestedWebhookPublic;
$maskedBotToken = mask_secret($telegramBotToken);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Trace Admin</title>
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
        <div class="col-12 col-xl-8">
            <div class="border rounded bg-white shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h1 class="h2 mb-2">Telegram Admin</h1>
                        <p class="text-secondary mb-0">Save Telegram bot settings and register the webhook from a dedicated admin page.</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary">Back to leads</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <?php if ($message !== ''): ?>
                <div class="alert <?= $messageType === 'error' ? 'alert-danger' : 'alert-success' ?>" role="alert">
                    <?= escape($message) ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-3 align-items-lg-start mb-3">
                        <div>
                            <h2 class="h5 mb-1">Bot settings</h2>
                            <p class="text-secondary small mb-0">Admin can save the bot token here and register the public webhook for <strong>telegram-bot.php</strong>.</p>
                        </div>
                        <div class="small text-secondary">
                            <div>Bot token: <?= escape($maskedBotToken) ?></div>
                            <div>Allowed chat IDs: <?= escape($telegramAllowedChatIds !== '' ? $telegramAllowedChatIds : 'not restricted') ?></div>
                        </div>
                    </div>

                    <form method="post" class="row g-3 align-items-end">
                        <div class="col-12 col-lg-6">
                            <label for="telegram_bot_token" class="form-label">Bot token</label>
                            <input
                                id="telegram_bot_token"
                                type="password"
                                name="telegram_bot_token"
                                class="form-control"
                                placeholder="<?= $telegramBotReady ? 'Leave blank to keep current token' : '123456:ABC-DEF...' ?>"
                                autocomplete="off"
                            >
                            <div class="form-text">Leave blank to keep the current token.</div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label for="telegram_allowed_chat_ids" class="form-label">Allowed chat IDs</label>
                            <input
                                id="telegram_allowed_chat_ids"
                                type="text"
                                name="telegram_allowed_chat_ids"
                                class="form-control"
                                value="<?= escape($telegramAllowedChatIds) ?>"
                                placeholder="123456789,-1001234567890"
                            >
                            <div class="form-text">Optional comma-separated chat IDs allowed to use the bot.</div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label for="app_public_url" class="form-label">Public app URL</label>
                            <input
                                id="app_public_url"
                                type="url"
                                name="app_public_url"
                                class="form-control"
                                value="<?= escape($publicBaseUrl) ?>"
                                placeholder="https://your-domain/contact-trace"
                            >
                            <div class="form-text">Used to prefill the webhook URL below.</div>
                        </div>
                        <div class="col-12 col-lg-8">
                            <label for="webhook_url" class="form-label">Webhook URL</label>
                            <input
                                id="webhook_url"
                                type="url"
                                name="webhook_url"
                                class="form-control"
                                value="<?= escape($telegramWebhookUrl) ?>"
                                placeholder="https://your-domain/contact-trace/telegram-bot.php"
                                required
                            >
                            <div class="form-text">
                                Telegram requires a public HTTPS URL. <?= $isSuggestedWebhookPublic ? 'The suggested URL looks public.' : 'The suggested URL is local, so replace it with your public domain or tunnel URL.' ?>
                                <?= $publicBaseUrl === '' ? 'Set the public app URL here to prefill the webhook URL automatically.' : '' ?>
                            </div>
                        </div>
                        <div class="col-6 col-lg-2 d-grid">
                            <button type="submit" name="action" value="save_telegram_settings" class="btn btn-outline-secondary">Save settings</button>
                        </div>
                        <div class="col-6 col-lg-2 d-grid">
                            <button type="submit" name="action" value="save_and_register_telegram_webhook" class="btn btn-outline-primary">Save + register</button>
                        </div>
                        <div class="col-12 col-lg-2 d-grid">
                            <button type="submit" name="action" value="check_telegram_webhook" class="btn btn-outline-secondary" <?= $telegramBotReady ? '' : 'disabled' ?>>Check status</button>
                        </div>
                    </form>

                    <?php if (!$telegramBotReady): ?>
                        <p class="small text-danger mb-0 mt-3">Save a bot token here first, then use Save + register to register the webhook.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
