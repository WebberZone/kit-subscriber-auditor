---
title: Kit API contract
description: Kit v4 endpoints used by Kit Subscriber Audit.
permalink: /docs/kit-api/
---

The application uses Kit API v4 with the server-side `X-Kit-Api-Key` header. Read the current Kit documentation before changing these assumptions.

## Endpoints used

- `GET /v4/subscribers` — cursor-paginated subscriber list; the app requests up to 1,000 records per page.
- `GET /v4/subscribers/{id}/stats` — engagement statistics, including sent, opened, clicked, rates, last activity, and sends since engagement.
- `GET /v4/tags` — available tags.
- `POST /v4/tags` — create or resolve a tag by name.
- `POST /v4/tags/{tag_id}/subscribers/{subscriber_id}` — add one subscriber to a tag.
- `GET /v4/tags/{tag_id}/subscribers?status=all` — refresh local state for members of the configured tag.
- `GET /v4/broadcasts?status=completed&slim=true` — list completed broadcasts for the re-engagement workflow.
- `GET /v4/broadcasts/{id}` — validate the selected broadcast and its send time.
- `POST /v4/subscribers/{id}/unsubscribe` — move a subscriber to Kit's cancelled state.

## Pagination

List responses use Kit's cursor pagination. The next request sends the previous response's `pagination.end_cursor` as `after` until `has_next_page` is false. The client rejects a missing or repeated cursor rather than looping indefinitely.

## Rate limits and retries

The client spaces API-key requests, respects `Retry-After` for `429` responses, and retries bounded, safe reads with exponential backoff. It does not automatically repeat an unsubscribe after an ambiguous network or server error. Failed items remain visible for review.

Subscriber stats are individual endpoints in the Kit contract, so the worker uses a local queue and bounded batches for progress and recovery; a batch does not turn them into one API request.

See Kit's [authentication](https://developers.kit.com/api-reference/authentication), [pagination](https://developers.kit.com/api-reference/pagination), [list subscribers](https://developers.kit.com/api-reference/subscribers/list-subscribers), [subscriber stats](https://developers.kit.com/api-reference/subscribers/list-stats-for-a-subscriber), and [unsubscribe](https://developers.kit.com/api-reference/subscribers/unsubscribe-subscriber) documentation.
