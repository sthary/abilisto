# Abilisto

Gig-marketplace / waste-collection service platform (Surigao del Sur, PH). Connects clients
with verified workers for bookings, handles escrow-style payments (Xendit), push notifications
(OneSignal + Firebase), SMS OTP (iProgSMS), AI-assisted chat/scanning (Gemini, OpenRouter), and
includes a separate GreenLoop recycling/rewards module and a real-time Node chat server.

## Folder map

| Path | What it is |
|---|---|
| `index.php`, `about.php`, `chat.php`, `support.php`, `settings.php`, `upload.php` | Root-level public pages |
| `auth/` | Login, signup, OTP verification, Google OAuth |
| `client/` | Client-facing dashboard, bookings, payments, chat |
| `worker/` | Worker-facing dashboard, jobs, wallet, verification |
| `admin/` | Admin panel — finance, HR, verifications, reports |
| `api/` | Shared JSON API endpoints used by client/worker pages |
| `greenloop/` | GreenLoop recycling rewards module (separate mini-app, shares the main DB) |
| `junkshop/` | Junkshop partner portal |
| `includes/` | Shared PHP helpers (mailer, SMS, push notifications, navbar, etc.) |
| `config/` | `constants.php` (business rules) and `env.php` (the `.env` loader) |
| `chat-server/` | Standalone Node.js/Socket.IO real-time chat server |
| `db.php` | Central DB connection (mysqli + PDO), reads credentials from `.env` |
| `storage/secrets/` | Non-web-servable directory for credential files (e.g. Firebase service account) — denied by `.htaccess` |

`uploads/`, `private_uploads/`, `logs/`, and any `*.sql` dump are **never committed** — they
contain real user PII and live data. See `.gitignore`.

## Local setup

1. Copy `.env.example` to `.env` and fill in real values (ask whoever has access to the current
   secrets — Xendit, Gemini, Firebase, Google OAuth, OpenRouter, Resend, OneSignal, iProgSMS).
2. Local dev currently points at the **live Hostinger MySQL database** (`DB_HOST`/`DB_NAME`/
   `DB_USER`/`DB_PASS` in `.env`) — there is no local/seed database yet. Be careful: writes from
   local testing hit production data.
3. Place the Firebase service-account JSON at the path set in `FIREBASE_SERVICE_ACCOUNT_PATH`
   (defaults to `storage/secrets/<file>.json`).
4. Serve the PHP app through XAMPP/Apache as usual (`C:\xampp\htdocs\public_html`).
5. For the chat server: `cd chat-server && npm install && npm start` (reads `chat-server/.env`
   or falls back to `DB_HOST`/`DB_PASSWORD`/`PORT` env vars — see `chat-server/server.js`).
6. Set `APP_DEBUG=1` in `.env` locally to see PHP errors (`db.php` gates this — production should
   always run with `APP_DEBUG=0`).

## Secrets

All API keys/passwords live in `.env` (gitignored) and are loaded via `config/env.php`
(`require_once __DIR__ . '/config/env.php'` then `getenv('KEY_NAME')`). Never hardcode a secret
in a PHP file — add it to `.env` / `.env.example` instead.

Several of the secrets currently in use were previously committed to the live server in plaintext
(`key.txt`, a cached OAuth token, hardcoded literals across ~15 files). Treat all of them as
potentially exposed and rotate them in each provider's dashboard when you get a chance — see
`DEPLOY.md` for the rotation checklist.

## Deploy

See `DEPLOY.md`.
