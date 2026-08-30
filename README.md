# Kit Subscriber Audit

Kit Subscriber Audit is a local-first PHP 8.3+ dashboard for auditing and deliberately cleaning a Kit.com subscriber list. Subscriber data and job history are cached in SQLite, while the Kit API key stays server-side.

This is intentionally designed for one trusted operator on a local HTTPS site. It is not a hosted multi-user application.

## Features

- Cursor-paginated Kit v4 subscriber sync.
- Cached subscriber stats with a configurable freshness window.
- Dashboard metrics for inactivity, opens, clicks, recent subscriptions, and sends since last open.
- Filterable and sortable subscriber table.
- Configurable removal candidates and a separate very-cold view.
- Re-engagement cohorts using a Kit tag, followed by a deliberate click check against a selected broadcast.
- CSV export, dry-run mode, explicit confirmation, and per-subscriber cleanup results.
- Detached local workers with progress, heartbeat, retries, and resumable queues.
- No OAuth integration: the app uses a Kit v4 API key stored in encrypted local SQLite or supplied through `.env`.

## Kit API contract

The app uses these Kit v4 operations:

- `GET /v4/subscribers` with `after` cursor pagination and a requested page size of 1000.
- `GET /v4/subscribers/{subscriber_id}/stats` for subscriber engagement fields.
- `GET /v4/tags` and `POST /v4/tags` for selecting or creating a re-engagement tag.
- `POST /v4/tags/{tag_id}/subscribers/{subscriber_id}` for API-key tag assignment.
- `GET /v4/tags/{tag_id}/subscribers?status=all` for targeted tag-status refreshes.
- `GET /v4/broadcasts?status=completed&slim=true` and stats filtered by `email_sent_after` for re-engagement checks.
- `POST /v4/subscribers/{subscriber_id}/unsubscribe` for explicit individual unsubscribe actions.

Kit's documented API-key allowance is 120 requests per rolling 60 seconds. The client spaces API-key requests, retries safe reads and rate-limit responses, and records ambiguous unsubscribe failures for review. Kit does not document a bulk unsubscribe endpoint for API-key authentication, so cleanup calls are intentionally individual and rate-limited.

See Kit's official [API documentation](https://developers.kit.com/api-reference) for the upstream contract. The project's [API notes](docs/kit-api.md) record the endpoints and operational assumptions used here.

## Local setup

Requirements:

- PHP 8.3 or newer.
- PHP extensions: `curl`, `pdo_sqlite`, and `openssl`.
- Herd or another HTTPS-capable PHP server.

From the repository root:

```sh
cp .env.example .env
chmod 700 storage
chmod 600 .env
```

Generate a local password and put it in `.env`:

```sh
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
```

Use the generated value as `APP_PASSWORD`. The default configuration requires login. For a strictly local, already-protected Herd site, `APP_ALLOW_NO_AUTH=1` can be set deliberately; do not use that setting on a shared or internet-facing host.

Set `KIT_API_KEY` in `.env`, or enter the key after opening Settings. The browser never receives the key. Keys entered in Settings are encrypted at rest using `storage/.credentials.key`, and the database, key file, logs, WAL files, and locks are ignored by Git.

For Herd, link the `public/` directory:

```sh
cd public
herd link kit-subscriber-auditor
```

Open `https://kit-subscriber-auditor.test`. The application rejects plain HTTP by redirecting to HTTPS. If a trusted reverse proxy terminates TLS, set `TRUST_PROXY=1` so the forwarded protocol can be checked.

The SQLite database is created and migrated automatically. To run migrations explicitly:

```sh
php bin/migrate.php
```

## Syncing

The first sync fetches subscriber pages and queues stats for the local worker. Each subscriber stats request is independent, so the worker reports progress and resumes pending items after interruption. A normal sync refreshes the subscriber list but skips stats newer than the configured refresh window, which defaults to 24 hours. Use the force-full option only when every subscriber's stats need to be fetched again.

The batch size controls how many queue items a worker claims and reports at a time; it does not turn the individual Kit stats endpoints into one request. The API client remains rate-limited to Kit's documented allowance.

## Cleanup safety

The default removal candidate rule is configurable and combines inactivity, subscription age, and minimum email volume. The separate very-cold view is fixed at no open or click for 365 days and at least 10 emails sent.

No cleanup happens during a sync. Before a real unsubscribe job can start, the app revalidates the selected active subscribers, shows the proposed list, allows CSV export, requires a review checkbox and the phrase `UNSUBSCRIBE`, and requires dry-run mode to be disabled for that job. Kit retains unsubscribed records and history, but the app does not provide an automatic re-subscribe operation.

The re-engagement flow lets you choose or create a Kit tag, send the broadcast yourself in Kit, then select the completed broadcast in this app. A targeted resync checks whether each current tag member clicked after that broadcast; only the resulting stale list is handed to the same explicit unsubscribe review.

## Development

```sh
composer test
composer lint
```

Tests use a temporary SQLite database and make no live Kit requests. Keep real `.env`, SQLite, credential-key, log, and export files out of commits. See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## Release packaging

The release archive is built from a clean Git tree and uses `git archive`, so ignored local state cannot enter the package:

```sh
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
./build-zip.sh v1.0.0
```

The resulting `build/kit-subscriber-auditor-v1.0.0.zip` contains the application source but excludes the GitHub Pages source, tests, storage, and the release script. GitHub Actions can attach the same archive to a published release.

## Documentation site

The public documentation is built from this repository with GitHub Pages at `https://ajay.social/kit-subscriber-auditor/`. Its Jekyll source is in `index.md`, `docs/`, `_layouts/`, `_includes/`, and `site-assets/`.

## License

MIT. See [LICENSE](LICENSE).
