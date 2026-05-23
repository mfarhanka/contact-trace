<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'contact_trace.php';

session_start();

$pdo = contact_trace_get_pdo();

$message = '';
$messageType = 'success';

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

function login_admin_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $user['id'];
}

function logout_admin_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function current_admin_user(PDO $pdo): ?array
{
    $userId = (int) ($_SESSION['admin_user_id'] ?? 0);

    if ($userId < 1) {
        return null;
    }

    $user = contact_trace_find_admin_user_by_id($pdo, $userId);

    if ($user === null) {
        unset($_SESSION['admin_user_id']);
        return null;
    }

    return $user;
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

function suggested_whatsapp_dashboard_url(): string
{
    $bridgeUrl = rtrim(contact_trace_env('WHATSAPP_BRIDGE_URL'), '/');

    if ($bridgeUrl === '') {
        return '';
    }

    $token = contact_trace_env('WHATSAPP_BRIDGE_TOKEN');

    return $token !== '' ? $bridgeUrl . '/?token=' . rawurlencode($token) : $bridgeUrl . '/';
}

function whatsapp_bridge_status_summary(array $info): string
{
    $state = trim((string) ($info['state'] ?? 'unknown'));
    $clientId = trim((string) ($info['clientId'] ?? ''));
    $lastError = trim((string) ($info['lastError'] ?? ''));
    $parts = ['Bridge state: ' . ($state !== '' ? $state : 'unknown') . '.'];

    if (($info['connected'] ?? false) === true) {
        $parts[] = 'WhatsApp is connected.';
    } elseif (($info['qrAvailable'] ?? false) === true) {
        $parts[] = 'QR is ready to scan.';
    }

    if ($clientId !== '') {
        $parts[] = 'Connected account: ' . $clientId . '.';
    }

    if ($lastError !== '') {
        $parts[] = 'Last error: ' . $lastError . '.';
    }

    return implode(' ', $parts);
}

if (isset($_GET['message'])) {
    $message = trim((string) $_GET['message']);
    $messageType = $_GET['type'] === 'error' ? 'error' : 'success';
}

$adminUserCount = contact_trace_count_admin_users($pdo);
$currentAdminUser = current_admin_user($pdo);
$isAuthenticated = $currentAdminUser !== null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        logout_admin_user();
        redirect_with_feedback('Logged out.', 'success');
    }

    if ($adminUserCount === 0) {
        if ($action !== 'setup_admin_user') {
            redirect_with_feedback('Create the first admin account to secure this page.', 'error');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirmPassword) {
            redirect_with_feedback('Passwords do not match.', 'error');
        }

        try {
            $userId = contact_trace_create_admin_user($pdo, $username, $password);
            $createdUser = contact_trace_find_admin_user_by_id($pdo, $userId);

            if ($createdUser === null) {
                throw new RuntimeException('Admin user was created but could not be loaded.');
            }

            login_admin_user($createdUser);
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback('Admin account created. You are now logged in.', 'success');
    }

    if (!$isAuthenticated) {
        if ($action !== 'login_admin') {
            redirect_with_feedback('Please log in first.', 'error');
        }

        $user = contact_trace_verify_admin_credentials(
            $pdo,
            trim((string) ($_POST['username'] ?? '')),
            (string) ($_POST['password'] ?? '')
        );

        if ($user === null) {
            redirect_with_feedback('Invalid username or password.', 'error');
        }

        login_admin_user($user);
        redirect_with_feedback('Logged in successfully.', 'success');
    }

    if ($action === 'create_admin_user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirmPassword) {
            redirect_with_feedback('New admin passwords do not match.', 'error');
        }

        try {
            contact_trace_create_admin_user($pdo, $username, $password);
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback('New admin user added.', 'success');
    }

    $currentSettings = contact_trace_current_manageable_settings();
    $telegramBotToken = $currentSettings['TELEGRAM_BOT_TOKEN'];

    if ($action === 'save_telegram_settings' || $action === 'save_and_register_telegram_webhook') {
        $submittedToken = trim((string) ($_POST['telegram_bot_token'] ?? ''));
        $settings = array_merge($currentSettings, [
            'TELEGRAM_BOT_TOKEN' => $submittedToken !== '' ? $submittedToken : $telegramBotToken,
            'TELEGRAM_ALLOWED_CHAT_IDS' => trim((string) ($_POST['telegram_allowed_chat_ids'] ?? '')),
            'APP_PUBLIC_URL' => trim((string) ($_POST['app_public_url'] ?? '')),
        ]);

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

    if ($action === 'save_whatsapp_settings') {
        $submittedToken = trim((string) ($_POST['whatsapp_bridge_token'] ?? ''));
        $settings = array_merge($currentSettings, [
            'WHATSAPP_BRIDGE_URL' => trim((string) ($_POST['whatsapp_bridge_url'] ?? '')),
            'WHATSAPP_BRIDGE_TOKEN' => $submittedToken !== '' ? $submittedToken : $currentSettings['WHATSAPP_BRIDGE_TOKEN'],
            'WHATSAPP_AUTO_MESSAGE_TEMPLATE' => trim((string) ($_POST['whatsapp_auto_message_template'] ?? '')),
        ]);

        try {
            contact_trace_save_manageable_settings($settings);
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback('WhatsApp settings saved.', 'success');
    }

    if ($action === 'check_telegram_webhook') {
        try {
            $webhookInfo = contact_trace_get_telegram_webhook_info($telegramBotToken);
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback(telegram_webhook_status_summary($webhookInfo), 'success');
    }

    if ($action === 'check_whatsapp_bridge') {
        try {
            $bridgeInfo = contact_trace_get_whatsapp_bridge_status();
        } catch (Throwable $exception) {
            redirect_with_feedback($exception->getMessage(), 'error');
        }

        redirect_with_feedback(whatsapp_bridge_status_summary($bridgeInfo), 'success');
    }
}

$telegramBotToken = contact_trace_env('TELEGRAM_BOT_TOKEN');
$telegramAllowedChatIds = contact_trace_env('TELEGRAM_ALLOWED_CHAT_IDS');
$publicBaseUrl = contact_trace_env('APP_PUBLIC_URL');
$whatsAppBridgeUrl = contact_trace_env('WHATSAPP_BRIDGE_URL');
$whatsAppBridgeToken = contact_trace_env('WHATSAPP_BRIDGE_TOKEN');
$whatsAppAutoMessageTemplate = contact_trace_env('WHATSAPP_AUTO_MESSAGE_TEMPLATE');
$telegramWebhookUrl = suggested_telegram_webhook_url();
$whatsAppDashboardUrl = suggested_whatsapp_dashboard_url();
$telegramBotReady = $telegramBotToken !== '';
$whatsAppBridgeReady = $whatsAppBridgeUrl !== '' && $whatsAppBridgeToken !== '';
$isSuggestedWebhookPublic = current_request_scheme() === 'https' && stripos(current_request_host(), 'localhost') === false && current_request_host() !== '127.0.0.1';
$isSuggestedWebhookPublic = $publicBaseUrl !== '' || $isSuggestedWebhookPublic;
$maskedBotToken = mask_secret($telegramBotToken);
$maskedWhatsAppBridgeToken = mask_secret($whatsAppBridgeToken);
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
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h1 class="h2 mb-2">Messaging Admin</h1>
                        <p class="text-secondary mb-0">
                            <?php if ($adminUserCount === 0): ?>
                                Create the first admin account to lock this page before managing Telegram and WhatsApp settings.
                            <?php elseif (!$isAuthenticated): ?>
                                Sign in with an admin username and password to manage Telegram and WhatsApp settings.
                            <?php else: ?>
                                Signed in as <strong><?= escape((string) $currentAdminUser['username']) ?></strong>. Manage messaging settings and create more admin users here.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-secondary">Back to leads</a>
                        <?php if ($isAuthenticated): ?>
                            <form method="post" class="d-inline">
                                <button type="submit" name="action" value="logout" class="btn btn-outline-danger">Logout</button>
                            </form>
                        <?php endif; ?>
                    </div>
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

            <?php if ($adminUserCount === 0): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-1">Create first admin</h2>
                        <p class="text-secondary small mb-4">This one-time setup protects admin.php with a username and password stored securely in the app database.</p>

                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="setup_admin_user">

                            <div class="col-12 col-md-6">
                                <label for="setup_username" class="form-label">Username</label>
                                <input id="setup_username" type="text" name="username" class="form-control" placeholder="admin" autocomplete="username" required>
                                <div class="form-text">Use 3 to 32 lowercase letters, numbers, dot, dash, or underscore.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="setup_password" class="form-label">Password</label>
                                <input id="setup_password" type="password" name="password" class="form-control" autocomplete="new-password" required>
                                <div class="form-text">Minimum 8 characters.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="setup_confirm_password" class="form-label">Confirm password</label>
                                <input id="setup_confirm_password" type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                            </div>
                            <div class="col-12 d-grid d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">Create admin account</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif (!$isAuthenticated): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-1">Admin login</h2>
                        <p class="text-secondary small mb-4">Only authenticated admin users can access messaging settings.</p>

                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="login_admin">

                            <div class="col-12 col-md-6">
                                <label for="login_username" class="form-label">Username</label>
                                <input id="login_username" type="text" name="username" class="form-control" autocomplete="username" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="login_password" class="form-label">Password</label>
                                <input id="login_password" type="password" name="password" class="form-control" autocomplete="current-password" required>
                            </div>
                            <div class="col-12 d-grid d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">Log in</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-3 align-items-lg-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">Add admin user</h2>
                                <p class="text-secondary small mb-0">Create another username and password so someone else can manage the admin page too.</p>
                            </div>
                            <div class="small text-secondary">
                                <div>Existing admins: <?= $adminUserCount ?></div>
                            </div>
                        </div>

                        <form method="post" class="row g-3 align-items-end">
                            <input type="hidden" name="action" value="create_admin_user">

                            <div class="col-12 col-lg-4">
                                <label for="new_admin_username" class="form-label">Username</label>
                                <input id="new_admin_username" type="text" name="username" class="form-control" autocomplete="off" required>
                            </div>
                            <div class="col-12 col-lg-3">
                                <label for="new_admin_password" class="form-label">Password</label>
                                <input id="new_admin_password" type="password" name="password" class="form-control" autocomplete="new-password" required>
                            </div>
                            <div class="col-12 col-lg-3">
                                <label for="new_admin_confirm_password" class="form-label">Confirm password</label>
                                <input id="new_admin_confirm_password" type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                            </div>
                            <div class="col-12 col-lg-2 d-grid">
                                <button type="submit" class="btn btn-outline-primary">Add user</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-3 align-items-lg-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">WhatsApp bridge</h2>
                                <p class="text-secondary small mb-0">Configure the local QR bridge that keeps a WhatsApp Web session and sends the first message automatically after Telegram adds a lead.</p>
                            </div>
                            <div class="small text-secondary">
                                <div>Bridge URL: <?= escape($whatsAppBridgeUrl !== '' ? $whatsAppBridgeUrl : 'missing') ?></div>
                                <div>Bridge token: <?= escape($maskedWhatsAppBridgeToken) ?></div>
                            </div>
                        </div>

                        <form method="post" class="row g-3 align-items-end">
                            <div class="col-12 col-lg-6">
                                <label for="whatsapp_bridge_url" class="form-label">Bridge URL</label>
                                <input
                                    id="whatsapp_bridge_url"
                                    type="url"
                                    name="whatsapp_bridge_url"
                                    class="form-control"
                                    value="<?= escape($whatsAppBridgeUrl) ?>"
                                    placeholder="http://127.0.0.1:3001"
                                >
                                <div class="form-text">This local Node.js service serves the QR page and sends messages for WhatsApp Web.</div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label for="whatsapp_bridge_token" class="form-label">Bridge token</label>
                                <input
                                    id="whatsapp_bridge_token"
                                    type="password"
                                    name="whatsapp_bridge_token"
                                    class="form-control"
                                    placeholder="<?= $whatsAppBridgeReady ? 'Leave blank to keep current token' : 'shared-secret-token' ?>"
                                    autocomplete="off"
                                >
                                <div class="form-text">Leave blank to keep the current token. PHP sends it on every bridge request.</div>
                            </div>
                            <div class="col-12">
                                <label for="whatsapp_auto_message_template" class="form-label">Auto message template</label>
                                <textarea
                                    id="whatsapp_auto_message_template"
                                    name="whatsapp_auto_message_template"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Hi {{owner_name}}, I saw your ad {{ad_url}}."
                                ><?= escape($whatsAppAutoMessageTemplate) ?></textarea>
                                <div class="form-text">Available placeholders: {{owner_name}}, {{phone}}, {{ad_url}}, {{service_offer}}, {{latest_reply}}, {{notes}}, {{status}}.</div>
                            </div>
                            <div class="col-6 col-lg-3 d-grid">
                                <button type="submit" name="action" value="save_whatsapp_settings" class="btn btn-outline-secondary">Save settings</button>
                            </div>
                            <div class="col-6 col-lg-3 d-grid">
                                <button type="submit" name="action" value="check_whatsapp_bridge" class="btn btn-outline-primary" <?= $whatsAppBridgeReady ? '' : 'disabled' ?>>Check status</button>
                            </div>
                            <div class="col-12 col-lg-3 d-grid">
                                <?php if ($whatsAppDashboardUrl !== ''): ?>
                                    <a href="<?= escape($whatsAppDashboardUrl) ?>" target="_blank" rel="noreferrer" class="btn btn-outline-secondary">Open QR dashboard</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary" disabled>Open QR dashboard</button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if (!$whatsAppBridgeReady): ?>
                            <p class="small text-danger mb-0 mt-3">Save the bridge URL and token first, then open the QR dashboard to connect WhatsApp Web.</p>
                        <?php elseif ($whatsAppAutoMessageTemplate === ''): ?>
                            <p class="small text-danger mb-0 mt-3">Set an auto message template if you want Telegram adds to send a WhatsApp message automatically.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-lg-between gap-3 align-items-lg-start mb-3">
                            <div>
                                <h2 class="h5 mb-1">Telegram bot settings</h2>
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
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
