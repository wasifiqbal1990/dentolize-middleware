# Railway Deployment

Use this for a temporary public webhook endpoint that Dentolize can call.

## First Safe Deployment

Create one Railway app service from the GitHub repository and add a Railway Postgres service.

Keep webhook processing safe for the first client test:

```env
WHISPER_ADAPTER_MODE=fake
QUEUE_CONNECTION=sync
```

With this mode, Dentolize webhook events are captured in the middleware database and processed through fake Qoyod storage. It does not automatically create live Qoyod invoices or payments.

## App Service Settings

In the Railway app service:

- Source Repo: `wasifiqbal1990/dentolize-middleware`
- Build Command: leave empty
- Pre-Deploy Command: `chmod +x ./railway/init-app.sh && sh ./railway/init-app.sh`
- Public Networking: generate a Railway domain

Railway auto-detects Laravel and runs it with PHP-FPM/Caddy.

## Required Variables

Set these variables on the app service:

```env
APP_NAME=Whisper
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-railway-domain.up.railway.app
APP_KEY=base64:replace-with-generated-key

LOG_CHANNEL=stderr

DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

WHISPER_ADAPTER_MODE=fake
DENTOLIZE_WEBHOOK_VERIFY_TOKEN=replace-with-shared-secret
DENTOLIZE_GRAPHQL_URL=https://api.dentolize.com/
DENTOLIZE_SESSION_COOKIE=

QOYOD_BASE_URL=https://api.qoyod.com/2.0/
QOYOD_API_KEY=
QOYOD_GENERIC_PRODUCT_ID=1
QOYOD_DEFAULT_INVENTORY_ID=1
QOYOD_DEFAULT_ACCOUNT_ID=1
VAT_RATE=15
```

Generate `APP_KEY` locally with:

```bash
php artisan key:generate --show
```

## Dentolize Webhook URL

Give Dentolize this URL:

```text
https://your-railway-domain.up.railway.app/webhooks/dentolize
```

Required header:

```text
X-Dentolize-Verify-Token: replace-with-shared-secret
```

## Smoke Test

After deployment, run:

```bash
curl -X POST https://your-railway-domain.up.railway.app/webhooks/dentolize \
  -H 'Content-Type: application/json' \
  -H 'X-Dentolize-Verify-Token: replace-with-shared-secret' \
  -d '{"event_id":"railway-smoke-1","event_type":"New Patient","data":{"id":"patient-railway-1","firstName":"Wasif","lastName":"Smoke","phoneNumber":"0500000000"}}'
```

Expected response:

```json
{"status":"received","inbox_id":1}
```
