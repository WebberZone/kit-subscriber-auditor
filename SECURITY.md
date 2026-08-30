# Security policy

## Scope

Kit Subscriber Audit is a local-first application for one operator. It stores
subscriber personally identifiable information and a Kit API key on the local
machine. It is not a multi-user application and must not be exposed directly
to the public internet.

The supported deployment model is a local HTTPS site such as Herd. If you put
it behind a reverse proxy, use a private network, require authentication, and
configure `TRUST_PROXY=1` only when that proxy is trusted.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's
private vulnerability reporting for this repository, or contact the
maintainer privately through the email address in `composer.json`.

Include the affected version, deployment model, reproduction steps, impact,
and any proposed mitigation. Do not include real subscriber data or API keys.

## Local security checklist

- Keep `APP_PASSWORD` set to a long random value.
- Use `APP_ALLOW_NO_AUTH=1` only for a strictly loopback-only local site.
- Serve the `public/` directory as the document root.
- Never commit `.env`, `storage/`, SQLite files, logs, or `.credentials.key`.
- Treat the SQLite database as sensitive cleartext local data.
- Revoke and replace the Kit API key if the machine, database, key file, or
  backups may have been exposed.
- Review and export a proposed list before any live unsubscribe operation.

## Security limitations

Credential encryption protects a copied database when the separate local key
file is not also available. It is not a substitute for full-disk encryption,
operating-system permissions, or encrypted backups. Subscriber records and raw
Kit responses are stored locally in SQLite and should be handled as private
data.
