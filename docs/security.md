---
title: Security model
description: Security boundaries and deployment guidance for Kit Subscriber Audit.
permalink: /docs/security/
---

## Intended threat model

Kit Subscriber Audit is designed for one trusted operator in a deployment you control. It is not a hosted SaaS application, an account-sharing tool, or a multi-tenant application. You can run it locally or self-host it elsewhere, but any internet-facing deployment needs its own hardened HTTPS, authentication, and network controls. Subscriber emails, names, engagement history, and raw Kit responses are sensitive personal data.

## Protections in the application

- API keys are never sent to browser JavaScript.
- Keys saved through Settings are authenticated-encrypted with a local key file.
- SQLite, logs, lock files, `.env`, and the credential key are excluded from Git.
- The application sets secure session cookies, CSRF protection, CSP, clickjacking protection, and no-store caching.
- Database access uses prepared statements and allowlisted sort/filter values.
- Kit requests use HTTPS, disable redirects, and use bounded timeouts.
- Cleanup requires revalidation against the current local rules, export/review confirmation, dry-run control, and the literal phrase `UNSUBSCRIBE`.

## Operator responsibilities

Keep `APP_PASSWORD` set to a long random value. `APP_ALLOW_NO_AUTH=1` is an explicit convenience escape hatch for a strictly loopback-only site; never use it for a LAN, tunnel, shared host, or public deployment.

Serve only `public/`. Keep full-disk encryption and secure backups enabled. The SQLite database is cleartext data on the host; encrypting the credentials separately does not encrypt subscriber data. Do not upload the database, `.env`, credential key, or logs to an issue, chat, cloud drive, or public repository.

Use a dedicated Kit API key for this application and revoke it if the machine or its backups may have been exposed. Review Kit's current API permissions and rate limits before changing the integration.

## Reporting problems

Please report security issues privately through GitHub's vulnerability reporting or to the maintainer's private email. Do not include real subscriber data or credentials.
