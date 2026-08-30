---
title: Getting started
description: Install and configure Kit Subscriber Audit locally.
permalink: /docs/getting-started/
---

## Requirements

- PHP 8.3 or newer
- PHP extensions: `curl`, `openssl`, `pdo_sqlite`, and `sodium`
- Kit v4 API key
- Herd, Apache, Nginx, or another PHP-capable local server

The application has no runtime Composer dependencies. Composer is used for the test and release commands.

## Install with Herd

Clone the repository, create the ignored environment file, and set a long random password:

```sh
git clone https://github.com/WebberZone/kit-subscriber-auditor.git
cd kit-subscriber-auditor
cp .env.example .env
chmod 600 .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Put the generated value in `APP_PASSWORD` in `.env`. Save a Kit v4 API key through the Settings screen; the local Settings flow encrypts it before writing it to SQLite.

Link only the public directory:

```sh
cd public
herd link kit-subscriber-auditor
```

Open `https://kit-subscriber-auditor.test`. HTTP is rejected or redirected to HTTPS, and the application requires the password from `.env`.

## Other local servers

Configure `public/` as the document root. Do not point a web server at the repository root. If a trusted reverse proxy terminates TLS, set `TRUST_PROXY=1` and ensure it overwrites—not appends to—the forwarded protocol header.

Run migrations explicitly when useful:

```sh
php bin/migrate.php
```

## First sync

1. Sign in.
2. Open Settings and save the Kit v4 API key.
3. Return to Audit and choose **Sync changes**.
4. Leave the page open if you want to watch the progress; the detached local worker continues independently.

Normal syncs refresh the subscriber list and fetch stats only when missing or older than the configured freshness window. Use **Force full resync** only when every subscriber's stats need to be refreshed.

## Deliberate cleanup

The default removal rule is configurable. Syncing never changes subscriber state in Kit. To clean up:

1. Select a filtered cohort.
2. Review the proposed list.
3. Export the CSV if you want a durable record.
4. Run the dry-run first.
5. Disable dry-run only for the reviewed job.
6. Type `UNSUBSCRIBE` to authorize the live operation.

Kit moves unsubscribed records to its `cancelled` state. Treat this as effectively permanent and verify Kit's current re-subscribe rules before acting.
