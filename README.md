# Contact Trace

Simple PHP + SQLite tool to track outreach leads from listings like Mudah.

## What it does

- Save owner phone number and ad link together.
- Save an optional Telegram handle for each owner.
- Store optional owner name, service offered, reply text, notes, and status.
- Search later by phone number, Telegram handle, owner name, notes, or ad link.
- Update the latest reply and status when an owner answers you.

## Run it in XAMPP

1. Start Apache in XAMPP.
2. Open `http://localhost/contact-trace/` in your browser.
3. The SQLite database is created automatically in the `data` folder on first load.

## Telegram bot

You can control the same leads database through a Telegram bot webhook.

1. Set these environment variables for Apache or your hosting runtime:
	- `TELEGRAM_BOT_TOKEN`: your bot token from BotFather.
	- `TELEGRAM_ALLOWED_CHAT_IDS`: optional comma-separated chat IDs allowed to use the bot.
	- `APP_PUBLIC_URL`: optional public base URL such as `https://your-domain/contact-trace` used to prefill the webhook URL in the browser.
2. Instead of Apache environment variables, you can also create a local `.env` file in the project root. Start from `.env.example` and fill in your values there.
3. Expose the app on a public HTTPS URL. Telegram cannot call `localhost` directly.
4. Open `admin.php` in the browser and use the Telegram admin page to save the bot token, allowed chat IDs, and public URL into `.env`, then register your webhook automatically.
5. Send commands to your bot:
	- `/search keyword`
	- `/delete 12`
	- `/add 012-3456789 | https://example.com/ad | Ali | @ali_owner | Aircond service | Interested | Call Friday | contacted`

Use `-` for any optional empty field in `/add`.

## Main fields

- `Phone number`: the owner number you contacted.
- `Telegram`: optional Telegram username or profile shortcut such as `@ownername`.
- `Ads link`: the listing URL.
- `Latest reply`: the last message or response from the owner.
- `Notes`: anything else you want to remember.
- `Status`: use values like contacted, replied, or follow-up.