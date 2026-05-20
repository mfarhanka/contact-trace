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

## Main fields

- `Phone number`: the owner number you contacted.
- `Telegram`: optional Telegram username or profile shortcut such as `@ownername`.
- `Ads link`: the listing URL.
- `Latest reply`: the last message or response from the owner.
- `Notes`: anything else you want to remember.
- `Status`: use values like contacted, replied, or follow-up.