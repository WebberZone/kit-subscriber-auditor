---
title: Architecture
description: Components and data flow in Kit Subscriber Audit.
permalink: /docs/architecture/
---

## Components

The application is intentionally small and framework-free:

```text
public/index.php
        │
        ├── server-rendered views + local JavaScript
        ├── AuditService       local metrics, filters, and revalidation
        ├── SyncService        paginated subscriber sync + stats queue
        ├── ReengagementService tags, broadcasts, and click-back checks
        ├── CleanupService     review-gated unsubscribe jobs
        ├── KitApiClient       HTTPS API transport, throttling, retries
        └── Database           SQLite migrations and prepared queries
```

## Sync flow

1. The web request creates a sync run in SQLite.
2. A detached PHP CLI worker claims the run and records a heartbeat.
3. Subscriber pages are fetched with Kit cursor pagination.
4. Each subscriber is upserted locally.
5. A stats queue is populated only for missing or stale stats, unless a full resync was explicitly selected.
6. The worker processes the queue in bounded batches and records individual failures.
7. The dashboard polls the local run state; it does not need a long-running web request.

This boundary makes a future scheduled worker or alternate storage adapter possible without putting Kit credentials in the browser.

## Data storage

The database contains the subscriber snapshot, raw API responses, settings, sync history, re-engagement cohorts, and cleanup history. `storage/` is created automatically and should remain outside the web root and version control.

## Future adapters

A multi-user hosted deployment would require a separate authentication model, encrypted secret storage, a durable queue, and a storage adapter such as a managed database. The current one-operator worker and SQLite implementation can be self-hosted by an operator who controls the environment, but should not be copied directly into a public multi-user service.
