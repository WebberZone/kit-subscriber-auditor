# Kit Subscriber Audit

A local-first PHP 8.3+ dashboard for auditing and deliberately cleaning a Kit.com subscriber list. Subscriber data and job history are cached in SQLite, and credentials remain server-side.

## Kit API contract verified before implementation

This project uses the current Kit API v4 contract:

- `GET https://api.kit.com/v4/subscribers` with the server-side `X-Kit-Api-Key` header. It uses cursor pagination: request the next page with `after=<end_cursor>`. Kit documents a default page size of 500 and a maximum of 1000; this app requests 1000 for subscriber pages.
- `GET https://api.kit.com/v4/subscribers/{subscriber_id}/stats` returns `sent`, `opened`, `clicked`, `bounced`, `open_rate`, `click_rate`, `last_sent`, `last_opened`, `last_clicked`, `sends_since_last_open`, and `sends_since_last_click`.
- `POST https://api.kit.com/v4/subscribers/{id}/unsubscribe` returns `204 No Content` and moves the subscriber to `cancelled`. Kit retains the subscriber record, history, and tags. Kit does not document a bulk unsubscribe endpoint for API-key authentication, so this app uses individually rate-limited calls.
- Kit documents a limit of 120 requests per rolling 60 seconds for an API key. The client spaces requests by 550ms and retries transient network, `429`, and `5xx` responses with exponential backoff. Because stats are exposed per subscriber, a first sync of 3,703 subscribers requires roughly 34 minutes at the documented API-key limit.

The official documentation pages are [List subscribers](https://developers.kit.com/api-reference/subscribers/list-subscribers), [List stats for a subscriber](https://developers.kit.com/api-reference/subscribers/list-stats-for-a-subscriber), [Unsubscribe subscriber](https://developers.kit.com/api-reference/subscribers/unsubscribe-subscriber), [Pagination](https://developers.kit.com/api-reference/pagination), [Response codes](https://developers.kit.com/api-reference/response-codes), and [Authentication](https://developers.kit.com/api-reference/authentication).

## Local setup

Requirements:

- PHP 8.3 or newer
- PHP extensions: `curl`, `pdo_sqlite`, and `openssl`
- Herd or another PHP server

From this directory:

```sh
cp .env.example .env
```

Set an optional local login in `.env`:

```dotenv
APP_ENV=local
APP_PASSWORD=choose-a-local-password
```

Set `APP_PASSWORD` to protect the dashboard with a local session login. If you leave it empty, the app has no login layer and should only be reachable from your local machine.

Paste a Kit v4 API key into Settings. It is authenticated-encrypted in SQLite using the local key at `storage/.credentials.key`. The key file, database, WAL files, and logs are all ignored by Git. A `KIT_API_KEY` in `.env` is also supported and is never rendered to the client.

The SQLite database is created and migrated automatically on the first request. To run the migration explicitly:

```sh
php bin/migrate.php
```

For Herd, link the `public/` directory explicitly:

```sh
cd public
herd link kit-subscriber-auditor
```

Then open `https://kit-subscriber-auditor.test`. Herd should show SSL enabled for the site; HTTP requests are redirected to HTTPS. If you link the project root instead, the root `.htaccess` forwards requests to `public/index.php` and denies access to application, database, storage, test, and secret files.

Open the resulting `.test` domain and click **Sync Kit now**. The first sync fetches all subscriber states, then starts a detached local PHP worker that fetches stats in batches of up to 50 by default. You can raise this to 100 in Settings. The browser polls SQLite for progress while the worker runs, so Herd request timeouts do not interrupt long batches. No cleanup occurs during sync.

## Cleanup safety

The default removal candidate rule is:

- no open in the last configured number of days
- no click in the last configured number of days
- subscribed more than the configured number of days ago
- at least the configured minimum number of emails sent

The separate **Very cold** view is fixed at no open/click for 365 days and at least 10 emails sent.

Before a real unsubscribe job can start, the app:

1. Revalidates that selected IDs still match the current candidate rule.
2. Shows the selected email addresses and engagement data.
3. Provides a CSV export.
4. Requires an explicit review checkbox and the phrase `UNSUBSCRIBE`.
5. Requires dry-run mode to be disabled before making real Kit calls.
6. Tracks every call as success or failure in SQLite.

Dry-run mode is enabled by default. Kit documents unsubscribe as effectively permanent because it revokes consent; this app does not offer an automatic re-subscribe action.

## Structure

```text
app/
  AuditService.php       SQLite metrics, filtering, sorting, and rule revalidation
  CleanupService.php     explicit cleanup jobs and per-subscriber results
  Config.php             environment-backed configuration
  CredentialStore.php    encrypted local API-key storage
  Database.php           SQLite connection and migrations
  KitApiClient.php       cURL client, API-key auth, throttling, retries, and API errors
  Settings.php           validated local settings
  SyncService.php        paginated subscriber sync and stats queue
  bin/sync-worker.php     detached local sync worker
  bootstrap.php          application startup and secure response headers
  views/                 server-rendered HTML templates
database/migrations/     versioned SQLite schema
public/                  web entry point and local assets
storage/                 SQLite database (ignored by Git)
tests/                   syntax lint and service-level smoke tests
```

The service boundaries keep the data source, audit rules, job orchestration, and presentation separate. That makes it straightforward to add a scheduled worker, a Cloudflare storage adapter, scoring, or analysis over the locally cached dataset later.

## Verification

```sh
composer test
composer lint
```

The test script uses a temporary SQLite database and verifies metric calculation, candidate filtering, and server-side candidate revalidation. No live Kit request is made by the test suite.
