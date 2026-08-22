# Lenco payments — implementation plan

How the Unity Savings Group system moves real money in and out through
[Lenco](https://lenco-api.readme.io/v2.0/reference/introduction) (BroadPay's Zambian
gateway): mobile money and card **in**, bank account and mobile money **out**.

Nothing in this document changes what the ledgers mean. Lenco is a way of moving
cash to and from the group's account; the ledgers stay the group's book of record and
every rule already written — K500 increments, the September cap, the trading-day
posting order, the two-signature payout, immutable entries — applies unchanged. The
gateway sits *beside* the ledgers, never inside them.

---

## 0. What the API actually gives us

Verified against the v2 docs (August 2026). These facts drive most decisions below.

| Thing | Detail |
| --- | --- |
| Base URL | `https://api.lenco.co/access/v2` (sandbox issued separately by Lenco support) |
| Auth | `Authorization: Bearer <API_TOKEN>`, HTTPS only. Token obtained from support@lenco.co |
| Envelope | `{ status: bool, message: string, data: object|array, meta?: {...} }` — **check `data`, not just HTTP 200**; a 200 is returned even for a failed charge |
| Amounts | **Decimal numbers, not minor units** — `500.00`, `13.75` |
| Currency | `ZMW` |
| Reference | Client-supplied, unique, case-sensitive, only `-` `.` `_` and alphanumerics. Error code `04` = invalid/duplicate reference |
| Money in | `POST /collections/mobile-money`, `POST /collections/card`, plus the hosted popup widget `https://pay.lenco.co/js/v1/inline.js` |
| Money out | `POST /transfers/bank-account`, `POST /transfers/mobile-money` (Zambia + Malawi), `POST /transfers/account` |
| Recipients | `POST /transfer-recipients/bank-account` · `/mobile-money` → reusable `transferRecipientId` |
| Verify a destination | `POST /resolve/bank-account`, `POST /resolve/mobile-money` → returns the **account holder's name** |
| Requery | `GET /collections/status/:reference`, `GET /transfers/status/:reference` |
| Balance | `GET /accounts/:id/balance` |
| Settlements | `GET /settlements` — collections land in the Lenco account, then settle |
| Webhooks | `X-Lenco-Signature` = HMAC-SHA512 of the **raw body**, keyed by `hash('sha256', $apiToken)`. Retried every 30 min for 24 h until a 2xx |
| Events | `collection.successful` · `collection.failed` · `collection.settled` · `transfer.successful` · `transfer.failed` · `transaction.credit` · `transaction.debit` |
| Operators (ZM) | `airtel`, `mtn`, `zamtel` |
| Fees | `bearer: merchant | customer` per transaction; transfers out carry a per-transfer fee |

Three findings that shape the design:

1. **`POST /collections/card` requires a PCI DSS certificate.** The group will not have
   one. Cards must therefore go through Lenco's hosted popup widget, which keeps card
   data entirely out of our servers and out of PCI scope. We never build the card API
   path.
