# Contributing

Thanks for helping improve Kit Subscriber Audit.

## Development setup

1. Install PHP 8.3 or newer with `curl`, `openssl`, `pdo_sqlite`, and
   `sodium`.
2. Copy `.env.example` to `.env` and set a long random `APP_PASSWORD`.
3. Configure a test Kit API key only if you need live API integration. Never
   use real subscriber data in fixtures, screenshots, or pull requests.
4. Run `composer test` and `composer lint` before opening a pull request.

## Pull requests

Keep changes small and explain any changes to the Kit API contract, database
migrations, security model, or destructive-action workflow. Add or update
tests for behavior changes. Do not add dependencies without documenting why
they are needed and how they are maintained.

Security-sensitive changes should also update `SECURITY.md` and the relevant
documentation page.
