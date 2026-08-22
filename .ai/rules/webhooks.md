---
paths:
  - 'app/Http/Controllers/Webhooks/**'
---

# Webhooks

## Verify the raw body, store, queue, return 200 — and nothing else
`X-Lenco-Signature` is HMAC-SHA512 of the RAW request body keyed by `hash('sha256', $apiToken)`. It must be computed over `$request->getContent()` — re-encoding the decoded JSON changes whitespace and key order and produces a different digest.

The controller does four things and no more: verify with `hash_equals`, insert a `lenco_webhook_events` row, dispatch `ProcessLencoWebhook`, return 200. The provider retries every 30 minutes for 24 hours on any non-2xx and will time out on work done inline, reading that as failure and sending again.

`event_key` is `event:providerId`, unique — a redelivery is a cheap no-op, while `collection.successful` followed by `collection.settled` for the same payment are correctly two rows.

The job does not trust the event's figures for anything that decides money: it takes the reference and re-queries the provider, which is the same call the poller makes. One path to be right about, not two. The route is CSRF-exempt in bootstrap/app.php by necessity — the signature is what authenticates it.
