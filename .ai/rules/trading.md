---
paths:
  - 'app/Domain/Trading/**'
---

# Trading

## Concluding is the only thing that posts, and the order inside it is the constitution's
The trading sheet is a worksheet. `markReceived` / `clearReceipt` touch no ledger; `TradingConcluder::conclude()` posts the whole month inside one `DB::transaction`, and any refusal (e.g. a member marked as repaying a loan they do not hold) rolls back all of it. Never post a row as it is marked — a half-posted trading day is the one outcome the group cannot reconcile by hand.

The order inside `conclude()` is load-bearing: close last month's installments (10% missed-installment penalty) → charge this month's interest → savings deposits → repayments. Interest must be charged before repayments are allocated, or the money lands on principal and quietly cuts the interest owed to the rest of the group.

The one exception is `confirmDisbursement`: money physically leaves the table there, so it posts through `LoanDisbursementQueue::disburse()` immediately and the conclusion does not repeat it.

`scheduled_conclude_date` is copied onto the session when it opens, already weekend-adjusted. Penalty days are computed against that copy so a concluded session cannot be re-dated by a later change to the cycle's weekend policy.

`openFor()` is idempotent and re-syncs expected figures while preserving anything the treasurer already marked — that is what lets a late declaration appear on the sheet without a rebuild.

## Equal amounts on the trading sheet are not member-to-member matching
Asked and answered (2026-08-21): the workbook's Trading sheet sometimes shows two members at the same figure (e.g. both at K30,000). That is a coincidence of the pooled ledger — money goes into one pool and out again, and equal figures are just the same loan amount on both sides. There is no pairing to model: do not build a matching feature into the trading console.
