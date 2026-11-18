# PromptEdit Studio

A small Laravel + Vue + Inertia application for generating images with the fictional [Pixelforge](https://pixelforge.promptedit.dev/docs) API. It tracks estimates, exact costs, credit balances, and webhook delivery state.

## Features

- Prompt, size, quality, and up to four reference-image URLs.
- Live worst-case estimate using Pixelforge's published rates plus a 25% markup.
- Reservation at submit time; exact charge or full refund after completion.
- Live generation status with polling and a scheduled reconciliation fallback.
- Signed, deduplicated webhook handling that tolerates duplicate and stale events.
- Per-user generation history and credit balance, with fake credit purchases.
- Operator report comparing customer charges with Pixelforge usage.

## Local setup

Requirements: PHP 8.4+, Composer, Node 22.12+, and SQLite (or MySQL/Postgres).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
php artisan serve
```

Create a local Pixelforge API key in the [dashboard](https://pixelforge.promptedit.dev/api-keys), then set its webhook URL to a public tunnel URL ending in `/webhooks/pixelforge`. Put the key and its signing secret in `.env`:

```dotenv
PIXELFORGE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=pf_your_local_key
PIXELFORGE_WEBHOOK_SECRET=whsec_your_local_secret
```

Run the test suite with `php artisan test --compact` and format PHP with `vendor/bin/pint --dirty --format agent`.

## Deployment

The app has been deployed to Laravel Cloud. For a fresh Cloud environment:

- PHP 8.4 or 8.5, Node 22 LTS, and an attached MySQL or Postgres database.
- Build command:

  ```bash
  composer install --no-dev --no-interaction --prefer-dist && npm ci --no-audit && npm run build && php artisan optimize
  ```

- Deploy command: `php artisan migrate --force`.
- Enable the Laravel scheduler so `app:reconcile-generations` runs every minute. This recovers state when webhook delivery fails.

Environment variables:

| Variable | Notes |
| --- | --- |
| `APP_NAME` | `PromptEdit Studio` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | The deployed HTTPS URL |
| `APP_KEY` | Generated with `php artisan key:generate` |
| `DB_CONNECTION` | `mysql` or `pgsql`; Cloud injects the remaining `DB_*` values |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `PIXELFORGE_URL` | `https://pixelforge.promptedit.dev/v1` |
| `PIXELFORGE_API_KEY` | The deployed environment's Pixelforge key |
| `PIXELFORGE_WEBHOOK_SECRET` | That key's signing secret |

Use a separate Pixelforge key for each environment so usage and webhook deliveries match that environment's records. Set the deployed key's webhook URL to `https://<your-domain>/webhooks/pixelforge` and send a test event from the Pixelforge dashboard.

Grant an existing user access to `/admin/report` with:

```bash
php artisan app:make-admin user@example.com
```

## Money and state model

Money is stored as integer nanodollars (`1_000_000_000` nanodollars = $1), avoiding floating-point drift. The estimate uses the worst case for text-token variance and reasoning tokens, so the reserved amount covers the final charge. On success, the exact cost is recomputed from the webhook's `usage` and the generation's fixed `rates`, and the reservation is settled once. On failure, the full reservation is refunded. A `settled` flag and row locks prevent double charging.

Webhook events are deduplicated by event ID inside the same transaction that applies them. Generation status moves forward only; a stale `processing` event cannot replace `succeeded` or `failed`. If a webhook is lost, the per-minute reconciliation command polls Pixelforge for pending generations and discovers generations whose POST response was lost.

## Useful prompt keywords

Pixelforge supports keywords for testing: `[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, and `[overloaded]`.
