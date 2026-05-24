# Contact Trace

Simple PHP + SQLite tool to track outreach leads from listings like Mudah.

## What it does

- Save owner phone number and ad link together.
- Store optional owner name, service offered, reply text, notes, and status.
- Search later by phone number, owner name, notes, or ad link.
- Update the latest reply and status when an owner answers you.

## Run it in XAMPP

1. Start Apache in XAMPP.
2. Open `http://localhost/contact-trace/` in your browser.
3. The SQLite database is created automatically in the `data` folder on first load.

## Admin login

1. Open `http://localhost/contact-trace/admin.php`.
2. On the first visit, create the first admin username and password to secure the page.
3. On later visits, log in with that admin account before changing Telegram or WhatsApp settings.
4. After logging in, use the same admin page to create another admin user when you want to share access.

## Telegram bot

You can control the same leads database through a Telegram bot running in local polling mode.

1. Set these environment variables for Apache or your local runtime:
	- `TELEGRAM_BOT_TOKEN`: your bot token from BotFather.
	- `TELEGRAM_ALLOWED_CHAT_IDS`: optional comma-separated chat IDs allowed to use the bot. These same chat IDs also receive alerts when a lead reply or status is updated from the web app.
2. Instead of Apache environment variables, you can also create a local `.env` file in the project root. Start from `.env.example` and fill in your values there.
3. Open `admin.php` in the browser and use the Telegram admin page to save the bot token and allowed chat IDs into `.env`.
4. Start the local polling worker from the project folder:
	- `C:\xampp\php\php.exe telegram-poll.php`
	- Optional one-pass check: `C:\xampp\php\php.exe telegram-poll.php --once`
	- Optional fresh start: `C:\xampp\php\php.exe telegram-poll.php --drop-pending`
5. Keep that command running on the local machine where the project lives.
6. Send commands to your bot:
	- `/search keyword`
	- `/delete 12`
	- `/add` to answer one field at a time
	- `/add https://example.com/ad | 012-3456789 | Ali | @ali_owner | Aircond service | Interested | Call Friday | contacted`
	- `/cancel` to stop an in-progress guided add

Use `-` or `/skip` for any optional empty field in `/add`.

## WhatsApp bridge

The app can send WhatsApp messages automatically right after a lead is added through Telegram, and it can also detect inbound WhatsApp replies, save them into the lead, switch the lead status to `replied`, and notify Telegram. This uses a small local Node.js bridge that stays logged in to WhatsApp Web.

1. Set these values in `.env` or save them from `admin.php`:
	- `WHATSAPP_BRIDGE_URL`: default `http://127.0.0.1:3001`
	- `WHATSAPP_BRIDGE_TOKEN`: any long random secret shared between PHP and the bridge
	- `WHATSAPP_AUTO_MESSAGE_TEMPLATE`: one or more messages sent after Telegram saves a lead
	- `WHATSAPP_INBOUND_URL`: optional override for the local callback endpoint. Defaults to `http://127.0.0.1/contact-trace/whatsapp-inbound.php`
2. Install and start the bridge:
	- `cd whatsapp-bridge`
	- `npm install`
	- `npm start`
3. Open `admin.php`, save the bridge URL, token, and auto message template(s). Separate each message with a line that only contains `---`.
4. Click `Open QR dashboard` in the admin page and scan the QR code using WhatsApp on your phone.
5. Use `/add` in Telegram. After the lead is saved, the bot will attempt to send each WhatsApp template to that lead automatically, waiting 5 seconds between messages.
6. Keep the bridge running. When someone replies in WhatsApp, the bridge forwards the message to Contact Trace, which updates the lead `latest_reply`, changes the status to `replied`, and sends a Telegram alert to the configured chat IDs.

Template placeholders:

- `{{owner_name}}`
- `{{phone}}`
- `{{ad_url}}`
- `{{service_offer}}`
- `{{latest_reply}}`
- `{{notes}}`
- `{{status}}`

Optional bridge runtime settings:

- `WHATSAPP_BRIDGE_HOST`: defaults to `127.0.0.1`
- `WHATSAPP_BRIDGE_PORT`: defaults to `3001`

## Main fields

- `Phone number`: the owner number you contacted.
- `Ads link`: the listing URL.
- `Latest reply`: the last message or response from the owner.
- `Notes`: anything else you want to remember.
- `Status`: use values like contacted, replied, or follow-up.