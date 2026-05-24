<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'telegram-bot.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'timeout::', 'drop-pending']);
$runOnce = array_key_exists('once', $options);
$dropPending = array_key_exists('drop-pending', $options);
$timeout = (int) ($options['timeout'] ?? 25);
$timeout = max(0, min($timeout, 50));
$botToken = trim(contact_trace_env('TELEGRAM_BOT_TOKEN'));

if ($botToken === '') {
    fwrite(STDERR, "Missing TELEGRAM_BOT_TOKEN in .env or environment variables.\n");
    exit(1);
}

try {
    contact_trace_delete_telegram_webhook($botToken, $dropPending);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unable to switch Telegram to polling mode: ' . $exception->getMessage() . "\n");
    exit(1);
}

$modeLabel = $runOnce ? 'single pass' : 'continuous';
fwrite(STDOUT, 'Telegram polling started (' . $modeLabel . ', timeout=' . $timeout . "s). Press Ctrl+C to stop.\n");

$offset = 0;

do {
    try {
        $updates = contact_trace_get_telegram_updates($botToken, $offset, $timeout);

        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $updateId = (int) ($update['update_id'] ?? 0);

            if ($updateId > 0) {
                $offset = $updateId + 1;
            }

            contact_trace_process_telegram_update($update, $botToken);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, '[' . date('c') . '] Telegram polling error: ' . $exception->getMessage() . "\n");
    }
} while (!$runOnce);