2. **Mobile money collection is asynchronous and offline-authorised.** The response
   comes back `pay-offline`; the member then approves the push prompt on their handset.
   Nothing can be posted to a ledger at request time. (v2 documents no OTP-submission
   endpoint, so none is built — `otp-required` is mapped to the same waiting state as
   `pay-offline`, and if the group's account ever requires it, it surfaces there.)
3. **Webhooks are not guaranteed.** Lenco's own docs tell you to run a re-query
   service. Every intent therefore needs a poller, and every ledger post must be
   idempotent against a webhook and a poll arriving for the same payment.

---

## 1. Answers to the two questions asked

**Can a member choose whether they are paid by bank or by mobile money?**
Yes. Each member keeps one or more **payout destinations** — a bank account
(bank + account number) or a mobile money wallet (phone + `airtel`/`mtn`/`zamtel`) —
managed at `/my/settings`, with one marked default. Every destination is verified
against Lenco's `/resolve/*` endpoint before it can be used, and the resolved account
name is checked against the member's `full_name`. Whatever is default at the moment of
execution is what the share-out, loan disbursement or grant is sent to, and the
committee sees the destination and its resolved name on the two-signature confirm
dialog before they sign.

**Can members pay in by card as well as mobile money?**
Yes, both. `/my` gets a "Pay now" flow that opens the Lenco popup with
`channels: ["card", "mobile-money"]`, so a member with a Visa/Mastercard and a member
with an Airtel wallet use the same button. Separately, the treasurer gets a
"Request payment" action on the trading console that fires a direct
`/collections/mobile-money` push to the member's phone — the flow that works for a
member who is on a phone call rather than in front of a browser.

---

## 2. Where it lives

A new domain module, following the seam already established by
`App\Domain\Notifications\Sms\SmsGateway`: an interface the application talks to, a
Lenco implementation, and a null implementation bound by default so the whole path is
exercisable before the group has an account.

```
app/Domain/Payments/
    PaymentGateway.php            interface — the only thing the app talks to
    Lenco/
        LencoClient.php           HTTP: auth, envelope unwrapping, error codes, retries
        LencoGateway.php          implements PaymentGateway
        LencoAmount.php           ngwee <-> "500.00", integer-only, no floats
        LencoReference.php        builds and parses our client references
        LencoSignature.php        webhook HMAC verification
        LencoOperator.php         phone prefix -> airtel|mtn|zamtel
        LencoException.php        typed errors off errorCode 01..07
    NullPaymentGateway.php        default binding: logs, never calls out
    CollectionRequest.php         value object (amount, member, purpose, channel)
    TransferRequest.php           value object (amount, destination, narration)
    PaymentResult.php             normalised status + provider references + fee
    PaymentIntentService.php      creates/advances intents; the state machine
    PaymentPoster.php             the ONLY thing that turns a settled payment into a ledger entry
    PayoutDestinationService.php  resolve, name-match, verify, set default
    Reconciler.php                daily ledger-vs-Lenco comparison
```

`app/Http/Controllers/Webhooks/LencoWebhookController.php` (unauthenticated, CSRF-exempt),
`app/Jobs/Payments/*` for the async half, `app/Enums/Payment*.php` for the enums.

### The one architectural rule

> **A webhook, a poll and a popup callback may only ever mark a `payment_intent`
> settled. Ledger posting happens in one place — `PaymentPoster` — from a queued job,
> inside `MoneyMutator`, guarded by a database-level idempotency key.**

This is what keeps the constitution's rules intact. `PaymentPoster` calls the existing
services (`SavingsLedger::record`, `LoanRepaymentService::record`,
`SocialFundLedger::post`), so an online K750 contribution is refused by
`InvalidSavingsAmountException` exactly as a cash one is — the difference being that
the refusal must be caught and the payment routed to a refund/reconciliation queue
rather than swallowed.

---

## 3. Data model

Five tables. All money integer ngwee, suffixed `_ngwee`, per `.ai/rules/models.md`.
Long table names get hand-named foreign keys per `.ai/rules/migrations.md`.

### `payment_intents`
One row per attempt to move money, in either direction. The spine of the module.

| Column | Notes |
| --- | --- |
| `id`, `cycle_id`, `member_id` | member nullable for group-level movements |
| `direction` | `collection` \| `transfer` |
| `purpose` | `PaymentPurpose` enum — savings contribution, joining fee, loan repayment, social fund contribution, loan disbursement, payout, share-out, funeral grant, unity baby grant, diaspora apportionment |
| `channel` | `mobile_money` \| `card` \| `bank_account` \| `widget` |
| `amount_ngwee`, `fee_ngwee`, `fee_bearer` | fee filled in from the provider response |
| `reference` | our client reference, **unique** |
| `provider_id`, `provider_reference` | Lenco's `id` and `lencoReference` |
| `status` | `PaymentStatus` enum (below) |
| `status_reason` | Lenco's `reasonForFailure`, verbatim |
| `payable_type`, `payable_id` | morph to Loan, Payout, FuneralGrantClaim, CycleMonth… |
| `payout_destination_id` | transfers only |
| `initiated_at`, `completed_at`, `settled_at`, `posted_at` | `completed_at` is **Lenco's** timestamp, not ours — penalties are computed from it |
| `posted_transaction_type`, `posted_transaction_id` | morph to the ledger row it produced; **unique**, so a replayed webhook cannot post twice |
| `requested_by_member_id`, `approved_by_member_id`, `second_approver_member_id` | outbound only |
| `last_polled_at`, `poll_attempts` | drives the requery job |
| `payload` | json — the provider response as received, for audit |

### `payout_destinations`
Where a member wants their money.

| Column | Notes |
| --- | --- |
| `member_id`, `type` | `bank_account` \| `mobile_money` |
| `bank_id`, `bank_name`, `account_number` | bank only |
| `phone`, `operator` | mobile money only; `airtel`/`mtn`/`zamtel` |
| `resolved_account_name` | what Lenco says the account is called |
| `name_match_score`, `name_match_confirmed_by_member_id` | see §8 |
| `lenco_transfer_recipient_id` | cached from `/transfer-recipients/*` |
| `verified_at`, `is_default`, `disabled_at` | |

Unique index on `(member_id, type, account_number, phone)`; a partial/application-level
guarantee of one default per member.

### `lenco_webhook_events`
Raw receipts. `event`, `signature`, `payload` json, `received_at`, `processed_at`,
`error`. Unique on the provider event/transaction id so a redelivery is a no-op.

### `payment_reconciliations`
One row per daily run: date, counts and totals per direction, unmatched items json,
`run_by` (usually the scheduler). Feeds a committee screen.

### Touching existing tables
- `savings_transactions.source`, `loan_transactions`, `social_fund_transactions` — add
  a `payment_intent_id` nullable FK so a ledger row can point back at the money that
  produced it. **No other change**; the ledgers stay append-only.
- `payouts` — add `paid_at`, `payment_intent_id`. `PayoutExecutor` is not touched
  (see §7).
- `App\Enums\TransactionSource` — add `case Gateway = 'gateway';`.

---

## 4. Enums

```php
enum PaymentStatus: string {
    case Draft = 'draft';                 // created, not yet sent
    case Pending = 'pending';             // accepted by Lenco, no outcome
    case AwaitingAuthorization = 'awaiting-authorization'; // pay-offline / otp-required / 3ds
    case Successful = 'successful';
    case Failed = 'failed';
    case Settled = 'settled';             // collections: money reached the account
    case Posted = 'posted';               // our ledger has it
    case NeedsAttention = 'needs-attention'; // succeeded but could not be posted
    case Abandoned = 'abandoned';         // member walked away / timed out
}
```

Plus `PaymentDirection`, `PaymentPurpose`, `PaymentChannel`, `MobileMoneyOperator`,
`PayoutDestinationType`. All mirrored to TypeScript by the existing
`unity:generate-ts-enums`.

`NeedsAttention` is the important one: it is where a payment goes when Lenco says the
money moved but the ledger refused it — a K750 contribution, a September payment over
the cap, a member whose ledgers were frozen by a payout an hour earlier. It must never
be silently retried; it appears on a committee screen with the member, the amount and
the domain exception's own message.

---

## 5. Config

`config/payments.php` (new; do not overload `config/services.php` — this needs more
than credentials):

```php
return [
    'default' => env('PAYMENT_GATEWAY', 'null'),   // 'null' | 'lenco'
    'gateways' => [
        'lenco' => [
            'base_url'    => env('LENCO_BASE_URL', 'https://api.lenco.co/access/v2'),
            'api_token'   => env('LENCO_API_TOKEN'),
            'public_key'  => env('LENCO_PUBLIC_KEY'),   // widget only, safe to share
            'account_id'  => env('LENCO_ACCOUNT_ID'),   // the account transfers debit
            'widget_url'  => env('LENCO_WIDGET_URL', 'https://pay.lenco.co/js/v1/inline.js'),
            'country'     => 'zm',
            'currency'    => 'ZMW',
            'collection_bearer' => env('LENCO_COLLECTION_BEARER', 'customer'),
            'timeout'     => 30,
        ],
    ],
    'reference_prefix' => env('PAYMENT_REFERENCE_PREFIX', 'usg'),
    'collections' => [
        'min_ngwee' => 100,
        'poll' => ['every_minutes' => 5, 'give_up_after_minutes' => 60],
    ],
    'transfers' => [
        'require_verified_destination' => true,
        'balance_headroom_ngwee' => 0,
        'poll' => ['every_minutes' => 15, 'give_up_after_hours' => 24],
    ],
];
```

`LENCO_API_TOKEN` never reaches the browser. `LENCO_PUBLIC_KEY` is shared through
`HandleInertiaRequests::share()` alongside `currentCycle`, since the widget needs it.

Sandbox is a separate token and base URL from Lenco support — the reference prefix
differs per environment (`usg-sbx-…`) so a sandbox reference can never collide with a
live one.

---

## 6. Money in

### 6.1 Reference and amount handling

```php
LencoReference::for($intent);   // "usg-sbx-sav-00412-1"  (kind, intent id, attempt)
LencoAmount::toDecimal(50_000); // "500.00"   — intdiv/modulo, never (float)
LencoAmount::toNgwee('500.00'); // 50000      — string parse, never (float)
```

Unit-tested both ways including `0.05`, `1234567.89` and trailing-zero forms. A retry
never reuses a reference (error 04); it creates a new intent linked to the same
payable.

### 6.2 Member self-service — card or mobile money (`/my`)

1. Member hits **Pay** on `/my/savings`, `/my/loan` or `/my/fund`. Amount is
   pre-filled from what is owed and validated server-side by the same rules the
   ledger enforces, *before* an intent exists — a K750 September contribution is
   refused at the form, not after the money has left.
2. `POST /my/payments` creates a `Draft` intent and returns `{reference, amount,
   publicKey, channels}`.
3. Vue opens the Lenco widget with `channels: ["card", "mobile-money"]`, the member's
   email and phone, and our reference.
4. `onSuccess` → `POST /my/payments/{intent}/verify`, which requeries
   `GET /collections/status/:reference` server-side. **The callback is never trusted**;
   the requery result is.
5. `onConfirmationPending` / `onClose` leave the intent for the poller and webhook.

### 6.3 Treasurer-initiated push (`/app/trading`)

On the trading sheet each expected row gains a **Request payment** action
(`payments.initiate`): `POST /collections/mobile-money` with the member's phone and
operator, `bearer` from config. Status comes back `pay-offline`; the row shows
"waiting for the member to approve" and updates live via the poller. If Lenco returns
`otp-required`, the treasurer is prompted for the code the member reads out, which is
posted to the submit-OTP endpoint.

Operator is derived from the phone prefix (`LencoOperator`) with an override, so the
treasurer is not asked which network a number is on.

### 6.4 What a successful collection does to the ledgers

This is where the existing rules bite, and each purpose behaves differently:

| Purpose | On settlement |
| --- | --- |
| **Savings contribution** | Does **not** post directly. It marks the member's trading entry received (`source: gateway`) and the money posts with everything else when `TradingConcluder::conclude()` runs. `.ai/rules/trading.md` is explicit that concluding is the only thing that posts, and the ordering — missed installments, interest, savings, repayments — is the constitution's. A gateway payment must not jump that queue. A payment that arrives outside the trading window is held `Settled` and attaches to the next session that opens. |
| **Joining fee** | Posts immediately via `SavingsLedger::record(JoiningFee)` and flips `members.joining_fee_paid`. |
| **Loan repayment** | Posts immediately via `LoanRepaymentService::record()`, with `receivedOn` = Lenco's `completedAt`. This matters: a member who paid at 23:50 on the 7th and whose webhook we process on the 8th must not be charged the daily late penalty. |
| **Social fund contribution** | Posts immediately via `SocialFundLedger::post(Contribution)`. |

If the domain service throws, the intent goes `NeedsAttention` with the exception's
message and the committee decides — refund, reclassify as an adjustment, or hold.

---

## 7. Money out

Outbound money is the half that can hurt. Three principles:

1. **Every transfer needs an approved domain decision behind it.** Lenco is the
   plumbing after a loan was approved or a payout signed; it is never itself the
   authorisation.
2. **The ledger posts on confirmed success, not on initiation** — with the deliberate
   exception of payouts, below.
3. **The group's Lenco balance is checked before any batch.** Collections settle into
   the account before they can be transferred out, so a share-out day can find the
   float short.

### 7.1 Loan disbursement

`LoanDisbursementQueue::disburse()` currently posts the disbursement immediately,
because today money is handed across a table. With Lenco it becomes two steps:

- `LoanDisbursementController` creates a `transfer` intent against the loan and calls
  `POST /transfers/mobile-money` or `/transfers/bank-account` for the member's default
  destination. The loan shows **Paying**.
- On `transfer.successful`, `PaymentPoster` calls the existing `disburse()` — which
  re-runs eligibility, generates the schedule and posts the ledger, exactly as now.
- On `transfer.failed`, nothing is posted; the loan returns to `Approved` and keeps
  its queue position, with the failure reason on the record.

This ordering means a failed transfer can never leave a member owing money they never
received, and no reversing entry is needed for a payment that never happened.

The trading console's `confirmDisbursement` keeps its cash path — a group that pays
some members in cash and some by wallet is normal, and the method is recorded on the
intent so the audit trail says which.

### 7.2 Payouts and share-out

`PayoutExecutor` is not modified. `.ai/rules/payouts.md` and `.ai/rules/domain.md`
make it the single irreversible act — two signatures, the freeze, the record — and
threading an async network call through that transaction would be a mistake. Instead:

- `execute()` runs as it does today and produces a `Payout` with `paid_at = null`.
- A separate, retryable **Pay** action (`payments.initiate`) creates the transfer
  intent for that payout to the member's default destination.
- On success, `paid_at` is stamped and the voucher gains the Lenco reference.
- On failure, the payout stands (the member's position *is* settled and their ledgers
  *are* frozen — that is correct), and the committee retries against a corrected
  destination or hands over cash and marks it paid manually.

`ShareOutBatchRunner` gains a payment stage: preflight the Lenco balance against the
batch total via `GET /accounts/:id/balance`, refuse to start if short (as a
`PreflightItem`, matching `ShareOutPreflight`'s existing pattern), then queue one
transfer per payout with per-item status, retry and a live progress screen. Transfers
are sent one at a time with distinct references rather than as one bulk call, so a
single bad destination cannot stall thirty members' money.

### 7.3 Social Fund outflows

Funeral grants, unity baby grants and diaspora apportionments already require a second
committee signature at the ledger (`SocialFundTransactionType::requiresSecondApprover`).
The transfer is attached to the claim after that approval, posts the outflow on
confirmed success, and shows the recipient's resolved name on the confirm dialog. A
funeral grant is often paid to a next of kin rather than the member, so a grant
transfer may target a one-off destination captured on the claim — verified through
`/resolve/*` the same way, but not stored as a member default.

---

## 8. Payout destinations, and the fraud they invite

Changing where money is sent is the highest-value attack on this system: it needs no
ledger tampering at all. Controls, in order of importance:

1. **Name resolution, always.** `/resolve/bank-account` and `/resolve/mobile-money`
   return the account holder's name. It is compared to `members.full_name` (normalised,
   token-based similarity). A mismatch does not block — Zambian accounts legitimately
   carry maiden names, initials, a spouse's wallet — but it is stored, shown in red on
   the confirm dialog, and requires an explicit committee acknowledgement recorded
   against the destination.
2. **A cooling-off period.** A destination added or changed within 48 hours of a payout
   cannot be paid to without a second committee signature, using the existing
   `TwoPersonRule`. This is the control that defeats "compromise the account, change
   the number, trigger the share-out".
3. **Notify out of band.** Adding, changing or defaulting a destination sends an SMS
   and email through `NotificationChannelManager` to the member's *existing* recorded
   contacts — so a member whose account is taken over hears about it.
4. **Show it at the point of signing.** The `dual-approval` `ConfirmDialog` for any
   payout renders the destination, the resolved name and its verification age. The
   people signing are looking at where the money is going, not just at how much.
5. **Everything is activity-logged** through `MoneyMutator`, including verification
   attempts and failed name matches.

Members manage destinations at `/my/settings` alongside their notification channel —
the same policy already guards their contact details, which is the right neighbour for
"where my money goes". Committee members can capture a destination on a member's behalf
(`members.manage`) for the members who will phone it in.

---

## 9. Fees, float and reconciliation

**Collections — settled: the member bears the fee.** `bearer: customer`, set as the
default in `config/payments.php`. If the group bore it, a K500 contribution would land
as K487.50 and the savings ledger — which must show exactly K500 to satisfy the
increment rule — would disagree with the bank forever. The member pays K500 plus the
fee and the group receives the round figure. Paying online is therefore marginally more
expensive than paying cash at the table, and every payment screen says so.

**Transfers.** The fee is unavoidably the group's. It must **not** be netted off a
payout: `.ai/rules/payouts.md` records that the group pays share-out to the exact
ngwee and `NoRounding` is a settled decision. So the fee is recorded on
`payment_intents.fee_ngwee` and reported, and the group decides where it lands in the
books. **This needs a treasurer decision** — the obvious candidates are a new group
expense line or the Social Fund, and neither should be picked without asking.

**Float.** Collections settle into the Lenco account before they can be transferred
out. Disbursement day and share-out day both need the balance checked first, and the
committee needs a "money at Lenco vs money the ledgers say we hold" figure on the
dashboard.

**Reconciliation.** `unity:lenco-reconcile` runs daily: pull `GET /transactions` and
`GET /settlements` for the period, match against posted intents, and write a
`payment_reconciliations` row listing anything on one side and not the other. This is
what catches the payment that succeeded while our webhook endpoint was down and the
poller had given up.

---

## 10. Webhooks and polling

```php
Route::post('webhooks/lenco', LencoWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.lenco');
```

The controller does four things and nothing else: verify the signature over
`$request->getContent()` with `hash_equals`, insert a `lenco_webhook_events` row
(unique on the provider id, so a redelivery is a cheap no-op), dispatch
`ProcessLencoWebhook`, return 200. Lenco retries for 24 hours on any non-2xx, and any
work done inline risks a timeout being read as failure.

`ProcessLencoWebhook` maps the event to an intent by our reference, advances the state
machine, and — for terminal success — dispatches `PostSettledPayment`. Both jobs are
idempotent on `payment_intents.posted_transaction_id`.

`PollPendingPayments` runs on the scheduler: collections every 5 minutes up to an hour,
transfers every 15 minutes up to 24 hours, then `Abandoned` (collections) or
`NeedsAttention` (transfers — a transfer whose outcome is unknown is never quietly
dropped).

**This module requires a real queue driver.** Confirm `QUEUE_CONNECTION` is not `sync`
in production before go-live; a synchronous webhook handler will time out and produce
duplicate deliveries.

---

## 11. UI

**Committee (`/app/payments`)**, gated by new permissions `payments.view`,
`payments.initiate`, `payments.retry`, `payments.reconcile`:
- `DataTable` of intents — member, purpose, direction, channel, amount, status,
  reference — with row actions gated by the `abilities` prop as everywhere else.
- **Needs attention** queue: succeeded but unposted, with the domain refusal spelled
  out and the actions the committee can take.
- Balance and settlement card on `/app` — Lenco balance, unsettled collections,
  today's transfers.
- Reconciliation screen driven by `payment_reconciliations`.
- Trading console: **Request payment** per row; a payment-status column beside the
  received tick.
- Disbursement and payout screens: destination + resolved name on the confirm dialog,
  a **Pay via Lenco** action, retry on failure.

**Member (`/my`)**, mobile-first:
- **Pay** buttons on savings, loan and fund, opening the widget.
- A payment history list with statuses in plain language — "waiting for you to approve
  on your phone" beats `pay-offline`.
- **Payout destination** management in settings, with the resolve step surfaced as
  "we'll check the name on the account" rather than as an API call.

Navigation entries go in the existing data-driven `navigation.ts` with their required
permissions, so the sidebar and the member bottom-nav pick them up automatically.

---

## 12. Testing

Per `.ai/rules` and the project's test-enforcement rule, every step below ships with
its tests.

**Unit (Pest):** `LencoAmount` round-tripping in both directions with awkward values;
`LencoReference` build/parse; `LencoSignature` against a known-good HMAC and a
tampered body; `LencoOperator` prefix mapping; the `PaymentStatus` state machine's
legal transitions.

**Feature (Pest + `Http::fake()`):** every collection and transfer path against
recorded Lenco envelopes, including the `pay-offline`, `otp-required`, `3ds-auth-required`
and each documented `errorCode`; webhook accepted / rejected / replayed; a webhook
arriving before the initiating response has been persisted; a settled savings
collection marking a trading entry rather than posting; a repayment posting with
Lenco's `completedAt` and therefore *not* attracting a late penalty; a settled
collection for a member whose ledgers are frozen landing in `NeedsAttention`; a failed
disbursement transfer leaving no ledger entry and restoring the queue position; a
share-out batch refusing to start on insufficient balance.

**Vitest:** the payment button's channel handling and the destination form's
operator/bank switching.

**Sandbox smoke:** `unity:lenco-smoke` drives the documented sandbox numbers —
`0971111111` (airtel, succeeds), `0975555555` (not enough funds), `0966666666`
(timeout), card `5555 5555 5555 4444` — end to end against the sandbox, so the real
integration is proven before live keys exist.

A `FakePaymentGateway` is bound in tests; `NullPaymentGateway` is the default binding
in `AppServiceProvider` until `PAYMENT_GATEWAY=lenco` is set, so nothing calls out by
accident.

---

## 13. Delivery order

Each phase is shippable and leaves the system working.

| # | Phase | Contents |
| --- | --- | --- |
| 1 | **Foundations** | Enums, migrations, `config/payments.php`, `PaymentGateway` + `NullPaymentGateway`, `LencoAmount`/`LencoReference`/`LencoSignature` with unit tests. Nothing user-visible. |
| 2 | **Client + intents** | `LencoClient`, `LencoGateway`, `PaymentIntentService`, the state machine, `Http::fake()` coverage of every endpoint and error code. |
| 3 | **Webhooks + polling** | Webhook route, controller, event table, `ProcessLencoWebhook`, `PollPendingPayments`, scheduler entries, `PaymentPoster` with its idempotency guard. |
| 4 | **Money in — mobile money** | Treasurer push from the trading console, trading-entry marking, joining fee, social fund contribution, loan repayment. Live-testable in sandbox end to end. |
| 5 | **Money in — card + member self-service** | Widget integration, `/my` pay flows, verify endpoint, member payment history. |
| 6 | **Payout destinations** | `payout_destinations`, resolve + name matching, `/my/settings` UI, committee capture, notifications, cooling-off rule. |
| 7 | **Money out — single transfers** | Loan disbursement, individual payout, social fund grants. Balance preflight. |
| 8 | **Money out — share-out batch** | `ShareOutBatchRunner` payment stage, per-item retry, progress screen. |
| 9 | **Operations** | Reconciliation command + screen, needs-attention queue, dashboard balance card, `unity:lenco-smoke`, `docs/RUNBOOK.md` additions. |

Phases 1–3 are the ones worth getting exactly right; everything after is repetition of
an established pattern.

---

## 14. Decisions

Settled, and built accordingly:

1. **The member bears the collection fee** (§9). `LENCO_COLLECTION_BEARER=customer`.
2. **Online savings go through the trading sheet** (§6.4). A settled savings payment
   marks the member's row received; `TradingConcluder::conclude()` still posts the
   month, in the constitution's order.
3. **Money collected outside the trading window is held, not refused.** It sits at
   `Settled` and the first `unity:poll-payments` run after a session opens puts it on
   the sheet.
4. **Paying by Lenco is optional, permanently.** Cash at the table is still the normal
   path for anybody without a wallet, so every screen carries both — "Ask" beside "Mark
   received" on the trading sheet, "Send" beside "Hand over" in the disbursement queue,
   and a by-hand column on the share-out run.
5. **A member who has not declared cannot pay online.** The sheet is built from
   declarations and there would be no row for the money to land on — the same position
   they would be in turning up with cash. Refused at the form, before any money moves.

Still the group's to answer:

6. **Where transfer fees are recorded.** They are captured on
   `payment_intents.fee_ngwee` and reported on the payments screen, but they are a real
   cost leaving the pool with no home in the current chart of accounts. They are never
   netted off a payout — share-out is paid to the exact ngwee.
7. **Who holds the API token, and where.** It moves money out of the group's account.
   Server environment only, rotated through Lenco support. See `docs/RUNBOOK.md`.

---

## 15. What was built

All nine phases are implemented and tested. The map, for anybody picking this up:

| Piece | Where |
| --- | --- |
| The seam | `app/Domain/Payments/PaymentGateway.php`, `NullPaymentGateway` (default binding) |
| Lenco | `app/Domain/Payments/Lenco/` — client, gateway, amount, reference, signature, operator |
| Intents + state machine | `PaymentIntentService`, `App\Enums\PaymentStatus` |
| The one door to the ledgers | `PaymentPoster` |
| Asking for money | `CollectionInitiator` |
| Sending money | `TransferInitiator`, `ShareOutPaymentRunner` |
| Where members are paid | `PayoutDestinationService`, `AccountNameMatcher` |
| Webhooks | `App\Http\Controllers\Webhooks\LencoWebhookController`, `App\Jobs\Payments\*` |
| Scheduled | `unity:poll-payments` (5 min), `unity:reconcile-payments` (02:30) |
| Sandbox proof | `unity:lenco-smoke` |
| Screens | `/app/payments`, `/app/payments/reconciliation`, `/app/shareout/payments`, `/my/payments`, `/my/destinations` |
